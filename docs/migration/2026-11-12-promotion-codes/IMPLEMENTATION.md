# Ưu đãi bằng mã — 12/11/2026

Khách có thể nhập mã promotion GlowMe vào ô “Mã ưu đãi hoặc voucher” khi đặt lịch. Mã promotion không yêu cầu được cấp voucher trước. Các voucher cấp riêng hiện có vẫn được kiểm tra quyền sở hữu như trước.

## Hành vi

- Mã được trim, viết hoa, dài 3–32 ký tự, bắt đầu bằng chữ/số và chỉ chứa chữ/số/`-`/`_`. Mã promotion duy nhất trong doanh nghiệp; mã của platform có scope riêng. Mã đã xóa vẫn được giữ chỗ như GlowMe.
- Kiểm tra trạng thái, thời hạn `[start, end)`, tenant, chi nhánh, dịch vụ/combo, audience và quota ở cả tính giá và đặt lịch. Snapshot lưu mã đã yêu cầu; promotion không tự áp dụng phải có đúng mã khi giữ lượt.
- `autoApply` độc lập với code như nguồn: promotion có mã chỉ tự áp dụng nếu chủ đã bật cờ này. Mã nhập hợp lệ tham gia so sánh với ưu đãi tự động; nếu chương trình tự động tốt hơn, giữ giá có lợi hơn và thông báo mã vẫn hợp lệ.
- `targetMode=SELECTED` dùng dịch vụ **hoặc** combo, `ALL` dùng toàn bộ lựa chọn trong scope. Giỏ có nhiều dịch vụ chỉ giảm trên phần đủ điều kiện. Quy tắc PHP cũ giữ chế độ `LEGACY` để không tự mở rộng phạm vi.
- Giao diện quản lý cho phép thêm/sửa/xóa code, tìm theo code và hiển thị cách áp dụng. Preview tách `promotionCodeApplied`, `promotionCodeDiscount`, `promotionCodeValid` khỏi ưu đãi tự động và voucher.
- Không cho tạo mã trùng giữa voucher và promotion trong các scope có thể giao nhau. Với dữ liệu cũ đã trùng: ưu tiên promotion hợp lệ, còn promotion không đủ điều kiện phải nhường cho voucher hợp lệ mà khách sở hữu.
- Khóa MySQL theo mã chuẩn hóa dùng chung cho cả hai bảng và mọi scope, trước transaction/khóa business; luôn nhả trong `finally`. Hai ca nhiều tiến trình kiểm tra platform/platform và platform/tenant chỉ cho một chương trình chiếm mã.
- PATCH luôn kiểm tra mode và danh sách đích sau khi gộp dữ liệu, kể cả request không gửi lại `targetMode`.

## Nâng cấp dữ liệu hiện tại

Migration `2026_11_12_030000_support_promotion_codes.php` thêm code, scope unique và target mode; khôi phục dữ liệu từ archive theo ID đã ánh xạ. Không reset database, không nhập lại booking hay thay đổi giá trị/thời hạn nguồn. Guard `code IS NULL` và `target_mode=LEGACY` tránh chạy lại hoặc ghi đè những chương trình đã chỉnh sau nâng cấp.

Đối chiếu thực tế đủ **10/10** mã và activation/autoApply/targetMode khớp nguồn: **8 bật, 2 tắt**. Cả 8 mã bật đều được engine chấp nhận khi kiểm tra đọc trong thời hạn gốc của từng chương trình; **7** nằm trong thời hạn hiện tại. Việc kiểm tra không tạo booking, không đổi ngày và không tiêu hao quota.

## Kiểm tra

Test `PromotionCodeTest` bao phủ nhập mã, không tự áp dụng khi thiếu mã, không cần voucher grant, ưu đãi tự động tốt hơn, sai tenant/chi nhánh/dịch vụ, chưa đến hạn/hết hạn/tắt/xóa, target OR và tính tiền phần hợp lệ, uniqueness và chuẩn hóa, API tạo/sửa/xóa code, preview phân loại giảm giá, reserve/release quota, snapshot mã, voucher collision và backfill chạy lại an toàn.

Kiểm tra kèm các test promotion/voucher, booking API, cart và recurring quote; toàn bộ Node/realtime; build cả Laravel và React. Kết quả chi tiết local nằm trong `storage/logs/coded-promotion-*.log`. Bằng chứng 10 mã thực lưu private tại `storage/app/private/glowme-import/promotion-codes-verification.json`.

Kết quả cuối: **65 test PHP đạt / 545 assertions**, **173 test Node/realtime đạt**, build Laravel và React thành công. Lượt test preview đầu dùng kỳ vọng số thay vì hợp đồng decimal-string hiện có; đã sửa assertion sang chuỗi tiền chính xác, không đổi API để làm test qua.

Phạm vi này bổ sung mã và cách chọn target. Engine PHP vẫn dùng cấu trúc pricing/booking hiện có; không tuyên bố parity toàn bộ cách chọn chương trình theo từng nhóm mua của GlowMe.
