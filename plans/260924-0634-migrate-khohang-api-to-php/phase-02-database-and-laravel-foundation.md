---
phase: 2
title: "Nền Laravel và dữ liệu"
status: pending
priority: P1
effort: "TBD"
dependencies: [1]
---

# Phase 2: Nền Laravel và dữ liệu

## Goal

Tạo backend PHP có schema tương thích và đường chuyển dữ liệu có thể phục hồi.

## Requirements

- Dùng Laravel 13/PHP 8.3+ nếu phase 1 chấp nhận. Giữ root URL hiện có: Laravel `routes/api.php` có prefix mặc định `/api`, cần cấu hình rõ để không nhân đôi prefix như `/api/chatbot/*`.
- Giữ SQLite là baseline thực tế; `app/database.py` ép đường `data/data.db`, còn `app/config.py` và README nói có `DATABASE_URL`. PostgreSQL không thuộc migration này.
- Giữ tên bảng, khóa kiểu chuỗi, FK, JSON, unique `(sender_id, client_message_id)`, time format và đường uploads. Không áp dụng migration trực tiếp lên DB nguồn.

## Files / architecture

- Read: [DB hiện tại](../../KhoHang_API/app/database.py), [mô hình DTO](../../KhoHang_API/app/schemas.py), [schema scripts](../../KhoHang_API/migrate_users_schema.py), [cấu hình](../../KhoHang_API/app/config.py).
- Create during execution: `KhoHang_API/php/composer.json`, `KhoHang_API/php/routes/api.php`, `KhoHang_API/php/database/migrations/`, `KhoHang_API/php/app/Models/`, `KhoHang_API/php/app/Console/Commands/ImportLegacyData.php`, `KhoHang_API/php/tests/Feature/DatabaseCompatibilityTest.php`.
- Keep Python backend runnable during development; final relocation belongs to phase 8.

## Implementation steps

1. Verify PHP, Composer and SQLite extensions; scaffold Laravel without accepting default auth schema that conflicts with legacy `users`.
2. Inventory actual schema on a copied DB, including startup `ALTER TABLE` and unique index. Create ordered migrations and casts against that schema; make blank-DB and legacy-import paths explicit.
3. Back up DB and uploads before any schema/data change; record checksums and demonstrate restore to a second isolated location. A missing live DB is a provider/data gate, not successful migration.
4. Build a dry-run importer or in-place compatibility path with counts, FK checks, JSON parse checks, timestamp comparison, password hash verification and upload path mapping. Fail and leave source untouched on mismatch.
5. Add PHP configuration with secret values supplied at runtime; fail closed on absent JWT secret and ensure `.env` and DB are ignored by Git.

## Todo

- [ ] Blank DB migration and import on disposable copy pass.
- [ ] Backup/restore and reconciliation report pass.
- [ ] Runtime writes stay outside version control.

## Success Criteria

`composer validate` and `php artisan test --filter=DatabaseCompatibilityTest` pass on the generated PHP project; copied DB checks agree with the phase 1 baseline.

## Risk / rollback

SQLite JSON and timestamp semantics differ across ORMs. Preserve a raw DB copy and return to FastAPI if reconciliation fails; do not mutate the only copy.
