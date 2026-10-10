# Phạm vi đồ án BeautyBookPHP

Cập nhật 07/10/2026. BeautyBookPHP là dự án phụ cho môn Lập trình mã nguồn mở, dùng Laravel/PHP, MySQL, Eloquent, Blade entry và middleware. Giao diện React BeautyBook được sao chép vào ứng dụng Laravel và gọi API Laravel; không vận hành backend NestJS nguồn. Nghiệp vụ kế thừa BeautyBook; bản nộp môn học chứa ứng dụng Laravel, frontend phục vụ ứng dụng và tài liệu hiện hành.

## Mục tiêu và công nghệ

Xây dựng nền tảng khám phá và đặt lịch dịch vụ làm đẹp, quản lý salon nhiều chi nhánh, dịch vụ và nhân sự phục vụ lịch hẹn. Tiêu chí trong ảnh là mức tối thiểu; các chức năng mở rộng được giữ để thể hiện nghiệp vụ thực tế.

Runtime đã kiểm tra: PHP 8.4 trong Docker, MySQL 8.4, Laravel theo composer.lock, Blade và Vite. Hướng dẫn cài đặt, seed và kiểm thử ở [README](../../README.md).

## Vai trò và phạm vi dữ liệu

| Vai trò | Chức năng chính |
| --- | --- |
| Customer | Hồ sơ/avatar, tìm dịch vụ, đặt/đổi/hủy lịch theo chính sách, favorite/review, ưu đãi, privacy |
| Business Owner | Quản lý doanh nghiệp, chi nhánh, catalog, staff/capability, ưu đãi, lịch hẹn, tài chính và chuyển chủ |
| Receptionist | Lịch chi nhánh, đặt hộ với contact, check-in/no-show và thu tiền theo quyền |
| Staff | Xem và cập nhật dịch vụ trong lịch được phân công |
| Platform Admin | Xét duyệt, tài khoản/quyền, settings, moderation, trust, audit và báo cáo |

Tài khoản Customer và Operational tách biệt. Workspace, business, branch và self scope được kiểm tra tại middleware/policy/service; không tin customer, price hoặc tenant ID từ trình duyệt.

## Chức năng đã triển khai

- Auth/session, phân quyền năm vai trò, CRUD/validation/search/pagination, onboarding, catalog/variant/offering, staff/capability và media.
- Quote/availability/booking bằng AJAX, giá và thời lượng snapshot, chống trùng lịch và replay, calendar, lifecycle, đổi/hủy/no-show, notification.
- Promotion/voucher, combo, lịch định kỳ, review/moderation, favorite, waitlist và loyalty.
- Thanh toán tiền mặt/chuyển khoản thủ công, verification/reversal, refund, ledger, gói liệu trình/trả góp, invoice và đối soát.
- Ảnh hưởng vận hành, chuyển chủ/legal/payout, privacy export, quản trị/settings/trust/audit/report và maintenance scheduler.

Không đưa trở lại HR/hồ sơ sức khỏe/vai trò đã retire, cổng thanh toán online hoặc phí hủy/no-show ngoài phạm vi đã duyệt. Giới hạn còn lại, gồm xử lý DSR erasure/rectification và transport email/SMS/push, được ghi tại [trạng thái](IMPLEMENTATION_STATUS.md).

## Hồ sơ phục vụ môn học

- [ERD/use case và kịch bản demo](ERD_USE_CASE.md).
- [Đối chiếu tiêu chí 40/25/15/10/10](RUBRIC_TRACEABILITY.md).
- [Trạng thái và giới hạn](IMPLEMENTATION_STATUS.md).
- [Prompt triển khai từ prompts.chat](PORT_IMPLEMENTATION_PROMPT.md).
- [Bằng chứng nghiệm thu](evidence/2026-10-07/FINAL_VALIDATION.md).

Hai cây NestJS/React, tài liệu audit/planning cũ và hồ sơ thực tập/CV đã được loại bỏ theo yêu cầu dọn dự án. Việc dọn thư mục không triển khai, reset hay xóa database đang chạy.
