# Biên bản ảnh kết quả dịch vụ

Bàn giao ngày 09/11/2026 trên nhánh `docs/audit-plan-2026-10-24`, baseline `0f7d811`. Working tree khi nghiệm thu có 17 file thuộc chặng này. Lượt bàn giao đối chiếu lại HEAD, danh sách file, diff và mốc sửa file với log nghiệm thu. Thay đổi bổ sung của lượt bàn giao là biên bản này.

## Nguồn đã đọc

Hai ZIP `beauty-booking-api-feature-business-branch-registration (1).zip` và `beauty-booking-web-feature-business-branch-registration (1).zip` được mở trực tiếp ở chế độ chỉ đọc. Danh sách gồm các entry đối chiếu khi triển khai và bàn giao.

### ZIP API

- `docs/beautybook-mvp48/API_SCOPE.md (P04-T009/T010)`
- `src/media/result-photos.controller.ts`
- `src/media/result-photos.service.ts`
- `src/media/dto/result-photos.dto.ts`
- `src/media/media-access.ts`
- `src/media/media-content.ts`
- `src/media/media.controller.ts`
- `src/media/media.service.ts`
- `src/services/services.service.ts`
- `prisma/schema.prisma`
- `prisma/migrations/202610160007_media_guards/migration.sql`
- `tests/backend/result-consent.test.cjs`
- `tests/backend/result-publication.test.cjs`

### ZIP Web

- `src/pages/Salon/SalonMedia.jsx`
- `src/components/media/ResourceMediaSection.jsx`
- `src/components/media/ResultPhotoForm.jsx`
- `src/components/media/ResultPhotoPanel.jsx`
- `src/components/media/ResultPhotoDecision.jsx`
- `src/components/media/ResultPhotoList.jsx`
- `src/components/media/ResultConsentForm.jsx`
- `src/pages/Public/PublicDetails.jsx`
- `src/api/apiClient.js`

## Luồng Laravel và React

- Chủ cơ sở đúng phạm vi tạo hồ sơ riêng tư trong tab **Ảnh kết quả** của hộp thoại **Ảnh dịch vụ**. Ảnh và bằng chứng dùng disk `local` và có cờ media `PRIVATE`. Ảnh dùng JPG, PNG hoặc WebP tối đa 8 MiB. Bằng chứng có thể dùng ảnh cùng định dạng hoặc PDF tối đa 10 MiB.
- Với booking, Laravel lấy người đồng ý từ hồ sơ khách của booking và kiểm tra lượt dịch vụ đã mua tại đúng chi nhánh. Booking có thể kèm bằng chứng private. Với người ngoài booking, định danh và bằng chứng private là bắt buộc. Tệp ở phạm vi doanh nghiệp có thể liên kết các chi nhánh trong cùng doanh nghiệp. Tệp ở phạm vi chi nhánh chỉ liên kết trong chi nhánh đó.
- Ghi nhận đồng ý và công khai là hai thao tác riêng. Ghi nhận lưu người thao tác, nguồn, phiên bản đồng ý và thời điểm UTC. Công khai nhận `expectedVersion` dạng số của từng liên kết. Trong transaction, Laravel kiểm lại chủ cơ sở, version, đồng ý, bằng chứng và khả năng hiển thị của mọi dịch vụ dùng chung tệp. Gửi thiếu liên kết hoặc dùng version cũ trả `409`.

## Quyền xem sau thu hồi

Thư viện công khai và `GET /media/{id}` cùng dùng `ResultPhoto::publicMedia()`. Mọi lượt dùng của tệp phải đang công khai, còn đồng ý hợp lệ và thuộc dịch vụ đang hiển thị. API thư viện chỉ đưa ID hồ sơ và URL ảnh. Route nội dung kiểm lại điều kiện sau khi đọc storage và trả `Cache-Control: private, no-store`.

Thu hồi xóa trạng thái công khai và tăng version của tất cả liên kết dùng chung tệp trong một transaction, đồng thời đánh dấu lượt bị thu hồi. Ảnh biến mất khỏi thư viện của các dịch vụ liên quan và URL đã chia sẻ trả `404` ở lần đọc mới. Một lượt đã thu hồi chặn công khai lại cùng tệp. Chủ cơ sở còn quyền và đúng phạm vi vẫn xem được ảnh, định danh và bằng chứng qua route xác thực GET /api/v1/media/{id}/content và màn quản lý. URL công khai của bằng chứng luôn trả `404`.

## Kết quả nghiệm thu

| Kiểm tra | Kết quả |
| --- | --- |
| Hồi quy MySQL | **69/69 test, 617 assertions**. Gồm ca sai quyền, sai chi nhánh, sai lượt đã mua, bằng chứng thiếu hoặc sai phạm vi, version cũ, tệp dùng chung, công khai và thu hồi qua URL, cùng hồi quy P07 và media. |
| React và PHP | **128/128 test JavaScript**, `npm run build:beautybook` và PHP lint đạt. |
| Chrome localhost | Desktop 1440 px đạt luồng ngoài booking. Mobile 375 px đạt luồng từ lượt dịch vụ đã mua. Cả hai xác minh ảnh private trước công khai, thư viện và URL sau công khai, URL bị chặn sau thu hồi và vùng xác nhận nhận focus trong viewport. Có 0 lỗi JavaScript trên trang. |

MySQL test dùng Docker project `beautybook-staff-calendar-test`, MySQL 8.4.13 và PHP 8.4.27. Tiến trình riêng xác minh cấu hình Laravel `testing`, config cache tắt, kết nối PDO đến `mysql_test:3306/beautybook_test` với tài khoản `beautybook_test` và hostname của container test. PHPUnit chạy với bootstrap mặc định cùng guard và khóa `beautybook_test_suite` của repo.

```sh
php vendor/bin/phpunit --do-not-cache-result --colors=never --fail-on-risky --fail-on-warning --filter 'ResultPhotoTest|ReviewPhotoTest|EngagementTest|BeautybookIdentityEngagementApiTest|BeautybookPublicPresentationTest|UploadTest|MediaContentValidationTest|BeautybookManagementApiTest|RegistrationPortTest|TestDatabaseSafetyTest'
```

Lượt đầu phát hiện truy vấn đọc bỏ phần mili giây khi so thời điểm công khai. Bản cuối giữ mili giây và kiểm lại đọc ảnh ngay sau công khai. Kết quả trên lấy từ lượt nghiệm thu. Lượt bàn giao chỉ bổ sung tài liệu.

Bằng chứng cục bộ nằm ở `C:/Users/User/.codex/visualizations/2026/11/08/01a1bb69-6ccf-7eb2-b5cf-c6107ce5798b/`. Log chính gồm `result-mysql-regression-final.log`, `result-react-tests.log`, `result-react-build.log` và `result-browser-results.json`. Runner tương ứng là `result-run-mysql.ps1`, `result-run-preview.ps1` và `result-run-browser.ps1`.

## Migration và hoàn tác

`database/migrations/2026_11_09_010000_create_result_photos_table.php` thêm bảng `result_photos`. Dữ liệu cũ không cần backfill. Khóa ngoại giữ liên kết với dịch vụ, media và dữ liệu nguồn gốc. Ràng buộc unique theo lượt dịch vụ và media chặn liên kết trùng. CHECK bảo vệ hình dạng nguồn gốc và trạng thái công khai. Migration đã chạy trên MySQL test trong lượt nghiệm thu.

Nếu đã có dữ liệu, sao lưu hồ sơ `result_photos`, các bản ghi `RESULT` và `CONSENT_PROOF` trong `media_files` cùng tệp private tương ứng. Giữ cơ chế kiểm soát truy cập mới cho đến khi đã đưa media này ra khỏi phạm vi phục vụ của bản cũ. `down()` chỉ xóa bảng hồ sơ, nên xử lý bản ghi media và tệp riêng theo kế hoạch lưu trữ trước khi quay lui.

## File trong phạm vi nghiệm thu

- `app/Http/Controllers/Beautybook/ManagementMediaController.php`
- `app/Http/Controllers/Beautybook/PublicController.php`
- `app/Http/Controllers/Beautybook/ResultPhotoController.php`
- `app/Http/Controllers/MediaController.php`
- `app/Models/BranchServiceOffering.php`
- `app/Models/MediaFile.php`
- `app/Models/ResultPhoto.php`
- `app/Services/Media/MediaManagementService.php`
- `app/Services/Media/ResultPhotoService.php`
- `database/migrations/2026_11_09_010000_create_result_photos_table.php`
- `resources/beautybook/src/api/apiClient.js`
- `resources/beautybook/src/components/media/ResultPhotoPanel.jsx`
- `resources/beautybook/src/pages/Public/PublicDetails.jsx`
- `resources/beautybook/src/pages/Salon/SalonServices.jsx`
- `routes/beautybook-management.php`
- `routes/beautybook-public.php`
- `tests/Feature/ResultPhotoTest.php`
