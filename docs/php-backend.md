# Backend PHP (Laravel 13 + Workerman)

Backend mặc định của dự án hiện là `KhoHang_API/php/`. Bản FastAPI cũ (`KhoHang_API/app/`) được giữ nguyên để so sánh và rollback.

## Thành phần

| Tiến trình | Lệnh | Cổng mặc định | Vai trò |
|---|---|---|---|
| HTTP API | `php artisan serve` (dev) hoặc nginx/Apache + PHP-FPM trỏ vào `php/public` | 8000 | 83 route REST, upload, export |
| Realtime | `php artisan rt:serve` | 8001 (+ 8002 loopback) | WebSocket `/ws/rt`, nhận sự kiện tồn kho từ HTTP |

Dùng chung với FastAPI: `KhoHang_API/data/data.db` (SQLite), `KhoHang_API/data/uploads/`, `KhoHang_API/templates/`, và file bí mật `KhoHang_API/.env` (`JWT_SECRET`, `SMTP_*`, `GEMINI_API_KEY`). Vì cùng `JWT_SECRET` và cùng định dạng bcrypt, người dùng không phải đăng nhập lại khi chuyển qua lại hai backend.

`JWT_SECRET` **bắt buộc**: bản PHP từ chối cấp/nhận token nếu thiếu (bản Python âm thầm dùng chuỗi mặc định).

## Chạy trên Windows

Yêu cầu: PHP 8.3+ (extension `pdo_sqlite`, `sqlite3`, `gd` có WebP, `mbstring`, `zip`, `xml`, `fileinfo`, `openssl`), Composer.

```bat
start_app.bat           :: backend PHP + frontend Tauri
start_backend.bat       :: chỉ backend PHP (mở thêm cửa sổ WebSocket)
start_backend_python.bat:: backend FastAPI cũ (rollback)
```

`start_backend.bat` chạy `composer install` lần đầu, tạo `php/.env`, rồi `php artisan khohang:upgrade`: nếu còn migration chưa chạy thì **sao lưu trước** (`data/backups/<thời gian>/` kèm SHA-256) rồi mới migrate. Migration chỉ dùng `CREATE … IF NOT EXISTS`/`ADD COLUMN`, không sửa dữ liệu; bảng `migrations` được thêm vào DB và FastAPI bỏ qua bảng này.

## Chạy thủ công

```bash
cd KhoHang_API/php
composer install
cp .env.example .env && php artisan key:generate
php artisan khohang:upgrade
php artisan rt:serve            # cửa sổ 1
php artisan serve --port=8000   # cửa sổ 2
```

`php artisan serve` dùng web server tích hợp của PHP: trên Windows chỉ xử lý **một request một lúc** (một lời gọi Gemini dài sẽ chặn người khác). Trên Linux đặt `PHP_CLI_SERVER_WORKERS=4`; khi triển khai thật dùng nginx + PHP-FPM. Workerman trên Windows chỉ chạy 1 tiến trình (đủ cho thiết kế hiện tại, vốn yêu cầu đúng 1 tiến trình).

## Client

- `VITE_API_BASE_URL` như cũ (mặc định `http://localhost:8000`).
- WebSocket: `VITE_WS_URL` (ví dụ `ws://192.168.1.10:8001`), hoặc `VITE_WS_PORT`; mặc định cùng host với API, cổng 8001.
- Sửa/xoá kho giờ hỏi passkey và gửi `X-Passkey`. Tài khoản chưa đặt passkey sẽ nhận lỗi `User chưa thiết lập passkey` — đặt passkey trong Cài đặt → Tài khoản trước.

## Sao lưu, khôi phục, rollback

```bash
php artisan khohang:backup                      # VACUUM INTO + uploads + manifest SHA-256
php artisan khohang:restore <thư mục backup> <thư mục đích> [--force]
```

Không copy `data.db` bằng tay khi server đang chạy: SQLite ở chế độ WAL, dữ liệu mới có thể còn trong `data.db-wal`.

Rollback về FastAPI: dừng hai tiến trình PHP, chạy `start_backend_python.bat`, và build client với `VITE_WS_URL=ws://localhost:8000` (hoặc trả lại commit client cũ). Dữ liệu ghi bởi PHP dùng cùng schema/định dạng nên FastAPI đọc được (đã kiểm: token, hash bcrypt, 18 bảng).

## Kiểm thử

```bash
cd KhoHang_API/php
php artisan test                         # DatabaseCompatibilityTest, AuthContractTest
PY=/đường/dẫn/venv/bin/python tests/contract/run.sh   # so sánh với FastAPI (Linux/macOS, cần requirements.txt + websockets + uvicorn)
```

`run.sh` không đụng tới `KhoHang_API/data`: nó tạo fixture tổng hợp, chạy hai backend trên hai bản sao và so sánh từng response, frame WebSocket và nội dung DB. Danh sách khác biệt được chấp nhận nằm trong `EXPECTED_DIFF` của `compare_backends.py` và trong [ma trận hợp đồng](../plans/260924-0634-migrate-khohang-api-to-php/contract-matrix.md).

## Chưa được kiểm chứng

- Gửi mail OTP qua SMTP thật và gọi Gemini thật (cần credential).
- Chạy React/Tauri end-to-end với backend PHP (mới typecheck `tsc`).
- Dữ liệu vận hành thật (mới dùng fixture tổng hợp).
- Chạy trên Windows (mới chạy trên Linux).
