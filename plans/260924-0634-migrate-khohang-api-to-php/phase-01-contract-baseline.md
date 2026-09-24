---
phase: 1
title: "Chốt hợp đồng và baseline"
status: pending
priority: P1
effort: "TBD"
dependencies: []
---

# Phase 1: Chốt hợp đồng và baseline

## Goal

Đóng băng phạm vi và bằng chứng đầu vào trước khi thay FastAPI bằng PHP.

## Requirements

- Kiểm kê 83 route HTTP và `/ws/rt`, gồm URL, method, payload, query, response, status, lỗi, quyền, file và trường hợp phân trang. Đối chiếu mọi lời gọi trong `UI_Desktop` thay vì suy từ README.
- Ghi lại 18 bảng và cả index/constraint trong `database.py`, các schema tự sửa khi khởi động, định dạng JSON, thời gian UTC, bcrypt và OTP.
- Ghi nhận lựa chọn đã chấp thuận: Laravel 13 + Workerman, giới hạn thay đổi client, cách bảo toàn JWT đang còn hiệu lực và quyết định giữ FIFO hiện tại trong migration. `calculate_fifo_cost()` duyệt các phiếu nhập nhưng không trừ lượng đã xuất trước đó, nên việc sửa thuộc thay đổi riêng.
- Ghi nhận thay đổi bảo mật đã chấp thuận: kiểm tra passkey `123456` ở `SettingsModal.tsx` hiện chỉ là kiểm tra UI, còn route cập nhật/xóa kho trong `main.py` chỉ yêu cầu auth. Backend PHP phải xác minh passkey thật và client gửi `X-Passkey` cho hai thao tác này.
- Phân loại route cũ còn dùng và route chỉ còn trong wrapper legacy; không xóa vì tên có vẻ deprecated.

## Trade-offs

| Cách làm | Giả định chính | Thất bại đầu tiên khi |
|---|---|---|
| Laravel + Workerman tương thích frame cũ (đã chọn) | Có thể vận hành HTTP và WS PHP cùng dữ liệu, port trực tiếp giao thức hiện hữu | Môi trường đích không chạy được WS worker hoặc không thể chuyển event liên tiến trình |
| Laravel + Reverb | Có thể đổi client/chat store sang Pusher protocol trong phạm vi dự án | Client cần giữ `/ws/rt` và frame `{type, reqId, data}` không đổi |
| PHP thuần + thư viện nhỏ | Đội dự án sẵn sàng tự duy trì routing, validation, auth, migration và test | Số lượng hợp đồng 83 route/18 bảng làm lớp tự viết khó kiểm chứng |

Better approaches: none — hướng đã chọn vẫn là backend PHP theo yêu cầu, với Laravel cho HTTP và Workerman giữ giao thức WS đang dùng bởi `rt_ws_client.ts`.

## Files / evidence

- Read: [README](../../README.md), [FastAPI routes](../../KhoHang_API/app/main.py), [auth](../../KhoHang_API/app/auth_routes.py), [realtime HTTP](../../KhoHang_API/app/rt_chat_routes.py), [realtime WS](../../KhoHang_API/app/rt_chat_ws.py), [schemas](../../KhoHang_API/app/schemas.py), [DB](../../KhoHang_API/app/database.py), [client API](../../UI_Desktop/src/app/api_client.ts), [client WS](../../UI_Desktop/src/services/rt_ws_client.ts).
- Create during execution: `plans/260924-0634-migrate-khohang-api-to-php/contract-matrix.md` and sanitized baseline fixtures inside the plan's `artifacts/` directory. Do not commit production payloads.

## Implementation steps

1. Pin the source commit and enumerate routes statically. If importing FastAPI to generate OpenAPI, first redirect it to an isolated DB copy: importing `database.py` calls `init_db()` and can alter a DB.
2. Record frontend request/response usages, including `X-Passkey`, token storage, `/uploads/*`, `/download/*`, binary exports, `/api/chatbot/*`, `/rt/*`, and WebSocket `{type, reqId, data}` frames.
3. Capture sanitized fixtures and expected HTTP/WS traces from a disposable FastAPI instance; mark any route unavailable without SMTP, Gemini, or real data as unverified.
4. Ghi lại quyết định Laravel + Workerman cùng giả định vận hành cần kiểm chứng từ bảng so sánh. Giữ FIFO cũ trong bản port, lập thay đổi nghiệp vụ riêng cho defect đã nhận diện và đưa passkey phía server vào ma trận khác biệt đã duyệt.

## Todo

- [ ] Contract matrix covers all HTTP routes, WebSocket events, client callers, and auth rules.
- [ ] Baseline uses isolated data; no source DB or provider state changed.
- [ ] Quyết định Laravel + Workerman, giữ FIFO hiện tại và sửa bảo vệ passkey phía server được ghi trong ma trận.

## Success Criteria

Every planned behavior has a source anchor or an explicit unverified gate; no route is silently omitted. Review the matrix against `rg` output and the frontend build inputs.

## Risk / rollback

Import-time DB initialization can mutate data. Use only a copied DB and retain the original unchanged; discard baseline fixtures if they contain personal data.
