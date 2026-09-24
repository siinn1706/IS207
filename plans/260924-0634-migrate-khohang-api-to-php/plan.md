---
title: "Chuyển KhoHang_API từ FastAPI sang PHP"
description: "Chuyển toàn bộ backend sang PHP và giữ hợp đồng đang dùng bởi React/Tauri."
status: pending
priority: P1
effort: "TBD sau phase 1"
tags: [php, laravel, api, migration]
blockedBy: []
blocks: []
created: 2026-09-24
---

# Kế hoạch chuyển KhoHang_API sang PHP

## Outcome

`KhoHang_API` chạy bằng PHP, phục vụ đầy đủ các luồng hiện có cho `UI_Desktop`: xác thực và phân quyền, dữ liệu kho, nhập/xuất và FIFO, báo cáo, xuất tệp, AI/chatbot, chat thời gian thực, upload và cấu hình người dùng. Bản chuyển đổi có thể kiểm chứng và có phương án quay lại FastAPI.

## Constraints và non-goals

- Chỉ lập kế hoạch trong phiên này; các phase dưới đây chưa được thực hiện.
- Giữ React/Tauri và hợp đồng HTTP/WebSocket hiện hữu làm chuẩn. Chỉ sửa client khi cần thay địa chỉ máy chủ WebSocket, loại bỏ URL API viết cứng, hoặc xử lý sai lệch bảo vệ passkey đã được xác minh.
- Giữ hành vi FIFO quan sát được khi chuyển ngôn ngữ; việc sửa cách tính giá vốn là thay đổi nghiệp vụ riêng theo quyết định người dùng.
- Giữ SQLite và dữ liệu hiện có; `database.py` hiện ép SQLite dù README nêu PostgreSQL. Không thêm chuyển đổi PostgreSQL nếu chưa có yêu cầu riêng.
- Trước bất kỳ thay đổi schema/dữ liệu nào: sao lưu DB và uploads, thử phục hồi trên bản sao, rồi mới chạy migration. Không dùng dữ liệu thật để thử nghiệm.
- Không xem việc chạy validator hoặc fixture tổng hợp là bằng chứng tương đương dữ liệu thật, quyền truy cập provider, hay vận hành production.

## Quyết định kiến trúc đề xuất

Laravel 13 (PHP 8.3+) cho HTTP, xác thực, migration và nghiệp vụ; Workerman PHP cho giao thức WebSocket JSON `/ws/rt` hiện tại. Xây tạm dưới `KhoHang_API/php/`, sau khi đạt parity mới chuyển vào `KhoHang_API/` và thay launcher. Đây là lựa chọn mặc định để hoàn tất kế hoạch; xác nhận framework ở đầu phase 1 nếu người dùng muốn lựa chọn khác.

Laravel Reverb dùng giao thức Pusher nên sẽ đòi hỏi đổi client và luồng chat nhiều hơn. PHP thuần giảm phụ thuộc framework nhưng tăng mã cần tự duy trì cho 83 route và 18 bảng. Workerman có thể phát triển trên Windows; bản vận hành lâu dài cần kiểm chứng môi trường Linux hoặc giới hạn Windows đã nêu trong tài liệu của Workerman.

## Phases

| Phase | Nội dung | Phụ thuộc | Trạng thái |
|---|---|---|---|
| 1 | [Chốt hợp đồng và baseline](./phase-01-contract-baseline.md) | Không | Pending |
| 2 | [Nền Laravel và dữ liệu](./phase-02-database-and-laravel-foundation.md) | 1 | Pending |
| 3 | [Danh tính và quyền truy cập](./phase-03-identity-and-access.md) | 2 | Pending |
| 4 | [Danh mục và kho](./phase-04-catalog-and-warehouses.md) | 2, 3 | Pending |
| 5 | [Nhập xuất, FIFO và báo cáo](./phase-05-inventory-and-reports.md) | 2, 3, 4 | Pending |
| 6 | [Xuất tệp, AI và chatbot](./phase-06-exports-and-ai.md) | 2, 3, 4 | Pending |
| 7 | [Chat thời gian thực](./phase-07-realtime-chat.md) | 2, 3 | Pending |
| 8 | [Tích hợp client và chuyển đổi](./phase-08-client-cutover-and-verification.md) | 3-7 | Pending |

## Dependencies

- Quyền truy cập bản sao dữ liệu vận hành và uploads đã được phép dùng; nếu không có, kiểm thử dữ liệu thật giữ trạng thái pending.
- PHP 8.3+, Composer, extension cần cho SQLite, ảnh và xuất tệp; môi trường chạy HTTP và WebSocket PHP.
- SMTP cho OTP và Gemini API cho AI chỉ khi kiểm thử tích hợp tương ứng được cho phép; thiếu quyền truy cập không được ghi là đã xác minh.

## Acceptance criteria

- [ ] Ma trận route, JSON/status, quyền, tệp và WebSocket đối chiếu với mã nguồn và client; mọi khác biệt được chấp nhận rõ.
- [ ] 18 bảng và dữ liệu được chuyển trên bản sao với kiểm tra số lượng, khóa ngoại, JSON, thời gian, mật khẩu/OTP và file path; phục hồi được từ backup.
- [ ] Các luồng nhập/xuất/hủy không tạo tồn âm hoặc nhân đôi giao dịch; FIFO và báo cáo khớp hành vi hiện tại, với sai lệch FIFO sẵn có được ghi nhận cho thay đổi riêng.
- [ ] React/Tauri chạy các luồng chính và chat bằng backend PHP; HTTP, WebSocket, export, upload và lỗi đều có kiểm thử hợp đồng.
- [ ] Quy trình khởi động, cutover và rollback được thử trên môi trường tương đương; chỉ kết luận production sau khi các cổng provider, dữ liệu và vận hành có bằng chứng.

## Nguồn

- Mốc khảo sát: commit `7499d982e49b1b64c5848d568019c33a82467bbc` từ [repo gốc](https://github.com/siinn1706/NT106_QuanLyKho).
- [Laravel 13 release notes](https://laravel.com/framework/docs/releases), [Laravel migrations](https://laravel.com/framework/docs/13.x/migrations), [Workerman requirements](https://manual.workerman.net/doc/en/install/requirement.html).

## Validation log

- Đã xác nhận: repo mới tên `siinn1706/IS207`, `Public`, xuất bản dưới dạng snapshot đã dọn dữ liệu upload/thông tin admin mẫu; không đẩy lịch sử gốc.
- Đã xác nhận: giữ FIFO hiện tại trong lần chuyển PHP; sửa giá vốn bằng thay đổi riêng.
- Kiểm chứng nguồn: 83 route HTTP, 1 WebSocket, 18 bảng; SQLite bị ép trong `database.py`; client có URL API viết cứng và kiểm tra passkey `123456` ở UI trong khi route sửa/xóa kho chỉ yêu cầu auth. Hai điểm cuối phải được nhận diện là sai lệch hiện có, không tuyên bố là bảo vệ backend.
- Kiểm tra cấu trúc: `ak plan validate` và `ak plan parse` đều pass; mọi liên kết tương đối trong kế hoạch đã được kiểm tra tồn tại.

## Câu hỏi còn mở

- Người dùng chưa xác nhận framework PHP; Laravel 13 là mặc định đề xuất. Phase 1 chốt lựa chọn trước khi tạo mã PHP.

<!-- slug: migrate-khohang-api-to-php -->
