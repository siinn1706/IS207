"""
Compares two SQLite files table by table after a contract run (row counts and
normalised row contents). JSON columns are parsed, UUIDs/timestamps/hashes masked.
  python compare_databases.py py/data/data.db php/data.db
"""
import json
import re
import sqlite3
import sys

UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")
TS = re.compile(r"\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?")
HEX32 = re.compile(r"(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])")
SKIP_COLS = {"password_hash", "passkey_hash", "code_hash"}
SKIP_TABLES = {"migrations", "sqlite_sequence"}
# Approved difference: FastAPI keeps the unverified user + OTP of a registration whose
# OTP mail failed; the PHP backend removes them so the email can register again.
IGNORED_ROW_MARKERS = ['"new@example.com"']
SKIP_TABLE_COLS = {("email_otps", "id")}  # autoincrement shifted by the removed row above


def norm(v):
    if isinstance(v, str):
        try:
            j = json.loads(v)
            if isinstance(j, (list, dict)) or v == "null":
                return json.dumps(norm(j), sort_keys=True, ensure_ascii=False)
        except ValueError:
            pass
        return HEX32.sub("<hex>", TS.sub("<ts>", UUID.sub("<uuid>", v)))
    if isinstance(v, list):
        return [norm(x) for x in v]
    if isinstance(v, dict):
        return {k: norm(x) for k, x in v.items()}
    if isinstance(v, float) and v.is_integer():
        return int(v)
    return v


def dump(path):
    con = sqlite3.connect(path)
    out = {}
    for (t,) in con.execute("select name from sqlite_master where type='table'"):
        if t in SKIP_TABLES:
            continue
        cur = con.execute(f"select * from {t}")
        cols = [c[0] for c in cur.description]
        rows = [tuple(json.dumps(norm(v), ensure_ascii=False) for c, v in zip(cols, r) if c not in SKIP_COLS and (t, c) not in SKIP_TABLE_COLS) for r in cur]
        out[t] = sorted(r for r in rows if not any(m in r for m in IGNORED_ROW_MARKERS))
    return out


a, b = dump(sys.argv[1]), dump(sys.argv[2])
bad = 0
for t in sorted(set(a) | set(b)):
    ra, rb = a.get(t), b.get(t)
    if ra == rb:
        print(f"ok   {t}: {len(ra)} rows")
        continue
    bad += 1
    print(f"DIFF {t}: {len(ra or [])} vs {len(rb or [])} rows")
    for x in sorted(set(ra or []) ^ set(rb or []))[:6]:
        print("    ", "py " if x in (ra or []) else "php", x)
sys.exit(1 if bad else 0)
