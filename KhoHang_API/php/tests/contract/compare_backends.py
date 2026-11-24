"""
Differential contract test: replays one scripted session against the FastAPI
baseline and the PHP port, each on its own copy of the same seeded SQLite file,
and compares status codes, selected headers and JSON bodies after normalising
values that legitimately differ (UUIDs, timestamps, JWTs).

Usage (see README in this folder):
  python compare_backends.py --py http://127.0.0.1:9100 --php http://127.0.0.1:9200 \
      --py-ws ws://127.0.0.1:9100/ws/rt --php-ws ws://127.0.0.1:9201/ws/rt

Approved deviations are listed in EXPECTED_DIFF and reported separately.
Exit code 0 = no unexpected difference.
"""
import argparse
import asyncio
import io
import json
import re
import sys

import requests
import websockets

UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")
HEX32 = re.compile(r"(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])")
TS = re.compile(r"\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})?")
JWT = re.compile(r"eyJ[\w-]+\.[\w-]+\.[\w-]+")
MMYY = re.compile(r"_P([NX])_\d{4}_")


def ts_shape(m):
    s = m.group(0)
    frac = "frac" if m.group(1) else "nofrac"
    tz = m.group(2) or "naive"
    sep = "T" if "T" in s else " "
    return f"<ts{sep}{frac}{tz}>"


def norm(v):
    if isinstance(v, dict):
        return {k: norm(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [norm(x) for x in v]
    if isinstance(v, str):
        v = JWT.sub("<jwt>", v)
        v = UUID.sub("<uuid>", v)
        v = HEX32.sub("<hex>", v)
        v = TS.sub(ts_shape, v)
        return v
    if isinstance(v, float) and v.is_integer():
        return v  # keep 1.0 vs 1 distinction visible via type below
    return v


def typed(v):
    """Attach JSON types so 1 vs 1.0 differences are caught."""
    if isinstance(v, dict):
        return {k: typed(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [typed(x) for x in v]
    if isinstance(v, bool) or v is None or isinstance(v, str):
        return v
    if isinstance(v, int):
        return f"int:{v}"
    if isinstance(v, float):
        return f"float:{v}"
    return v


# step name -> reason. These differences are approved changes, not regressions.
EXPECTED_DIFF = {
    "warehouse.update.no_passkey": "approved: PUT /warehouses/{id} now requires X-Passkey",
    "warehouse.delete.wrong_passkey": "approved: DELETE /warehouses/{id} now verifies X-Passkey",
    "otp.reset.resend_within_60s": "fix: FastAPI raised TypeError (naive-aware datetime) -> 500; PHP returns intended 429",
    "login.json_invalid": "JSON parser error text/position differ (Python json vs PHP json_decode)",
    "avatar.badimage": "Pillow error text embeds a Python object address",
    "item.delete": "fix: item with stock history -> 409 instead of IntegrityError 500 (item kept in both)",
    "item.delete.404": "consequence of item.delete (item still exists in both)",
    "warehouse.delete": "consequence of warehouse.delete.wrong_passkey (FastAPI already deleted it)",
    "files.bad_ext": "FastAPI lists allowed extensions in random set order",
    "rt.files.bad_ext": "FastAPI lists allowed extensions in random set order",
}


class Session:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.tokens = {}

    def call(self, method, path, who=None, **kw):
        headers = kw.pop("headers", {})
        if who:
            headers["Authorization"] = f"Bearer {self.tokens[who]}"
        r = requests.request(method, self.base + path, headers=headers, timeout=60, **kw)
        ctype = r.headers.get("content-type", "")
        if "application/json" in ctype:
            try:
                body = r.json()
            except ValueError:
                body = r.text
        elif ctype.startswith("text/"):
            body = r.text
        else:
            body = "<binary>" if r.content else ""
        keep = {k: r.headers.get(k) for k in ("content-disposition", "retry-after") if r.headers.get(k)}
        if ctype and r.status_code != 204:  # Symfony drops Content-Type on 204 (no body)
            keep["content-type"] = ctype.split(";")[0]
        return r.status_code, body, keep


def make_png(w, h):
    import struct, zlib
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    raw = b"".join(b"\x00" + b"\xff\x00\x00" * w for _ in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


def script(s: Session):
    """Yields (step_name, response). Same calls for both backends."""
    c = s.call
    png = make_png(2, 2)

    yield "root", c("GET", "/")
    yield "notfound", c("GET", "/nope")
    yield "method_not_allowed", c("PATCH", "/items")
    for p in ["/items", "/suppliers", "/warehouses", "/warehouses/active", "/company", "/dashboard/stats",
              "/items/alerts", "/items/top-items", "/items/monthly-trend", "/items/category-distribution",
              "/reports/inventory-by-category", "/reports/monthly-trend", "/reports/low-stock-items",
              "/stock/in", "/stock/out", "/stock/transactions", "/api/chatbot/config"]:
        yield f"empty GET {p}", c("GET", p)

    # ---- auth ----
    yield "login.unknown", c("POST", "/auth/login", json={"username_or_email": "ghost", "password": "x"})
    yield "login.badpass", c("POST", "/auth/login", json={"username_or_email": "admin", "password": "wrong"})
    yield "login.unverified", c("POST", "/auth/login", json={"username_or_email": "pending", "password": "secret123"})
    yield "login.422", c("POST", "/auth/login", json={"username_or_email": "admin"})
    yield "login.json_invalid", c("POST", "/auth/login", data="{bad", headers={"Content-Type": "application/json"})
    for who, login, pw in [("admin", "admin", "admin123"), ("staff", " Staff ", "staff123")]:
        st, body, h = c("POST", "/auth/login", json={"username_or_email": login, "password": pw})
        if st == 200:
            s.tokens[who] = body["access_token"]
        yield f"login.{who}", (st, body, h)
    s.tokens["bad"] = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.invalidsig"
    yield "me.noauth", c("GET", "/auth/me")
    yield "me.badtoken", c("GET", "/auth/me", who="bad")
    yield "me.malformed_header", c("GET", "/auth/me", headers={"Authorization": "Token abc"})
    yield "auth.me", c("GET", "/auth/me", who="admin")
    yield "users.me", c("GET", "/users/me", who="staff")
    yield "users.patch", c("PATCH", "/users/me", who="staff", json={"display_name": "Nhân viên A"})
    yield "users.patch.422", c("PATCH", "/users/me", who="staff", json={"display_name": ""})
    yield "users.search", c("GET", "/users?search=ADMIN&limit=5", who="staff")
    yield "users.search.empty", c("GET", "/users", who="staff")
    yield "users.search.422", c("GET", "/users?search=&limit=0", who="staff")
    yield "register.taken", c("POST", "/auth/register/request-otp", json={
        "username": "Admin", "email": "new@example.com", "display_name": "x", "password": "secret1"})
    yield "register.badname", c("POST", "/auth/register/request-otp", json={
        "username": "Bad Name!", "email": "new@example.com", "display_name": "x", "password": "secret1"})
    yield "register.bademail", c("POST", "/auth/register/request-otp", json={
        "username": "newbie", "email": "not-an-email", "display_name": "x", "password": "secret1"})
    yield "register.confirm.nootp", c("POST", "/auth/register/confirm", json={"email": "new@example.com", "otp": "123456"})
    yield "otp.reset.unknown", c("POST", "/auth/password/request-otp", json={"username_or_email": "ghost"})
    yield "otp.reset.first", c("POST", "/auth/password/request-otp", json={"username_or_email": "staff"})
    yield "otp.reset.resend_within_60s", c("POST", "/auth/password/request-otp", json={"username_or_email": "staff"})
    yield "otp.reset.confirm.wrong", c("POST", "/auth/password/confirm", json={"email": "staff@example.com", "otp": "000000", "new_password": "abcdef"})
    yield "otp.passkey.badpass", c("POST", "/auth/passkey/request-otp", who="staff", json={"password": "nope"})
    yield "otp.passkey.confirm.none", c("POST", "/auth/passkey/confirm", who="admin", json={"otp": "123456", "new_passkey": "1234"})

    # ---- suppliers ----
    yield "supplier.create.noauth", c("POST", "/suppliers", json={"name": "NCC A"})
    yield "supplier.create.422", c("POST", "/suppliers", who="admin", json={"phone": "1"})
    yield "supplier.create", c("POST", "/suppliers", who="admin", json={"name": "NCC A", "phone": "0909", "tax_id": "TX1"})
    yield "supplier.create2", c("POST", "/suppliers", who="admin", json={"name": "Công ty B", "email": "b@x.vn"})
    yield "supplier.dup", c("POST", "/suppliers", who="admin", json={"name": "NCC A"})
    yield "supplier.update", c("PUT", "/suppliers/1", who="admin", json={"notes": "ghi chú", "phone": None})
    yield "supplier.update.404", c("PUT", "/suppliers/99", who="admin", json={"notes": "x"})
    yield "supplier.update.badid", c("PUT", "/suppliers/abc", who="admin", json={"notes": "x"})
    yield "supplier.list", c("GET", "/suppliers")
    yield "supplier.list.q", c("GET", "/suppliers?q=ncc&page=1&page_size=1")
    yield "supplier.list.422", c("GET", "/suppliers?page=0&page_size=500")

    # ---- items ----
    yield "item.create.422", c("POST", "/items", who="admin", json={"name": "x", "price": "abc"})
    items = [
        {"name": "Bút bi", "sku": "BB01", "unit": "cây", "price": 5000, "category": "Văn phòng phẩm", "supplier_id": 1},
        {"name": "Giấy A4", "sku": "A4", "unit": "ram", "price": 65000.5, "category": "Văn phòng phẩm", "min_stock": 5},
        {"name": "Chuột", "sku": "MS1", "unit": "cái", "price": 120000, "category": "Điện tử"},
        {"name": "Màn hình", "sku": "LCD", "unit": "cái", "price": 0, "category": "Điện tử", "min_stock": 2},
    ]
    for i, it in enumerate(items):
        yield f"item.create{i}", c("POST", "/items", who="admin", json=it)
    yield "item.dup", c("POST", "/items", who="admin", json=items[0])
    yield "item.update", c("PUT", "/items/3", who="admin", json={"category": "Thiết bị", "quantity": 7})
    yield "item.update.dupsku", c("PUT", "/items/3", who="admin", json={"sku": "A4"})
    yield "item.list", c("GET", "/items")
    yield "item.list.filters", c("GET", "/items?q=b&category=Văn phòng phẩm&low_stock=true&sort=name_desc&page=1&page_size=10")
    yield "item.list.sort", c("GET", "/items?sort=quantity_desc")
    yield "item.list.badbool", c("GET", "/items?low_stock=maybe")

    # ---- warehouses ----
    yield "warehouse.create", c("POST", "/warehouses", who="admin", json={"name": "Kho 1", "code": "K1",
                                 "managers": [{"email": "Boss@Example.COM", "position": "Chủ kho"}]})
    yield "warehouse.create2", c("POST", "/warehouses", who="admin", json={"name": "Kho 2", "code": "K2"})
    yield "warehouse.dup", c("POST", "/warehouses", who="admin", json={"name": "Kho X", "code": "K1"})
    yield "warehouse.bad_manager", c("POST", "/warehouses", who="admin", json={"name": "Kho Y", "code": "KY", "managers": [{"email": "x"}]})
    yield "warehouse.list", c("GET", "/warehouses")
    yield "warehouse.active", c("GET", "/warehouses/active")
    yield "warehouse.set_active", c("PUT", "/warehouses/2/set-active", who="admin")
    yield "warehouse.update.no_passkey", c("PUT", "/warehouses/2", who="admin", json={"address": "Q1"})
    yield "warehouse.update", c("PUT", "/warehouses/2", who="admin", json={"address": "Q1", "code": "K2B"},
                                headers={"X-Passkey": "246810"})
    yield "warehouse.update.dupcode", c("PUT", "/warehouses/2", who="admin", json={"code": "K1"}, headers={"X-Passkey": "246810"})

    # ---- stock in/out ----
    base = {"warehouse_code": "K1", "date": "2026-03-05", "tax_rate": 10}
    yield "stockin.422", c("POST", "/stock/in", who="staff", json={"warehouse_code": "K1"})
    yield "stockin.unknown_item", c("POST", "/stock/in", who="staff", json={**base, "supplier": "NCC A", "items": [
        {"item_id": "999", "item_code": "NOPE", "item_name": "?", "quantity": 1, "unit": "cái"}]})
    yield "stockin.1", c("POST", "/stock/in", who="staff", json={**base, "supplier": "NCC A", "note": " lô 1 ", "items": [
        {"item_id": "2", "item_code": "A4", "item_name": "Giấy", "quantity": 10, "unit": "ram", "price": 60000},
        {"item_id": "", "item_code": "BB01", "item_name": "Bút", "quantity": 100, "unit": "cây", "price": 4000.5}]})
    yield "stockin.2", c("POST", "/stock/in", who="staff", json={**base, "date": "2026-03-20T10:00:00Z", "supplier": "Công ty B",
        "payment_method": "chuyển_khoản", "items": [
        {"item_id": "2", "item_code": "A4", "item_name": "Giấy", "quantity": 5, "unit": "ram", "price": 70000},
        {"item_id": "3", "item_code": "MS1", "item_name": "Chuột", "quantity": 3, "unit": "cái", "price": 100000}]})
    yield "stockin.baddate", c("POST", "/stock/in", who="staff", json={**base, "warehouse_code": "K9", "date": "05/03/2026",
        "supplier": "NCC A", "items": [{"item_id": "4", "item_code": "LCD", "item_name": "LCD", "quantity": 1, "unit": "cái"}]})
    yield "stockout.insufficient", c("POST", "/stock/out", who="staff", json={**base, "recipient": "KH", "purpose": "Bán hàng", "items": [
        {"item_id": "2", "item_code": "A4", "item_name": "Giấy", "quantity": 999, "unit": "ram"}]})
    yield "stockout.sale", c("POST", "/stock/out", who="staff", json={**base, "recipient": "Khách lẻ", "purpose": "Bán hàng", "note": "bán", "items": [
        {"item_id": "2", "item_code": "A4", "item_name": "Giấy", "quantity": 12, "unit": "ram", "sell_price": 50000},
        {"item_id": "1", "item_code": "BB01", "item_name": "Bút", "quantity": 10, "unit": "cây", "sell_price": 6000},
        {"item_id": "3", "item_code": "MS1", "item_name": "Chuột", "quantity": 1, "unit": "cái"},
        {"item_id": "4", "item_code": "LCD", "item_name": "LCD", "quantity": 0, "unit": "cái", "sell_price": 0}]})
    yield "stockout.internal", c("POST", "/stock/out", who="staff", json={**base, "recipient": "Phòng IT", "purpose": "Nội bộ", "items": [
        {"item_id": "1", "item_code": "BB01", "item_name": "Bút", "quantity": 5, "unit": "cây", "sell_price": 5100}]})
    yield "stockin.list", c("GET", "/stock/in")
    yield "stockin.list.filters", c("GET", "/stock/in?q=lô&warehouse_code=K1&date_from=2026-03-01&date_to=2026-03-10&page=1")
    yield "stockin.one", c("GET", "/stock/in/K1_PN_0326_0001")
    yield "stockin.one.404", c("GET", "/stock/in/NOPE")
    yield "stockout.list", c("GET", "/stock/out?purpose=Bán hàng")
    yield "stockout.one", c("GET", "/stock/out/K1_PX_0326_0001")
    yield "stockout.cancel.nopasskey", c("DELETE", "/stock/out/K1_PX_0326_0001", who="staff")
    yield "stockout.cancel.nopasskey_set", c("DELETE", "/stock/out/K1_PX_0326_0001", who="staff", headers={"X-Passkey": "1111"})
    yield "stockout.cancel.wrong", c("DELETE", "/stock/out/K1_PX_0326_0001", who="admin", headers={"X-Passkey": "0000"})
    yield "stockout.cancel", c("DELETE", "/stock/out/K1_PX_0326_0001?passkey=246810", who="admin")
    yield "stockout.cancel.again", c("DELETE", "/stock/out/K1_PX_0326_0001", who="admin", headers={"X-Passkey": "246810"})
    yield "stockin.cancel.insufficient", c("DELETE", "/stock/in/K1_PN_0326_0002", who="admin", headers={"X-Passkey": "246810"})
    yield "stockin.cancel", c("DELETE", "/stock/in/K1_PN_0326_0001", who="admin", headers={"X-Passkey": "246810"})
    yield "stockin.list.cancelled", c("GET", "/stock/in?include_cancelled=true")
    yield "tx.list", c("GET", "/stock/transactions")
    yield "tx.create", c("POST", "/stock/transactions", who="staff", json={"type": "in", "item_id": 4, "quantity": 3, "note": "manual"})
    yield "tx.create.out_insufficient", c("POST", "/stock/transactions", who="staff", json={"type": "out", "item_id": 4, "quantity": 30})
    yield "tx.create.badtype", c("POST", "/stock/transactions", who="staff", json={"type": "x", "item_id": 4, "quantity": 1})
    yield "tx.create.404", c("POST", "/stock/transactions", who="staff", json={"type": "x", "item_id": 404, "quantity": 1})

    # ---- reads after activity ----
    for p in ["/items", "/items/alerts", "/items/top-items", "/items/monthly-trend", "/items/category-distribution",
              "/dashboard/stats", "/reports/inventory-by-category", "/reports/monthly-trend", "/reports/low-stock-items",
              "/warehouses/1/inventory", "/warehouses/9/inventory", "/suppliers/1/transactions", "/suppliers/2/transactions",
              "/search/global?q=a", "/search/global?q=K1&limit=1", "/search/global", "/search/global?q=a&limit=99"]:
        yield f"GET {p}", c("GET", p)

    # ---- company ----
    yield "company.put.404", c("PUT", "/company", who="admin", json={"name": "x"})
    yield "company.post", c("POST", "/company", who="admin", json={"name": "N3T", "tax_id": "0101"})
    yield "company.post2", c("POST", "/company", who="admin", json={"phone": "028"})
    yield "company.put", c("PUT", "/company", who="admin", json={"address": "HCM", "name": None})
    yield "company.get", c("GET", "/company")
    yield "logo.notimage", c("POST", "/company/upload-logo", files={"file": ("a.png", b"hello", "image/png")})
    yield "logo.notsquare", c("POST", "/company/upload-logo", files={"file": ("a.png", make_png(3, 2), "image/png")})
    yield "logo.ok", c("POST", "/company/upload-logo", files={"file": ("Logo.PNG", png, "image/png")})
    yield "logo.missing", c("POST", "/company/upload-logo")

    # ---- preferences & bot chat ----
    yield "prefs.get", c("GET", "/user/preferences", who="staff")
    yield "prefs.put", c("PUT", "/user/preferences", who="staff", json={"accent_id": "red", "dark_mode_theme": {"gradient_id": "polar", "pattern_opacity": 0.2}})
    yield "prefs.put.422", c("PUT", "/user/preferences", who="staff", json={"light_mode_theme": {"pattern_size_px": "big"}})
    yield "chat.create", c("POST", "/chat/messages", who="staff", json={"id": "m1", "conversation_id": "bot_x", "sender": "user", "text": "xin chào",
                           "reply_to": {"id": "m0", "text": "hi", "sender": "bot"}})
    yield "chat.create.dup", c("POST", "/chat/messages", who="staff", json={"id": "m1", "conversation_id": "bot_x", "sender": "user", "text": "khác"})
    yield "chat.create.forbidden", c("POST", "/chat/messages", who="staff", json={"id": "m2", "conversation_id": "other", "sender": "user", "text": "x"})
    yield "chat.react", c("PUT", "/chat/messages/m1/reactions", who="staff", json={"reactions": ["👍", "❤️"]})
    yield "chat.react.404", c("PUT", "/chat/messages/zz/reactions", who="staff", json={})
    yield "chat.get", c("GET", "/chat/messages/bot_x", who="staff")
    yield "chat.clear", c("DELETE", "/chat/messages/bot_x", who="staff")
    yield "chat.get.after_clear", c("GET", "/chat/messages/bot_x", who="staff")

    # ---- chatbot ----
    yield "chatbot.put.forbidden", c("PUT", "/api/chatbot/config?bot_name=X", who="staff")
    yield "chatbot.put", c("PUT", "/api/chatbot/config?bot_name=Trợ lý&bot_description=mô tả", who="admin")
    yield "chatbot.avatar.forbidden", c("POST", "/api/chatbot/avatar", who="staff", files={"file": ("a.png", png, "image/png")})
    yield "chatbot.avatar.notimage", c("POST", "/api/chatbot/avatar", who="admin", files={"file": ("a.txt", b"x", "text/plain")})
    yield "chatbot.avatar", c("POST", "/api/chatbot/avatar", who="admin", files={"file": ("bot.png", png, "image/png")})
    yield "chatbot.config", c("GET", "/api/chatbot/config")
    yield "files.ok", c("POST", "/api/chatbot/files", who="staff", files={"file": ("note.txt", b"hello", "text/plain")})
    yield "files.bad_ext", c("POST", "/api/chatbot/files", who="staff", files={"file": ("x.exe", b"MZ", "application/octet-stream")})
    yield "download.badfolder", c("GET", "/download/etc/passwd")
    yield "download.404", c("GET", "/download/chat_files/none.txt")

    # ---- avatar ----
    yield "avatar.badtype", c("POST", "/users/me/avatar", who="staff", files={"file": ("a.txt", b"x", "text/plain")})
    yield "avatar.badimage", c("POST", "/users/me/avatar", who="staff", files={"file": ("a.png", b"x", "image/png")})
    yield "avatar.ok", c("POST", "/users/me/avatar", who="staff", files={"file": ("a.png", png, "image/png")})
    yield "avatar.delete", c("DELETE", "/users/me/avatar", who="staff")

    # ---- rt REST ----
    yield "rt.direct.self", c("POST", "/rt/conversations/direct", who="staff", json={"email": "staff@example.com"})
    yield "rt.direct.none", c("POST", "/rt/conversations/direct", who="staff", json={})
    yield "rt.direct.404", c("POST", "/rt/conversations/direct", who="staff", json={"email": "ghost@example.com"})
    st, body, h = c("POST", "/rt/conversations/direct", who="staff", json={"email": "ADMIN@example.com"})
    yield "rt.direct", (st, body, h)
    s.conv = body.get("conversation_id") if isinstance(body, dict) else None
    yield "rt.direct.again", c("POST", "/rt/conversations/direct", who="admin", json={"other_user_id": "u-staff"})
    yield "rt.list.staff", c("GET", "/rt/conversations", who="staff")
    yield "rt.pending.admin", c("GET", "/rt/conversations/pending", who="admin")
    yield "rt.lookup", c("GET", "/rt/users/lookup?email=Admin@Example.com", who="staff")
    yield "rt.lookup.404", c("GET", "/rt/users/lookup?email=no@example.com", who="staff")
    yield "rt.lookup.422", c("GET", "/rt/users/lookup?email=bad", who="staff")
    yield "rt.files", c("POST", "/rt/files", who="staff", files={"file": ("p.pdf", b"%PDF-1.4", "application/pdf")})
    yield "rt.files.bad_ext", c("POST", "/rt/files", who="staff", files={"file": ("p.webp", b"x", "image/webp")})
    yield "rt.messages.404", c("GET", "/rt/conversations/nope/messages", who="staff")


def ws_script(s: Session, ws_url):
    """Realtime scenario: admin accepts, both connect, chat, react, pin, edit, sync, delete."""
    out = []

    async def recv_all(ws, wait=0.6):
        frames = []
        while True:
            try:
                f = json.loads(await asyncio.wait_for(ws.recv(), wait))
                if f.get("type") != "ping":  # heartbeat timing is not deterministic
                    frames.append(f)
            except (asyncio.TimeoutError, TimeoutError):
                return frames

    async def run():
        conv = s.conv
        try:
            async with websockets.connect(f"{ws_url}?token=bad"):
                out.append(("ws.badtoken", "accepted"))
        except Exception as e:  # handshake rejected
            out.append(("ws.badtoken", f"rejected {getattr(getattr(e, 'response', None), 'status_code', '')}"))
        async with websockets.connect(f"{ws_url}?token={s.tokens['staff']}") as a, \
                websockets.connect(f"{ws_url}?token={s.tokens['admin']}") as b:
            async def step(name, sock, frame, wait=0.6):
                await sock.send(json.dumps(frame) if not isinstance(frame, str) else frame)
                await asyncio.sleep(0.3)
                out.append((name, {"staff": await recv_all(a, wait), "admin": await recv_all(b, wait)}))

            await step("hello", a, {"type": "client:hello", "reqId": "r1", "data": {"reqId": "inner", "deviceId": "d"}})
            await step("bad_json", a, "{nope")
            await step("unknown", a, {"type": "foo", "reqId": "r2", "data": {}})
            await step("join.forbidden", a, {"type": "conv:join", "reqId": "r3", "data": {"conversationId": "zzz"}})
            await step("join", a, {"type": "conv:join", "reqId": "r4", "data": {"conversationId": conv}})
            await step("join.b", b, {"type": "conv:join", "data": {"conversationId": conv}})
            await step("send.invalid", a, {"type": "msg:send", "reqId": "r5", "data": {"conversationId": conv}})
            await step("send", a, {"type": "msg:send", "reqId": "r6", "data": {
                "conversationId": conv, "clientMessageId": "c1", "content": "Chào sếp", "attachments": [{"name": "f"}]}})
            await step("send.dup", a, {"type": "msg:send", "reqId": "r7", "data": {
                "conversationId": conv, "clientMessageId": "c1", "content": "Chào sếp"}})
            await step("send.b", b, {"type": "msg:send", "reqId": "r8", "data": {
                "conversationId": conv, "clientMessageId": "c2", "content": "ok", "replyToId": None}})
            await step("typing", a, {"type": "typing", "data": {"conversationId": conv, "isTyping": True}})
            st, body, _ = s.call("GET", f"/rt/conversations/{conv}/messages", who="staff")
            mids = [m["id"] for m in body["messages"]] if st == 200 else ["x", "y"]
            out.append(("rest.messages", s.call("GET", f"/rt/conversations/{conv}/messages?limit=1", who="staff")))
            out.append(("rest.messages.after", s.call("GET", f"/rt/conversations/{conv}/messages?after={mids[0]}", who="staff")))
            out.append(("rest.messages.before", s.call("GET", f"/rt/conversations/{conv}/messages?before={mids[-1]}", who="staff")))
            await step("read", b, {"type": "msg:read", "reqId": "r9", "data": {"conversationId": conv, "lastReadMessageId": mids[0]}})
            await step("read.invalid", b, {"type": "msg:read", "reqId": "r10", "data": {"reqId": "in"}})
            await step("react", b, {"type": "msg:react", "reqId": "r11", "data": {"conversationId": conv, "messageId": mids[0], "emoji": "👍"}})
            await step("react.toggle", b, {"type": "msg:react", "reqId": "r12", "data": {"conversationId": conv, "messageId": mids[0], "emoji": "👍"}})
            await step("pin", a, {"type": "msg:pin", "reqId": "r13", "data": {"conversationId": conv, "messageId": mids[0]}})
            await step("pin.again", a, {"type": "msg:pin", "reqId": "r14", "data": {"conversationId": conv, "messageId": mids[0]}})
            await step("edit.other", b, {"type": "msg:edit", "reqId": "r15", "data": {"conversationId": conv, "messageId": mids[0], "content": "x"}})
            await step("edit", a, {"type": "msg:edit", "reqId": "r16", "data": {"conversationId": conv, "messageId": mids[0], "content": "Đã sửa"}})
            await step("sync", a, {"type": "conv:sync", "reqId": "r17", "data": {"conversationId": conv, "limit": 1}})
            await step("delete.me", b, {"type": "msg:delete", "reqId": "r18", "data": {"conversationId": conv, "messageId": mids[0]}})
            await step("delete.everyone", a, {"type": "msg:delete", "reqId": "r19", "data": {"conversationId": conv, "messageId": mids[0], "deleteForEveryone": True}})
            await step("unpin.missing", a, {"type": "msg:unpin", "reqId": "r20", "data": {"conversationId": conv, "messageId": "nope"}})
            out.append(("rest.pinned", s.call("GET", f"/rt/conversations/{conv}/pinned", who="admin")))
            out.append(("rest.list.admin", s.call("GET", "/rt/conversations", who="admin")))
            out.append(("rest.pending.admin", s.call("GET", "/rt/conversations/pending", who="admin")))
            out.append(("rest.react.add", s.call("POST", f"/rt/messages/{mids[-1]}/reactions", who="admin", json={"emoji": "🔥"})))
            out.append(("rest.react.dup", s.call("POST", f"/rt/messages/{mids[-1]}/reactions", who="admin", json={"emoji": "🔥"})))
            out.append(("rest.react.list", s.call("GET", f"/rt/messages/{mids[-1]}/reactions", who="staff")))
            out.append(("rest.react.del", s.call("DELETE", f"/rt/messages/{mids[-1]}/reactions/🔥", who="admin")))
            out.append(("rest.react.del404", s.call("DELETE", f"/rt/messages/{mids[-1]}/reactions/🔥", who="admin")))
            out.append(("rest.pin", s.call("POST", f"/rt/conversations/{conv}/pin/{mids[-1]}", who="admin")))
            out.append(("rest.pin.dup", s.call("POST", f"/rt/conversations/{conv}/pin/{mids[-1]}", who="admin")))
            out.append(("rest.unpin", s.call("DELETE", f"/rt/conversations/{conv}/pin/{mids[-1]}", who="admin")))
            out.append(("rest.accept", s.call("POST", f"/rt/conversations/{conv}/accept", who="admin")))
            # inventory broadcast from HTTP -> WS
            s.call("POST", "/stock/in", who="staff", json={"warehouse_code": "K1", "date": "2026-04-01", "supplier": "NCC A",
                   "items": [{"item_id": "4", "item_code": "LCD", "item_name": "LCD", "quantity": 1, "unit": "cái"}]})
            await asyncio.sleep(0.5)
            out.append(("inventory.broadcast", {"staff": await recv_all(a), "admin": await recv_all(b)}))
        out.append(("rest.reject.422", s.call("POST", f"/rt/conversations/{conv}/reject", who="admin")))
        out.append(("rest.reject", s.call("POST", f"/rt/conversations/{conv}/reject", who="admin", json={"delete_history": True})))
        out.append(("rest.list.after_reject", s.call("GET", "/rt/conversations", who="staff")))

    asyncio.run(run())
    return out


def tail(s: Session):
    c = s.call
    voucher = {"voucher_type": "PN", "voucher_no": "PN/001", "voucher_date": "2026-03-05", "partner_name": "NCC A",
               "warehouse_code": "K1", "tax_rate": 8, "invoice_no": "HD1", "items": [
                   {"sku": "A4", "name": "Giấy", "unit": "ram", "qty_doc": 10, "qty_actual": 10, "unit_price": 65000}]}
    yield "export.excel", c("POST", "/export/excel", json=voucher)
    yield "export.pdf", c("POST", "/export/pdf", json={**voucher, "voucher_type": "PX"})
    yield "export.too_many", c("POST", "/export/excel", json={**voucher, "items": voucher["items"] * 19})
    yield "export.422", c("POST", "/export/pdf", json={"voucher_type": "PN"})
    yield "ai.no_key", c("POST", "/ai/chat", json={"prompt": "hi"})
    yield "ai.md.no_key", c("POST", "/ai/chat-md", json={"prompt": "hi"})
    yield "item.delete.nopasskey", c("DELETE", "/items/4", who="admin")
    yield "item.delete", c("DELETE", "/items/4", who="admin", headers={"X-Passkey": "246810"})
    yield "item.delete.404", c("DELETE", "/items/4", who="admin", headers={"X-Passkey": "246810"})
    yield "supplier.delete", c("DELETE", "/suppliers/2", who="admin", headers={"X-Passkey": "246810"})
    yield "warehouse.delete.wrong_passkey", c("DELETE", "/warehouses/1", who="admin", headers={"X-Passkey": "000000"})
    yield "warehouse.delete", c("DELETE", "/warehouses/1", who="admin", headers={"X-Passkey": "246810"})
    yield "warehouse.list.after_delete", c("GET", "/warehouses")


def collect(base, ws):
    s = Session(base)
    res = list(script(s))
    res += ws_script(s, ws)
    res += list(tail(s))
    return res


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--py", required=True)
    ap.add_argument("--php", required=True)
    ap.add_argument("--py-ws", required=True)
    ap.add_argument("--php-ws", required=True)
    ap.add_argument("--dump", help="write normalised results to this JSON file")
    a = ap.parse_args()

    py = collect(a.py, a.py_ws)
    php = collect(a.php, a.php_ws)
    unexpected, approved, same = [], [], 0
    for (name, pv), (name2, hv) in zip(py, php):
        assert name == name2
        np_, nh = typed(norm(pv)), typed(norm(hv))
        if np_ == nh:
            same += 1
        elif name in EXPECTED_DIFF:
            approved.append((name, EXPECTED_DIFF[name], np_, nh))
        else:
            unexpected.append((name, np_, nh))
    if a.dump:
        with open(a.dump, "w", encoding="utf-8") as f:
            json.dump({"py": [(n, typed(norm(v))) for n, v in py], "php": [(n, typed(norm(v))) for n, v in php]},
                      f, ensure_ascii=False, indent=1)
    print(f"steps={len(py)} identical={same} approved_diffs={len(approved)} unexpected={len(unexpected)}")
    for name, why, p, h in approved:
        print(f"[approved] {name}: {why}\n   py : {json.dumps(p, ensure_ascii=False)[:300]}\n   php: {json.dumps(h, ensure_ascii=False)[:300]}")
    for name, p, h in unexpected:
        print(f"[DIFF] {name}\n   py : {json.dumps(p, ensure_ascii=False)[:1500]}\n   php: {json.dumps(h, ensure_ascii=False)[:1500]}")
    sys.exit(1 if unexpected else 0)


if __name__ == "__main__":
    main()
