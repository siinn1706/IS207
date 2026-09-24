---
phase: 8
title: "Tích hợp client và chuyển đổi"
status: pending
priority: P1
effort: "TBD"
dependencies: [3, 4, 5, 6, 7]
---

# Phase 8: Tích hợp client và chuyển đổi

## Goal

Chuyển React/Tauri và launcher sang backend PHP sau khi parity được chứng minh, với rollback đã thử.

## Requirements

- Phạm vi client chỉ gồm cấu hình URL API/WS và sửa chỗ viết cứng `http://localhost:8000` nếu cản cutover; không thiết kế lại UI.
- Giữ đường HTTP đang dùng và WS frame; hỗ trợ server WS riêng qua cấu hình như `VITE_WS_URL` khi môi trường dev không proxy được cùng cổng.
- Không xóa FastAPI hoặc data cũ trước khi full matrix, test và phục hồi từ backup pass. Sau khi đạt gate mới thay launcher, tài liệu chạy và code Python theo thứ tự có thể hoàn nguyên.

## Files / architecture

- Read: [API client](../../UI_Desktop/src/app/api_client.ts), [auth service](../../UI_Desktop/src/app/auth_service.ts), [WS client](../../UI_Desktop/src/services/rt_ws_client.ts), [password screen](../../UI_Desktop/src/features/auth/Change_Password_Page.tsx), [launcher](../../start_backend.bat), [README](../../README.md).
- Modify during execution: `UI_Desktop/.env.example`, relevant URL consumers, `start_backend.bat`, `start_app.bat`, root `README.md` and the smallest owning docs under `docs/`. Relocate `KhoHang_API/php/` to final PHP root only after tests pass; then remove obsolete Python files via reviewed diff.

## Implementation steps

1. Run full PHP feature/protocol suite plus `composer validate`, `php artisan migrate:status` on copy, and `npm run build` in `UI_Desktop`. The package has no `lint` script, so do not claim one ran.
2. Run React web and Tauri desktop flows end to end: auth, roles, catalog, warehouse, stock in/out/cancel, reports, export, Gemini when allowed, bot, two-client chat, uploads and preferences. Compare status/body/headers/events with baseline.
3. Create cutover checklist with frozen DB/asset backup, checksum, read-only window or write quiescence, import/reconciliation, PHP HTTP+WS start, health checks and exact rollback steps. Test rollback on a copy.
4. Replace launcher and docs, move PHP into the `KhoHang_API` root, retire Python only after signed-off parity. Keep source commit/tag and backup reference for rollback. Review final diff and secret scan before shipping.

## Todo

- [ ] PHP unit/feature/WS suites and frontend build pass.
- [ ] Web/Tauri E2E and artifact comparisons pass.
- [ ] Data migration, provider gates and rollback test have evidence.
- [ ] Final diff contains no secrets, DB, uploads or generated cache.

## Success Criteria

PHP serves every in-scope client path and the old backend can be restored within the documented procedure. Production readiness remains pending until real data and provider/environment gates pass.

## Risk / rollback

Stale desktop API URL, token incompatibility and WS proxy mismatch can break cutover. Retain old start path, a verified DB/asset backup and a tested routing reversal; stop on any parity mismatch.
