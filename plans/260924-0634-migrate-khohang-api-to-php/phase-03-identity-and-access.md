---
phase: 3
title: "Danh tính và quyền truy cập"
status: pending
priority: P1
effort: "TBD"
dependencies: [2]
---

# Phase 3: Danh tính và quyền truy cập

## Goal

Port xác thực, người dùng và kiểm soát quyền mà không làm sai hợp đồng token/OTP/client.

## Requirements

- Giữ các route `/auth/*`, `/users/*`, `/user/preferences`; Bearer JWT HS256 với `sub`/`exp`, bcrypt hash hiện có và các response/status trong ma trận phase 1.
- OTP email có hash, hạn dùng, số lần thử và consumed state; SMTP là cổng tích hợp thật, không giả lập thành đã gửi thành công.
- Giữ quy tắc role `admin/manager/staff`, quyền theo route thực tế; passkey dùng `X-Passkey` hoặc query legacy hiện có, tránh ghi passkey vào log. Quyết định thay query cần đổi hợp đồng riêng.

## Files / architecture

- Read: [auth routes](../../KhoHang_API/app/auth_routes.py), [user routes](../../KhoHang_API/app/user_routes.py), [security](../../KhoHang_API/app/security.py), [middleware](../../KhoHang_API/app/auth_middleware.py), [role rules](../../KhoHang_API/app/rbac.py), [client auth](../../UI_Desktop/src/app/auth_service.ts).
- Create during execution: `KhoHang_API/php/app/Http/Controllers/AuthController.php`, `UserController.php`, `KhoHang_API/php/app/Http/Middleware/`, `KhoHang_API/php/app/Services/OneTimeCodeService.php`, `KhoHang_API/php/tests/Feature/AuthContractTest.php`.

## Implementation steps

1. Build explicit serializers, validation and token middleware matching legacy payloads, not Laravel's default session response shape. Test whether an unexpired legacy token can remain valid with the same secret; otherwise plan forced re-login and client handling.
2. Port registration, login, password reset, passkey OTP, profile/avatar and preference flows. Verify existing bcrypt hashes without resetting passwords.
3. Derive route-by-route authorization tests from phase 1, including unauthenticated and wrong-role cases. Do not infer enforcement from comments alone.
4. Add rate limits for OTP/login and ensure secrets, JWT and query passkey never appear in application logs; preserve expected client error messages where relied on.

## Todo

- [ ] Auth contract and RBAC matrix pass.
- [ ] Legacy hashes, OTP state and JWT behavior pass on copied data.
- [ ] SMTP delivery separately verified only when credentials are provided.

## Success Criteria

`php artisan test --filter=AuthContractTest` passes. React auth flows work against PHP in a local integration run; external email remains explicitly pending until exercised.

## Risk / rollback

Changing JWT secret, token claims or password hash format logs users out. Keep original secret in secure runtime storage; rollback routes/auth service together, not one component alone.
