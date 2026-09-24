---
phase: 7
title: "Chat thời gian thực"
status: pending
priority: P1
effort: "TBD"
dependencies: [2, 3]
---

# Phase 7: Chat thời gian thực

## Goal

Giữ API chat và giao thức WebSocket JSON hiện tại trong backend PHP.

## Requirements

- Giữ `/rt/*` và `/ws/rt` hoặc cấu hình client tương đương; frame `{type, reqId, data}`, ACK, sync, read/delivered, edit/delete, reactions, pin, typing, heartbeat và `inventory:updated` theo ma trận phase 1.
- Giữ tính duy nhất `(sender_id, client_message_id)` và kiểm tra thành viên hội thoại trước khi đọc/ghi; JWT trong WS query hiện là contract nhưng phải tránh ghi URL/token vào log.
- Máy chủ WS PHP chạy như tiến trình riêng; cổng và lifecycle rõ. Không mặc định Reverb tương thích vì Reverb nói Pusher protocol.

## Files / architecture

- Read: [WS server cũ](../../KhoHang_API/app/rt_chat_ws.py), [RT routes](../../KhoHang_API/app/rt_chat_routes.py), [WS client](../../UI_Desktop/src/services/rt_ws_client.ts), [RT store](../../UI_Desktop/src/state/rt_chat_store.ts), [DB realtime](../../KhoHang_API/app/database.py).
- Create during execution: `KhoHang_API/php/bin/ws-server.php`, `KhoHang_API/php/app/Realtime/`, `KhoHang_API/php/app/Http/Controllers/RealtimeChatController.php`, `KhoHang_API/php/tests/Feature/RealtimeChatContractTest.php` and WS protocol tests.

## Implementation steps

1. Dùng Workerman để nhận WebSocket raw frame; xác thực JWT khi kết nối, kiểm tra user còn hợp lệ và quyền từng hội thoại. Dùng services PHP chung cho HTTP và WS để tránh hai logic ghi DB.
2. Port từng nhóm sự kiện từ ma trận: join/sync, send/idempotency/ack, delivered/read, edit/delete/reaction/pin, typing/presence, heartbeat, lỗi. Kiểm tra thứ tự ACK và event sau commit.
3. Port HTTP `/rt/*`, upload tệp và lookup user. Kết nối event kho từ phase 5 tới WS worker qua cơ chế chỉ rõ; nếu đa tiến trình cần shared bus, phải thử trước khi mở rộng.
4. Chạy test hai client đồng thời, reconnect và gửi lại cùng `clientMessageId`; verify không nhân đôi message, không lộ hội thoại khác và cleanup kết nối.

## Todo

- [ ] Mọi event dùng bởi client có test protocol.
- [ ] Hai client, reconnect và idempotency pass.
- [ ] Quy trình start/stop WS trên Windows dev và môi trường đích được ghi rõ.

## Success Criteria

`php artisan test --filter=RealtimeChatContractTest` và WS protocol suite pass; UI chat hai người hoạt động với backend PHP, không cần đổi định dạng message.

## Risk / rollback

Workerman trên Windows có giới hạn về nhiều worker và daemon. Trước cutover, xác minh cổng, proxy/TLS, supervisor và môi trường chạy; giữ endpoint Python làm đường quay lại.
