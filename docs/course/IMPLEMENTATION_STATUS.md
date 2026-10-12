# Trạng thái triển khai BeautyBookPHP

> Trạng thái hiện tại sau khi cắt luồng phụ và tối ưu local ngày 11/10/2026 được ghi tại [PARITY_PERFORMANCE_AUDIT_2026-10-11.md](PARITY_PERFORMANCE_AUDIT_2026-10-11.md). Báo cáo dưới đây là mốc lịch sử 07/10.

Cập nhật trong phiên 07/10/2026. Backend Laravel tại root (`artisan`, `app`, `routes`, `database`). Frontend chính là React nguồn trong `resources/beautybook`, nạp bằng Blade tại `/beautybook`; `/` chuyển tới giao diện này.

## Những phần đã triển khai

| Nhóm | Triển khai hiện hành |
| --- | --- |
| Danh tính/quyền | Session/CSRF, năm vai trò, assignment kiểm tra trên server, tách khách/vận hành, hồ sơ/bảo mật/thu hồi phiên |
| Công khai/quản lý | Tìm kiếm/lọc/phân trang, chi tiết cơ sở/dịch vụ/chuyên viên, owner registration, onboarding 13 bước/readiness, catalog/variant/category, nhân viên/lời mời/nghỉ việc, tài liệu phiên bản/upload |
| Đặt lịch | Wizard nguồn dịch vụ/combo/recurring/AUTO, contact-consent, quote/slots, idempotency/snapshot, trạng thái/yêu cầu đổi lịch |
| Scheduler/realtime | UI ngày/tuần/tháng; API phân công/move/resize/actual-time/history; Socket.IO chuyển sự kiện qua kiểm tra quyền Laravel |
| Availability/pricing | Giờ mở cửa, ngày đóng/ngày đặc biệt, capability, policy/buffer, overbooking có kiểm soát, service price rules và ưu đãi/loyalty có quota/version |
| Ưu đãi/khách hàng | Promotion/voucher scope editor, grant theo danh tính có quan hệ tenant, automatic issuance, expiry/status; combo, review/moderation, favorite, waitlist/loyalty |
| Tài chính | Cash/manual bank, xác minh/reversal/refund, ledger, policy/version, package/installment/entitlement, phí/statement/invoice, báo cáo từ dữ liệu thật |
| Vận hành/admin | Impact kiểm tra lại lịch ảnh hưởng, ownership/legal/payout versions, trust restriction/suspension, privacy preferences/request/export, settings/audit/report/maintenance |

Avatar qua adapter source lưu riêng tư, chỉ chủ tài khoản đọc; JPEG/PNG/WebP/AVIF được kiểm tra nội dung. Review ẩn danh không lộ danh tính/avatar. Hạn chế/đình chỉ toàn doanh nghiệp theo trust đã được người dùng cho phép; luồng phải kiểm tra/xử lý ảnh hưởng trên các chi nhánh trước khi chuyển trạng thái.

Không dùng các mô tả cũ “FE chỉ có Blade”, “AUTO tối đa 10”, “combo/recurring chưa tích hợp”, “chưa có socket” hoặc “price rules mới có CRUD” cho giao diện/API chính. Validation booking nguồn không kiểm tra ca làm riêng; không coi đó là yêu cầu port thiếu.

## Dữ liệu và chứng cứ

Bản sao FE: 169 file đối chiếu, 164 giống byte, 5 điểm tích hợp; 432 asset giống byte. Inventory AST: 197 operation đang hoạt động có route tương ứng, chỉ chứng minh sự hiện diện. Chi tiết ở [UI_SOURCE_PARITY.md](UI_SOURCE_PARITY.md).

Seeder tham chiếu bổ sung 5 doanh nghiệp/11 chi nhánh/116 dịch vụ và ánh xạ minh họa từ entity plan nguồn, giữ demo hiện có. Giá/thời lượng là template base, không phải giá ngẫu nhiên/lịch sử database gốc. Nhân viên dùng mẫu demo cố định; không bịa booking/rating/thanh toán nguồn. Xem import trong [README](../../README.md).

Trong phiên: 108 test FE đạt, 35 lượt responsive đạt, browser cuối 60 trang và 7 chi tiết nội bộ đạt. Tương tác phân trang/tìm kiếm và wizard đủ năm bước với contact/consent, replay/reload/hủy lịch thật đều đạt. Nhóm hồi quy cuối owner/context và DTO đạt 22 test/220 assertions. **Full suite MySQL: 277 đạt / 2.901 assertions**; log 197 test Blade cũ không phải kết quả mới. Nhãn giá thập phân được kiểm thử riêng sau suite toàn bộ.

## Phạm vi chưa được khẳng định

- Không quy đổi file/route/test/trọng số môn học thành phần trăm giống BeautyBook; chưa tuyên bố parity 100% hoặc giống từng pixel.
- Dữ liệu tham chiếu không phải bản sao database nguồn. Laravel/MySQL thay NestJS/PostgreSQL; schema/route nội bộ có thể khác.
- Privacy erasure/rectification trọn vẹn vốn chưa hoàn thiện trong nguồn. Outbox/inbox và Socket.IO không chứng minh email/SMS/push bên ngoài đã gửi thành công.
- Chưa phát hành công khai, Git push hoặc chọn giấy phép thay người dùng.

[Prompt đã áp dụng](PORT_IMPLEMENTATION_PROMPT.md) ghi cách dùng catalog prompts.chat cục bộ. Tiêu chí môn học là mức tối thiểu, không giới hạn chức năng BeautyBook.

