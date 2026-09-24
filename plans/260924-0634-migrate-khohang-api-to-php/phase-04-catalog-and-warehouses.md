---
phase: 4
title: "Danh mục và kho"
status: pending
priority: P1
effort: "TBD"
dependencies: [2, 3]
---

# Phase 4: Danh mục và kho

## Goal

Port dữ liệu nền và các phép tìm kiếm mà UI đang gọi.

## Requirements

- `/suppliers`, `/items`, `/warehouses`, `/company`, `/search/global`, `/dashboard/stats` và các route thống kê danh mục giữ filter, phân trang tùy chọn, thứ tự, JSON shape và quyền theo ma trận.
- Preserve SKU/warehouse code uniqueness, liên kết supplier, kho active, `managers` JSON, low-stock và company logo. Phân biệt số lượng tổng trong `items` với tồn theo kho suy từ giao dịch.
- Theo thay đổi đã chấp thuận, `PUT` và `DELETE /warehouses/{warehouse_id}` phải xác minh passkey phía PHP API sau bước đăng nhập. Giữ các quyền/route kho khác theo ma trận phase 1; không suy rằng kiểm tra `123456` cũ ở UI từng bảo vệ API.

## Files / architecture

- Read: [main routes](../../KhoHang_API/app/main.py), [search](../../KhoHang_API/app/search_service.py), [schema helpers](../../KhoHang_API/app/db_helpers.py), [client API](../../UI_Desktop/src/app/api_client.ts), [warehouse screen](../../UI_Desktop/src/features/warehouses/Warehouse_Page.tsx).
- Create during execution: `KhoHang_API/php/app/Http/Controllers/ItemController.php`, `SupplierController.php`, `WarehouseController.php`, `CompanyController.php`, `SearchController.php`; feature tests per resource.

## Implementation steps

1. Implement DTO/resources for list and detail responses. Pay attention to legacy behavior that returns an array without `page` but an envelope with `data/page/page_size/total` when pagination is requested.
2. Port CRUD validation và unique errors, active-warehouse state, warehouse inventory projection và supplier transaction history. Gắn middleware passkey của phase 3 vào đúng hai route sửa/xóa kho, đọc `X-Passkey` và kiểm tra hash của người dùng hiện tại.
3. Port global search, autocomplete, dashboard and item alert/trend/category statistics using the same filters and result keys.
4. Port image/logo upload validation and `/uploads/*`/`/download/*` paths with safe filenames and authenticated access where current contract requires it.

## Todo

- [ ] CRUD and pagination parity tests pass.
- [ ] Sửa/xóa kho: thiếu đăng nhập, thiếu/sai passkey bị từ chối; passkey đúng cho người dùng hợp lệ được xử lý; các route kho khác giữ quyền hiện tại.
- [ ] Search/statistics match baseline on copied fixtures.
- [ ] Uploads resolve from migrated paths.

## Success Criteria

Feature tests for catalog, warehouses and search pass; UI pages load against PHP without new data-shape adapters, và hai thao tác sửa/xóa kho không thể vượt qua kiểm tra passkey bằng cách gọi API trực tiếp.

## Risk / rollback

DB-backed unique errors and SQLite case-insensitive search can differ from Python/SQLAlchemy. Keep fixture comparisons and stop before client cutover on unexplained differences.
