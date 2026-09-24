---
phase: 6
title: "Xuất tệp, AI và chatbot"
status: pending
priority: P2
effort: "TBD"
dependencies: [2, 3, 4]
---

# Phase 6: Xuất tệp, AI và chatbot

## Goal

Port nội dung tệp và các luồng AI/chatbot mà client đang sử dụng.

## Requirements

- Giữ `/export/excel`, `/export/pdf`, template phiếu, tên tệp, `Content-Type`/`Content-Disposition`, định dạng số/ngày/tiếng Việt; không thay báo cáo bằng một file giả.
- Giữ `/ai/chat`, variant markdown và `/api/chatbot/*`; Gemini không cấu hình phải trả lỗi theo hợp đồng, quota/rate lỗi được phân biệt với phản hồi thật.
- Giữ chat bot lịch sử, reaction, preference liên quan và file/avatar upload; xác thực và kiểm tra loại/kích thước file theo hành vi đã xác minh.

## Files / architecture

- Read: [export service](../../KhoHang_API/app/export_service.py), [templates](../../KhoHang_API/templates/), [AI client](../../KhoHang_API/app/gemini_client.py), [chatbot routes](../../KhoHang_API/app/chatbot_routes.py), [chat routes](../../KhoHang_API/app/main.py), [frontend export](../../UI_Desktop/src/app/exportApi.ts).
- Create during execution: `KhoHang_API/php/app/Services/VoucherExportService.php`, `GeminiChatService.php`, `KhoHang_API/php/app/Http/Controllers/ExportController.php`, `ChatbotController.php`, feature tests with binary artifact comparisons.

## Implementation steps

1. Chọn thư viện PHP có thể tái tạo mẫu Excel/PDF hiện có; kiểm tra giấy phép, font tiếng Việt và binary đầu ra. Giữ template nguồn, không thay bố cục nếu chưa được duyệt.
2. Port DTO, chuyển số thành chữ, response headers, file naming và điều kiện lỗi; so sánh nội dung/tổng tiền/tệp mở được, không dựa vào hash binary nếu metadata tệp thay đổi.
3. Port Gemini request/response và xử lý quota bằng HTTP client PHP; test parser với fixture tĩnh, test provider thật chỉ khi có API key/quota được phép.
4. Port cấu hình chatbot, avatar/file upload, chat message/reaction và đường phục vụ tệp; kiểm tra path traversal, MIME và quyền truy cập.

## Todo

- [ ] Excel/PDF mở được và khớp dữ liệu phiếu.
- [ ] AI/chatbot API và upload khớp hợp đồng.
- [ ] Provider test và license được ghi là pass hoặc pending với bằng chứng.

## Success Criteria

Feature tests và so sánh tệp pass; UI tải/mở được Excel/PDF và chatbot hoạt động trong môi trường có cấu hình hợp lệ.

## Risk / rollback

Khác biệt font PDF và API Gemini có thể làm lệch output. Giữ backend cũ phục vụ cho tới khi artifact và lỗi được đối chiếu; không dùng reply giả để che provider failure.
