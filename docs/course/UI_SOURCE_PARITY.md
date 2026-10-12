# Đối chiếu giao diện và luồng với BeautyBook gốc

> Có đợt đối chiếu mới ngày 11/10/2026 tại [PARITY_PERFORMANCE_AUDIT_2026-10-11.md](PARITY_PERFORMANCE_AUDIT_2026-10-11.md). Các số liệu bên dưới giữ làm chứng cứ phiên 07/10, không đại diện cho trạng thái hiện tại.

Cập nhật trong phiên 07/10/2026. BeautyBookPHP là đồ án phụ cho môn Lập trình mã nguồn mở: backend Laravel/Eloquent/MySQL, Blade nạp frontend React BeautyBook.

## Frontend hiện tại

Frontend tại `../beauty-booking-api-main/beauty-booking-web-main/beauty-booking-web-main` được sao chép vào `resources/beautybook`; nguồn chỉ được đọc. `/beautybook` chạy bản React này; `/` chuyển tới cùng giao diện. Các view Blade cũ vẫn phục vụ route Laravel cũ, không còn là căn cứ mô tả giao diện chính.

React Router, Zustand, Lucide, Recharts, react-hot-toast, date-fns và socket.io-client được giữ trong bản sao và dependency. Khác ngôn ngữ backend không còn là lý do để giản lược giao diện.

| Phần đối chiếu | Bằng chứng hiện có |
| --- | --- |
| Component, trang, style/state | [Đối chiếu file](../../resources/beautybook/source-verification.json): 169 file, 164 giống byte, 5 điểm tích hợp, không có thay đổi ngoài danh sách |
| Ảnh nguồn | [Đối chiếu asset](../../resources/beautybook/asset-verification.json): 432 asset giống byte; ảnh minh họa giữ `illustration: true` |
| API FE đang gọi | [Inventory AST](../../resources/beautybook/api-contract-audit.json): 197 operation đang hoạt động có route tương ứng; chỉ kiểm tra sự hiện diện, không chứng minh mọi hành vi đúng |
| Trang/workspace | Giữ trang công khai/chi tiết, đăng ký khách/chủ doanh nghiệp, dashboard/chart, drawer/modal, onboarding 13 bước, quản lý dịch vụ/nhân viên từ nguồn |
| Đặt lịch | Giữ chi nhánh/dịch vụ/combo, recurring, chuyên viên/AUTO, giờ trống, thông tin/consent và journal phục hồi request trong wizard nguồn |
| Scheduler | Giữ ngày/tuần/tháng, bộ lọc/drawer, move/resize, phân công, giờ thực tế/lịch sử; Laravel có adapter cho các thao tác |
| Realtime | `realtime/server.mjs` dùng Socket.IO, kiểm tra phiên/quyền qua Laravel và chuyển sự kiện; backend nghiệp vụ vẫn là Laravel |

Năm điểm tích hợp: `apiClient.js`, `App.jsx`, `PublicShell.jsx`, `BookingConfirm.jsx`, `theme.css` — URL API/session-CSRF, base path, liên kết shell, payload confirmation và asset/font cục bộ. Số file giống byte không phải phần trăm giống sản phẩm.

## Backend và dữ liệu

Controller `app/Http/Controllers/Beautybook` trả DTO camelCase và ID chuỗi. Scope lấy từ assignment trên server; header doanh nghiệp/chi nhánh chỉ chọn ngữ cảnh. Các thao tác tái sử dụng service có validation, transaction, khóa, snapshot/idempotency; không proxy sang NestJS.

Ghi chú cũ về AUTO cap 10, combo/recurring ở màn hình riêng, thiếu socket/move/resize hoặc chỉ lưu price rules đã hết phù hợp với API/giao diện chính. `CartBookingService`, `AvailabilityService`, `ServicePriceRules` xử lý AUTO, timeline/buffer nguồn, ngày đóng/ngày đặc biệt, policy, overbooking có kiểm soát và rule giá theo điều kiện. Validation booking nguồn **không kiểm tra ca làm riêng của nhân viên**; không tự đưa điều kiện đó thành khoảng thiếu so với nguồn.

Fixture `database/data/beautybook-source-reference.json` và `BeautybookSourceReferenceSeeder` bổ sung **5 doanh nghiệp, 11 chi nhánh, 116 dịch vụ** từ tên/địa chỉ/UUID entity plan và template seed. Ánh xạ 132 entity giúp ID Laravel dùng đúng ảnh minh họa. Seeder giữ dữ liệu demo hiện có và các chỉnh sửa sau import.

Đây không phải export database BeautyBook. Giá/thời lượng là `TEMPLATE_BASE_PRICE`; không sao chép jitter giá, rating, booking, lịch sử thanh toán hoặc danh tính nhân viên sinh ngẫu nhiên. Nhân viên dùng mẫu cố định `Demo Nhân viên` trong seed nguồn. Provenance chứa hash seed/entity plan; ảnh minh họa không được gán thành ảnh thực tế.

## Kiểm chứng và giới hạn kết luận

- 108 test FE đã đạt trong phiên; kiểm tra logic frontend nguồn, không thay thế test tích hợp Laravel.
- [Browser cuối](evidence/2026-10-07/source-parity/browser-pages.json): 60 trang đạt, không lỗi API/JavaScript/ảnh/redirect. [Chi tiết nội bộ](evidence/2026-10-07/source-parity/browser-internal-details.json): 7 trang đạt, số liệu chủ/doanh nghiệp từ database thật. Các timeout request cũ đã được phân biệt bằng document epoch và log nginx499.
- [Tương tác khám phá](evidence/2026-10-07/source-parity/explore-interactions.json) đạt: phân trang 12→24 không trùng ID, query/canonical/location đúng API. [Wizard](evidence/2026-10-07/source-parity/booking-wizard.json) hoàn thành đủ năm bước, lưu contact/consent, replay/reload cùng lịch và hủy lịch vừa tạo.
- [Responsive](evidence/2026-10-07/source-parity/responsive/results.json): 35 lượt tại 320/375/414/768/1440px, không có tràn ngang/lỗi JavaScript trong các lượt đã ghi. Đây không phải so ảnh từng pixel.
- Các test API riêng kiểm tra identity/public, booking/finance, management, governance/realtime, voucher và importer. [Full suite](evidence/2026-10-07/source-parity/full-suite.txt): **277 đạt / 2.901 assertions**; log 197 test trong `ui-parity` là mốc Blade cũ.

Không quy đổi các số trên thành tỷ lệ giống sản phẩm. Chưa có database/runtime BeautyBook gốc để so cùng dữ liệu/trạng thái hoặc ảnh từng pixel; API gốc mặc định localhost:3000 không chạy khi kiểm tra. Transport email/SMS/push bên ngoài và privacy erasure/rectification nguồn chưa hoàn thiện được ghi riêng; không suy ra đã hoàn tất từ route/tên file. Readiness card admin lặp nhãn cho scalar cũng tồn tại trong component nguồn, được giữ nguyên.

