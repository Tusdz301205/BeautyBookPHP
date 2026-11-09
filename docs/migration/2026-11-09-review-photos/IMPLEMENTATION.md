# P07 ảnh đính kèm đánh giá của khách

Bàn giao ngày 09/11/2026 trên nhánh `docs/audit-plan-2026-10-24`, baseline `5166ef2`. P06 và P07 cùng có mặt trong working tree được kiểm thử. Đề xuất tách P06 trước, P07 sau. Biên bản này thuộc P07.

## Luồng và hợp đồng

- Khách mở `ReviewModal` từ chi tiết lịch hẹn, danh sách lịch hoặc trang đánh giá. Form cho chọn và gỡ tối đa 5 ảnh JPG, PNG hoặc WebP, mỗi ảnh tối đa 8 MiB. Khi gửi, form tải từng ảnh, giữ lại ID đã nhận để thử lại và đối chiếu trạng thái booking nếu kết quả gửi đánh giá chưa rõ.
- `POST /api/v1/reviews/booking/{booking}/photos` nhận một tệp multipart qua trường `file`. Laravel dùng phiên khách hàng và booking hiện có để kiểm tra đúng chủ, trạng thái `COMPLETED` và chưa có đánh giá. Nội dung, dung lượng và kích thước ảnh được kiểm tra phía server. Thành công trả 201 với `id`, `mediaId` và `contentUrl`. Ảnh tải lên nằm trên disk `local` và có `visibility=PRIVATE`. Trần 20 ảnh chưa gắn mỗi booking giữ theo ZIP API.
- `POST /api/v1/reviews` nhận `photoIds` tùy chọn. Dưới khóa booking, service xác minh lại quyền, trạng thái, ảnh chưa gắn và cùng booking, người tải, doanh nghiệp và chi nhánh. Service lấy khóa các hàng ảnh theo ID, tạo review rồi ghi thứ tự gắn trong cùng transaction. Hàng ảnh và review cùng rollback nếu thao tác gắn thất bại. Ràng buộc một đánh giá mỗi booking giữ hành vi gửi trùng hiện có.
- Review công khai và `GET /media/{id}` dùng `Review::publiclyVisible()`. Review phải `APPROVED` và thuộc booking của doanh nghiệp đủ điều kiện công khai. URL ảnh được kiểm tra lại sau khi đọc storage và trả `Cache-Control: private, no-store`. Ảnh chưa gắn, ảnh của review `REPORTED` hoặc `HIDDEN` trả 404 qua URL công khai. `GET /api/v1/media/{id}/content` dành cho người tải. Chủ doanh nghiệp đúng phạm vi và quản trị viên chỉ đọc ảnh đã gắn.
- Đánh giá mới giữ trạng thái `APPROVED` theo Laravel. Ca chờ xử lý kiểm duyệt dùng `REPORTED`. Gallery dùng các trường `id`, `mediaId`, `contentUrl` và giữ quy tắc ẩn danh hiện có. Phản hồi ảnh không đưa đường dẫn lưu trữ, tên file gốc hoặc người tải. Đánh giá cũ và đánh giá gửi không ảnh trả `photos: []`.

ZIP API đưa ảnh qua kho media của nguồn và công khai theo trạng thái đánh giá ở đó. Laravel giữ cờ media private cả sau khi gắn, cấp quyền công khai qua điều kiện hiển thị review hiện có. Giới hạn PHP trong `docker/laravel/php.ini` là 10M mỗi tệp và 12M mỗi request để Laravel nhận được tệp hợp lệ 8 MiB kèm dữ liệu multipart.

## Entry đã đối chiếu

Hai ZIP `beauty-booking-api-feature-business-branch-registration (1).zip` và `beauty-booking-web-feature-business-branch-registration (1).zip` được mở trực tiếp ở chế độ chỉ đọc. Danh sách dưới đây gồm các entry P07 đã đối chiếu trong lượt triển khai và lượt bàn giao.

### ZIP API

- `docs/beautybook-mvp48/API_SCOPE.md`
- `src/reviews/reviews.controller.ts`
- `src/reviews/review-photos.service.ts`
- `src/reviews/reviews.service.ts`
- `src/reviews/review-reader.service.ts`
- `src/reviews/dto/reviews.dto.ts`
- `src/reviews/dto/review-read.dto.ts`
- `src/media/media-access.ts`
- `src/media/media-content.ts`
- `src/media/media-storage.ts`
- `src/media/media.service.ts`
- `src/common/upload-limits.ts`
- `prisma/schema.prisma`
- `prisma/migrations/202610160006_review_guards/migration.sql`
- `prisma/migrations/202610160007_media_guards/migration.sql`
- `prisma/migrations/20261028000000_review_photos/migration.sql`
- `tests/backend/review-photos-postgres.test.cjs`
- `tests/backend/review-fixture.cjs`
- `tests/backend/review-items.test.cjs`
- `tests/backend/review-reads.test.cjs`

### ZIP Web

- `src/pages/Customer/CustomerBookings.jsx`
- `src/components/reviews/CustomerBookingReview.jsx`
- `src/components/reviews/ReviewPhotoGallery.jsx`
- `src/components/reviews/ReviewList.jsx`
- `src/styles/review-photos.css`
- `src/api/apiClient.js`
- `tests/customer-booking-review.browser.cjs`

## Bằng chứng kiểm thử

### Hồi quy trên working tree P06 và P07

Docker project `beautybook-staff-calendar-test` dùng mạng và volume riêng. Trước mỗi bộ, tiến trình kiểm tra riêng đọc cấu hình Laravel hiệu lực và truy vấn PDO để đối chiếu schema, tài khoản, cổng và hostname với container test. Kết quả xác minh là `testing`, config cache tắt, `mysql_test:3306/beautybook_test`, tài khoản `beautybook_test`, MySQL 8.4.13 và PHP 8.4.27. Hai tiến trình PHPUnit dùng bootstrap mặc định và chạy tuần tự. `Tests\TestCase` kiểm cấu hình và giữ khóa `beautybook_test_suite` trong các test feature.

| Bộ | Kết quả lượt bàn giao |
| --- | --- |
| P06 rộng | **83/83 test, 619 assertions**, 2 phút 17,931 giây |
| P07 | **65/65 test, 509 assertions**, 2 phút 08,080 giây |

Bộ P06 rộng bao gồm ca đổi lịch với mốc `+07:00` sau bản sửa UTC. Bộ P07 bao gồm quyền tải, giới hạn, dữ liệu cũ, ảnh sai booking hoặc trùng ID, rollback khi gắn và quyền đọc URL theo kiểm duyệt. `TestDatabaseSafetyTest` nằm trong cả hai bộ.

Hai tiến trình PHPUnit đều trả mã 0. Sau khi chạy xong cả hai bộ, trình bọc PowerShell/Docker trả 127 do ký tự CR dư ở cuối stdin. Runner `p06-p07-handoff-rerun.ps1` đã lọc CR trước khi đưa dữ liệu vào shell. Kiểm tra lại riêng bước kết nối và mã thoát của runner này đạt, có log `p06-p07-handoff-wrapper-check.log`.

Lệnh PHPUnit chạy bên trong container test

```sh
php vendor/bin/phpunit --do-not-cache-result --colors=never --fail-on-risky --fail-on-warning --filter 'StaffCalendarTest|BookingCreationTest|BookingChangeRequestTest|BeautybookBookingApiTest|CartBookingTest|SchedulingTest|ImpactTest|TestDatabaseSafetyTest'
php vendor/bin/phpunit --do-not-cache-result --colors=never --fail-on-risky --fail-on-warning --filter 'ReviewPhotoTest|EngagementTest|BeautybookIdentityEngagementApiTest|BeautybookPublicPresentationTest|UploadTest|MediaContentValidationTest|BeautybookManagementApiTest|RegistrationPortTest|TestDatabaseSafetyTest'
```

### Bằng chứng từ lượt triển khai P07

- `node --test` chạy trên các file `*.test.js` trong React, đạt 128/128. `npm run build:beautybook` thành công, có cảnh báo font và dependency đã ghi nhận.
- Chrome localhost với MySQL test đã chạy luồng chọn, gỡ, báo lỗi, gửi lại và xem ảnh ở desktop 1440 px và mobile 375 px. Đã gửi JPG, PNG, WebP và một PNG lớn hơn 2 MiB. Cả hai lượt có 0 lỗi JavaScript.
- Fixture ẩn danh ở lượt đầu còn dùng chính sách mặc định tắt ẩn danh. Lượt cuối bật chính sách trong riêng test và kiểm được toàn bộ luồng. Các kiểm tra JavaScript, build và Chrome ở mục này là bằng chứng từ lượt triển khai.

Log cục bộ nằm trong `C:/Users/User/.codex/visualizations/2026/11/08/01a1bb69-6ccf-7eb2-b5cf-c6107ce5798b/`. Lượt bàn giao dùng `p06-p07-handoff-mysql.log` và `p06-p07-handoff-run.ps1`. Lượt triển khai có `p07-mysql-regression-final.log`, `p07-react-node-tests.log`, `p07-react-build.log`, `p07-browser-results.json` và `p07-browser-mobile-results.json`.

## Dữ liệu và hoàn tác

Migration P07 thêm `review_photos` và chỉ mục unique `reviews_id_booking_unique` trên `reviews(id, booking_id)`. Khóa ngoại ghép buộc ảnh và review cùng booking. Mỗi file có tối đa một hàng liên kết. Giới hạn vị trí 0 đến 4 và unique theo review giữ tối đa 5 ảnh đã gắn. Các liên kết ảnh bắt đầu từ dữ liệu phát sinh sau migration.

Nếu quay lui sau khi đã có ảnh, sao lưu hàng liên kết, bản ghi media và tệp private trước. Giữ cơ chế kiểm soát truy cập ảnh P07 cho đến khi media `REVIEW_PHOTO` đã được đưa ra khỏi phạm vi phục vụ của bản cũ. `down()` xóa bảng liên kết và chỉ mục phụ, còn tệp private và bản ghi media cần được xử lý theo kế hoạch lưu trữ. Migration P06 và P07 trong lượt bàn giao chỉ chạy trên MySQL test.

## Hai commit dự kiến

### P06 · feat: enforce staff hours and time off in booking

Các file lấy toàn bộ

- `app/Http/Controllers/Beautybook/StaffCalendarController.php`
- `app/Services/Beautybook/BookingCommands.php`
- `app/Services/Booking/AppointmentChangeRequestService.php`
- `app/Services/Booking/AvailabilityService.php`
- `app/Services/Staff/StaffCalendarService.php`
- `database/migrations/2026_11_06_000000_create_staff_calendar_tables.php`
- `docs/migration/2026-11-06-staff-calendar/IMPLEMENTATION.md`
- `resources/beautybook/src/components/staff/StaffCalendarPanels.jsx`
- `resources/beautybook/src/pages/Salon/StaffDetail.jsx`
- `routes/beautybook-public.php`
- `scripts/browser-staff-calendar.mjs`
- `tests/Feature/ImpactTest.php`
- `tests/Feature/StaffCalendarTest.php`

### P07 · feat: attach customer review photos

Các file lấy toàn bộ

- `app/Http/Controllers/Beautybook/BookingController.php`
- `app/Http/Controllers/Beautybook/EngagementController.php`
- `app/Http/Controllers/Beautybook/ManagementMediaController.php`
- `app/Http/Controllers/Beautybook/PublicController.php`
- `app/Http/Controllers/EngagementController.php`
- `app/Http/Controllers/MediaController.php`
- `app/Models/Booking.php`
- `app/Models/Review.php`
- `app/Models/ReviewPhoto.php`
- `app/Services/Beautybook/BookingPresenter.php`
- `app/Services/Beautybook/PublicPresenter.php`
- `app/Services/Engagement/EngagementService.php`
- `app/Services/Media/MediaManagementService.php`
- `database/migrations/2026_11_09_000000_create_review_photos_table.php`
- `docker/laravel/php.ini`
- `docs/migration/2026-11-09-review-photos/IMPLEMENTATION.md`
- `resources/beautybook/src/components/customer/ReviewModal.jsx`
- `resources/beautybook/src/components/customer/ReviewPhotoGallery.jsx`
- `resources/beautybook/src/pages/Admin/AdminReviewsModeration.jsx`
- `resources/beautybook/src/pages/Customer/CustomerAppointmentDetail.jsx`
- `resources/beautybook/src/pages/Customer/CustomerReviews.jsx`
- `resources/beautybook/src/pages/Public/PublicDetails.jsx`
- `resources/beautybook/src/pages/Salon/SalonReviews.jsx`
- `resources/beautybook/src/styles/public-home.css`
- `routes/beautybook-engagement.php`
- `tests/Feature/ReviewPhotoTest.php`

### Hunk trong hai file dùng chung

Các header dưới đây tính theo diff từ HEAD `5166ef2` đến working tree trước khi stage. Tên hàm và route là điểm đối chiếu sau khi vị trí dòng thay đổi.

| File | P06 | P07 |
| --- | --- | --- |
| `resources/beautybook/src/api/apiClient.js` | `@@ -559,6 +559,15 @@`. Trong `staffApi`, thêm `getWorkingHours`, `saveWorkingHours`, `getTimeOff`, `saveTimeOff` và `removeTimeOff`. | `@@ -740,6 +749,12 @@`. Trong `reviewsApi`, thêm `uploadPhoto`. |
| `routes/beautybook-management.php` | `@@ -1,6 +1,7 @@` và `@@ -63,6 +64,12 @@`. Import `StaffCalendarController` và sáu route quản lý giờ làm/lịch nghỉ. | `@@ -83,4 +90,4 @@`. Đặt tên `beautybook.media.content` cho route `GET media/{id}/content`. |
