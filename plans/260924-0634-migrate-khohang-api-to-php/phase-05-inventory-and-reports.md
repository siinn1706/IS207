---
phase: 5
title: "Nhập xuất, FIFO và báo cáo"
status: pending
priority: P1
effort: "TBD"
dependencies: [2, 3, 4]
---

# Phase 5: Nhập xuất, FIFO và báo cáo

## Goal

Port nghiệp vụ kho và báo cáo với ranh giới giao dịch rõ và kết quả tài chính đối chiếu được.

## Requirements

- Giữ `/stock/transactions`, `/stock/in`, `/stock/out`, hủy phiếu, `/reports/*`, `/dashboard/stats`, mã phiếu, status, actor và broadcast `inventory:updated`.
- Nhập/xuất/hủy phải nguyên tử: lock hoặc SQLite write transaction phù hợp, kiểm tra tồn trước khi ghi, commit cùng phiếu/giao dịch/số lượng; phát event chỉ sau commit.
- FIFO hiện tại ở `calculate_fifo_cost` duyệt phiếu nhập theo `created_at` nhưng không trừ xuất trước. Người dùng đã chọn giữ hành vi này trong lần chuyển; tài liệu hóa defect và lên thay đổi nghiệp vụ riêng, không âm thầm đổi giá vốn.
- Giá trị tiền hiện là `Float`; đối chiếu dữ liệu thật trước khi chọn cách biểu diễn chính xác hơn để tránh thay đổi response ngầm.

## Files / architecture

- Read: [inventory service](../../KhoHang_API/app/inventory_service.py), [route kho và báo cáo](../../KhoHang_API/app/main.py), [stock DTO](../../KhoHang_API/app/schemas.py), [FIFO tests](../../KhoHang_API/test_inventory_valuation.py), [restock tests](../../KhoHang_API/test_restock_logic.py).
- Create during execution: `KhoHang_API/php/app/Services/InventoryService.php`, `FifoValuationService.php`, `KhoHang_API/php/app/Http/Controllers/StockController.php`, `ReportController.php`, `KhoHang_API/php/tests/Feature/InventoryContractTest.php`.

## Implementation steps

1. Chuyển tạo phiếu nhập/xuất và hủy theo một transaction; khóa/bảo vệ cấp phát mã phiếu và tồn khi hai yêu cầu đồng thời. Giữ dấu vết `voucher_id`, `warehouse_code`, `actor_user_id`.
2. Thiết lập bộ đối chiếu FIFO: nhiều lô, xuất nhiều lần, hủy xuất, hủy nhập sau xuất, các kho khác nhau, thiếu lô, tax, rounding. Kỳ vọng của migration là parity với baseline; test nghiệp vụ độc lập ghi lại defect để sửa riêng.
3. Port giá bán override theo ngưỡng hiện hành và audit note; không thay ngưỡng khi chỉ chuyển PHP. Port báo cáo doanh thu/COGS/lợi nhuận và biểu đồ.
4. Phát `inventory:updated` sau commit và kiểm thử không phát khi rollback. Đối chiếu snapshot DB trước/sau mỗi case.

## Todo

- [ ] Test nguyên tử, cạnh tranh và hủy phiếu pass.
- [ ] FIFO/các báo cáo khớp baseline; defect cũ được ghi nhận, không trình bày là đã sửa.
- [ ] Event chỉ phát sau commit.

## Success Criteria

`php artisan test --filter=InventoryContractTest` pass; chạy cùng kịch bản trên backend cũ và mới cho mọi trường hợp được phê duyệt, không còn sai lệch không giải thích.

## Risk / rollback

SQLite không có row lock giống PostgreSQL; thiết kế transaction phải dựa trên hành vi SQLite đã đo. Khi sai tồn hoặc giá vốn, dừng cutover và phục hồi DB bản sao/backup.
