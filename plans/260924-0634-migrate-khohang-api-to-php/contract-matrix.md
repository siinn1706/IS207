# Ma trận hợp đồng FastAPI → PHP

Nguồn chuẩn: `KhoHang_API/app/*.py` tại commit hiện tại của repo. Bản PHP: `KhoHang_API/php/routes/api.php`.
Bằng chứng: `KhoHang_API/php/tests/contract/run.sh` chạy cùng một kịch bản (227 bước HTTP + WebSocket) trên FastAPI gốc và PHP, mỗi bên một bản sao của cùng fixture tổng hợp, rồi so status, header (`content-type`, `content-disposition`, `retry-after`), JSON (đã chuẩn hoá UUID/thời gian theo **dạng** chứ không bỏ qua) và nội dung 18 bảng sau khi chạy.

Kết quả lần chạy gần nhất: **232 bước — 216 giống hệt, 16 khác biệt đã duyệt (bảng dưới), 0 khác biệt ngoài dự kiến; 18/18 bảng giống nhau** (trừ bản ghi do lần đăng ký lỗi mail để lại ở FastAPI, xem #13); JWT do FastAPI cấp được PHP chấp nhận; hash bcrypt do PHP tạo được passlib xác minh. Ngoài ra `tests/e2e/run.sh` (Playwright) chạy UI thật: 7/7 luồng đạt.

Giới hạn của bằng chứng: fixture tổng hợp, không phải dữ liệu vận hành; SMTP và Gemini **chưa** được gọi thật (chỉ kiểm nhánh "chưa cấu hình"). Client đã chạy end-to-end ở chế độ web (Vite + Playwright, `tests/e2e/run.sh`), chưa chạy bản desktop Tauri.

## Route HTTP (83 của FastAPI + 2 route mới + static `/uploads`)

Auth: `—` công khai · `JWT` Bearer bắt buộc · `JWT+PK` thêm passkey (`X-Passkey`, hoặc `?passkey=` kiểu cũ) · `JWT+PK*` chỉ header `X-Passkey` · `admin` JWT và role admin.

| Nhóm | Method & path | Auth | Ghi chú |
|---|---|---|---|
| Gốc | `GET /`, `GET /debug/paths` | — | |
| File | `GET /download/{folder}/{filename}`, `GET /uploads/{path}` | — | Chặn path traversal; folder whitelist như cũ |
| Auth | `POST /auth/register/request-otp`, `/auth/register/confirm`, `/auth/login`, `/auth/password/request-otp`, `/auth/password/confirm` | — | Thêm rate limit: login 10 lần/phút/IP+tên, OTP 10 lần/10 phút/IP |
| Auth | `GET /auth/me`, `POST /auth/passkey/request-otp`, `/auth/passkey/confirm` | JWT | |
| Auth (mới) | `POST /auth/change-password` | JWT | Khác biệt #15; rate limit 5 lần/5 phút |
| Auth (mới) | `POST /auth/logout` | — | Khác biệt #16 |
| Users | `GET /users`, `GET/PATCH /users/me`, `POST/DELETE /users/me/avatar` | JWT | Avatar → WebP 800px (GD thay Pillow) |
| Chatbot | `GET /api/chatbot/config` | — | Prefix `/api` giữ nguyên (Laravel cấu hình `apiPrefix: ''`) |
| Chatbot | `PUT /api/chatbot/config` (query `bot_name`, `bot_description`), `POST /api/chatbot/avatar` | admin | |
| Chatbot | `POST /api/chatbot/files` | JWT | |
| RT REST | `GET /rt/conversations`, `/rt/conversations/pending`, `POST /rt/conversations/direct`, `GET /rt/conversations/{id}/messages`, `POST …/accept`, `POST …/reject`, `GET …/pinned`, `POST/DELETE …/pin/{mid}`, `POST /rt/files`, `GET /rt/users/lookup`, `GET/POST /rt/messages/{mid}/reactions`, `DELETE /rt/messages/{mid}/reactions/{emoji}` | JWT | lookup 10 req/phút/user |
| NCC | `GET /suppliers`, `GET /suppliers/{id}/transactions` | — | |
| NCC | `POST /suppliers`, `PUT /suppliers/{id}` | JWT | |
| NCC | `DELETE /suppliers/{id}` | JWT+PK | Hàng thuộc NCC bị gỡ `supplier_id` như backref SQLAlchemy |
| Hàng | `GET /items`, `/items/alerts`, `/items/top-items`, `/items/monthly-trend`, `/items/category-distribution` | — | Response `Item` **không** có `quantity` (pydantic đã loại) — giữ nguyên |
| Hàng | `POST /items`, `PUT /items/{id}` | JWT | |
| Hàng | `DELETE /items/{id}` | JWT+PK | Xem khác biệt #4 |
| Khác | `GET /search/global`, `GET /stock/transactions`, `GET /dashboard/stats` | — | |
| Khác | `POST /stock/transactions` | JWT | |
| AI | `POST /ai/chat`, `POST /ai/chat-md` | — | Gemini REST thay SDK; 501 khi thiếu key |
| Phiếu | `GET /stock/in`, `GET /stock/in/{id}`, `GET /stock/out`, `GET /stock/out/{id}` | — | |
| Phiếu | `POST /stock/in`, `POST /stock/out` | JWT | Giá vốn FIFO: khác biệt #17. Mã phiếu `{kho}_PN|PX_{mmyy}_{0001}`, retry 5 lần khi trùng |
| Phiếu | `DELETE /stock/in/{id}`, `DELETE /stock/out/{id}` | JWT+PK | Huỷ = hoàn tồn + ghi giao dịch đảo |
| Xuất tệp | `POST /export/excel`, `POST /export/pdf` | — | PhpSpreadsheet trên đúng template; PDF bằng Dompdf (font DejaVu, hỗ trợ tiếng Việt) |
| Công ty | `GET /company`, `POST /company/upload-logo` | — | |
| Công ty | `POST /company`, `PUT /company` | JWT | |
| Kho | `GET /warehouses`, `/warehouses/active`, `/warehouses/{id}/inventory` | — | |
| Kho | `POST /warehouses`, `PUT /warehouses/{id}/set-active` | JWT | |
| Kho | `PUT /warehouses/{id}`, `DELETE /warehouses/{id}` | **JWT+PK\*** | Thay đổi bảo mật đã duyệt (khác biệt #1) |
| Báo cáo | `GET /reports/inventory-by-category`, `/reports/monthly-trend`, `/reports/low-stock-items` | — | |
| Bot chat | `GET/DELETE /chat/messages/{conversation_id}`, `POST /chat/messages`, `PUT /chat/messages/{id}/reactions` | JWT | Quy tắc `bot_` cũ giữ nguyên (mọi id bắt đầu `bot_` đều truy cập được — xem rủi ro) |
| Theme | `GET/PUT /user/preferences` | JWT | |

Lỗi giữ dạng FastAPI: `{"detail": "..."}`; 422 `{"detail": [{type, loc, msg, input, ctx?}]}` (thứ tự field theo model pydantic); 404/405 `Not Found`/`Method Not Allowed`; 500 dạng text `Internal Server Error` khi `APP_DEBUG=false`.

Thời gian: DB lưu UTC naive `Y-m-d H:i:s.u` như SQLAlchemy. Response giữ đúng 3 dạng cũ: naive `…T…[.u]`, `to_utc_iso` `…+00:00`, và pydantic aware `…Z` (các DTO `/rt/*`). Đã đối chiếu từng chỗ bằng test.

## WebSocket `/ws/rt?token=<JWT>`

Server: Workerman (`php artisan rt:serve`), **cổng riêng 8001** (mặc định). Client đổi địa chỉ qua `VITE_WS_URL` hoặc tự suy ra từ `VITE_API_BASE_URL` + cổng 8001. Frame `{type, reqId, data}` giữ nguyên; các event client→server: `client:hello`, `conv:join`, `msg:send|edit|delete|react|pin|unpin|read`, `typing`, `conv:sync`, `pong`. Server→client: `server:hello`, `msg:ack|new|delivered|read|edit|edit:ack|delete|delete:ack|react|react:ack|pin:ack|pinned|unpin:ack|unpinned`, `typing`, `conv:sync:result`, `conv:upsert`, `inventory:updated`, `error`, `ping`.

Quirk `reqId` của bản Python được giữ (client đang chạy với chúng): `client:hello`, lỗi `msg:read` và `conv:sync:result` lấy `reqId` trong `data`; lỗi `conv:join` có `reqId: null`. Token sai: từ chối handshake bằng HTTP 403 như Starlette.

`inventory:updated` từ HTTP: tiến trình HTTP gửi một dòng JSON tới `127.0.0.1:8002` (chỉ loopback); WS server phát cho mọi kết nối. WS server tắt thì thao tác kho vẫn thành công, chỉ mất thông báo realtime.

## Khác biệt đã duyệt / sửa lỗi

| # | Hành vi | FastAPI | PHP | Lý do |
|---|---|---|---|---|
| 1 | Sửa/xoá kho | Chỉ cần JWT; UI so passkey với `123456` | Bắt buộc `X-Passkey` khớp passkey bcrypt của người gọi (không nhận `?passkey=`) | Quyết định đã duyệt trong plan |
| 2 | Gửi lại OTP reset/passkey trong 60s | 500 (trừ datetime naive và aware) | 429 `Please wait before requesting a new OTP` | Sửa lỗi; dữ liệu không đổi ở cả hai |
| 3 | Rate limit login/OTP | Không | 429 + `Retry-After` | Yêu cầu bước 4 phase 3 |
| 4 | Xoá hàng đã có giao dịch kho | 500 (IntegrityError), hàng vẫn còn | 409 `Không thể xóa hàng hoá đã có giao dịch kho`, hàng vẫn còn | Cùng kết quả dữ liệu, mã lỗi rõ |
| 5 | `DELETE /users/me/avatar` | Không xoá được file (sai đường dẫn theo CWD) | Xoá file trong `data/uploads/avatars` | Sửa lỗi |
| 6 | 422 JSON hỏng | Thông điệp/vị trí của `json` Python | `JSON decode error` + thông điệp `json_decode` | Khác parser |
| 7 | Danh sách đuôi file cho phép trong lỗi 400 | Thứ tự ngẫu nhiên (set) | Thứ tự cố định | |
| 8 | Lỗi ảnh hỏng khi upload avatar | Chuỗi Pillow kèm địa chỉ bộ nhớ | `Failed to process image: cannot identify image file` | |
| 9 | Nội dung byte XLSX/PDF | openpyxl / ReportLab | PhpSpreadsheet / Dompdf | Header và tên file giống nhau; bố cục PDF tương đương, không giống từng byte |
| 10 | WebSocket | Cùng cổng 8000 | Cổng 8001 | Quyết định Laravel + Workerman đã duyệt |
| 11 | `Item` trả thêm `quantity` | pydantic bỏ mất trường này → trang Hàng hoá hiện " /5", lọc "tồn thấp" hỏng | Có `quantity` | Sửa lỗi hiển thị (thêm trường, không đổi trường cũ) |
| 12 | `/chat/messages/*` | Mọi id bắt đầu `bot_` đều truy cập được → đọc/xoá lịch sử chatbot người khác | Chỉ đúng `bot_<id của mình>`, còn lại 403 | Lỗ hổng lộ dữ liệu; client vốn chỉ dùng id của mình |
| 13 | Đăng ký khi gửi mail OTP lỗi | User chưa xác minh bị giữ lại → email bị khoá (`Email already exists`) | Huỷ bản ghi của lần thử; tài khoản chưa xác minh có thể yêu cầu lại | Sửa lỗi |
| 14 | `GET /warehouses/{id}/inventory` | Cộng cả phiếu đã huỷ | Bỏ phiếu đã huỷ | Sửa lỗi số liệu |
| 15 | `POST /auth/change-password` | Không tồn tại (trang Đổi mật khẩu luôn 404) | Route mới: cần JWT, email phải của chính mình, kiểm mật khẩu cũ, rate limit | Hoàn thiện tính năng client đã có |
| 16 | `POST /auth/logout` | Không tồn tại (client gọi khi đăng xuất, nhận 404) | Trả `{"message": "Logged out"}` (JWT không trạng thái) | |
| 17 | Giá vốn FIFO (`cost` trong JSON phiếu xuất) | Mỗi lần xuất đều tính lại từ lô nhập **đầu tiên** → giá vốn sai từ lần xuất thứ hai | Bỏ qua lượng đã xuất bởi các phiếu xuất chưa huỷ (và các dòng trước trong cùng phiếu); phần vượt lô tính theo giá niêm yết | Thay đổi nghiệp vụ, nhánh riêng `claude/fifo-cogs`. Không có API/báo cáo nào đọc `cost` nên không đổi response; chỉ đổi dữ liệu lưu và ghi chú khi huỷ phiếu xuất |

## Hành vi giữ nguyên dù là lỗi

- `/rt/conversations/{id}/reject` với `delete_history` không xoá reactions/pin (FK không bật, như SQLAlchemy).
- Nhiều route ghi (company, warehouses create, stock…) chỉ cần đăng nhập, không phân quyền theo role.
