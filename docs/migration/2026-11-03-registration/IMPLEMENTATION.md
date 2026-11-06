# Đợt chuyển tính năng đăng ký — 03/11/2026

## Phạm vi đã triển khai

- Thông tin pháp lý doanh nghiệp: ENTERPRISE/HOUSEHOLD_BUSINESS, tên pháp nhân, số đăng ký và địa chỉ đăng ký; form owner và phần xem hồ sơ admin. Hồ sơ có loại pháp nhân mới phải có đủ thông tin và giấy đăng ký PDF private trước khi submit.
- Loại đăng ký chi nhánh: địa điểm kinh doanh, chi nhánh doanh nghiệp, địa chỉ đã đăng ký. Hộ kinh doanh không được submit kiểu chi nhánh doanh nghiệp. Hồ sơ mới khai báo loại đăng ký cần giấy tờ PDF private đúng chi nhánh.
- Owner gửi/rút yêu cầu đổi địa chỉ/đăng ký; admin có hàng đợi, phê duyệt/từ chối với version. Pending giữ nguyên dữ liệu công khai; mỗi branch chỉ có một pending, có constraint MySQL và transaction/lock.
- Khi approve: kiểm tra lại scope, trạng thái, PDF, snapshot có bị thay đổi và lịch hẹn đang hiệu lực. Lưu phiên bản giấy tờ mới, audit và database notification/outbox trong cùng transaction.
- Chặn PATCH/onboarding thay địa chỉ trực tiếp đối với branch đã duyệt. Thông tin liên hệ và cấu hình vận hành vẫn cập nhật qua luồng cũ.
- Booking mới lưu snapshot địa chỉ. Trước một lần chuyển địa chỉ, booking cũ chưa có snapshot nhận bản chụp địa chỉ quan sát được tại thời điểm đổi, gắn `basis=LEGACY_CURRENT_AT_CHANGE`; đây không phải khẳng định đã biết địa chỉ lịch sử ban đầu.
- Test harness có guard cấu hình trước RefreshDatabase và connection lease tồn tại suốt lượt chạy, kể cả DDL/app refresh.

## Giữ tương thích

Migration bổ sung nullable fields và bảng mới, không đổi ID hoặc reset dữ liệu. Hồ sơ legacy chưa khai loại pháp nhân/đăng ký tiếp tục theo validation cũ; không tự suy loại từ dữ liệu demo. Giữ Laravel/MySQL/Eloquent, React, vai trò STAFF, catalog/offering và mọi module khác.

Danh mục địa chỉ tiếp tục dùng SourceDistrictCatalog hiện có. Chưa chuyển toàn bộ Addresskit trong ZIP, không áp giới hạn HCM của ZIP lên ứng dụng PHP. Chưa chuyển chat, Google/recovery, push, R2, SePay hoặc ví. Không tuyên bố parity toàn bộ ZIP.

## Kiểm thử thực tế đợt đầu

- Full Laravel/MySQL suite: **300 tests / 3.104 assertions đạt**, gồm 9 test feature đăng ký và các test nhiều process của booking/role. Thời gian 717,59 giây trên môi trường Docker mới của máy này; không dùng làm benchmark.
- Guard DB: **8 test / 8 assertions đạt**, từ chối demo DB/host/user, URL override, config cache, môi trường sai và driver sai.
- Lượt kiểm tra cuối sau hoàn thiện harness: **17 test đăng ký + guard / 69 assertions đạt**, 78,55 giây.
- Frontend: **126 test JavaScript đạt**; build Laravel + React đạt. Build có cảnh báo font tham chiếu runtime và cảnh báo dependency có sẵn; không nâng dependency hay sửa lockfile.
- PHP lint đã chạy thành công. Browser regression dùng `scripts/browser-registration-port.mjs`: owner đăng nhập, upload PDF, gửi yêu cầu, xem trạng thái; bộ chọn loại đăng ký chi nhánh trên mobile; admin xem và từ chối; kiểm tra viewport 375px/desktop và lỗi JavaScript.

Log và ảnh nằm ngoài repo tại `C:/Users/User/.codex/visualizations/2026/11/03/01a1a165-84c0-79f2-a63f-a0f3018a8d3b/`, gồm `registration-full-php-tests.log`, `registration-final-focused-tests.log`, `registration-db-safety-tests.log`, `registration-all-js-tests.log`, `registration-final-react-build.log` và thư mục `registration-browser/`. Browser workflow đạt, không có page error; đã xem lại ảnh owner/admin mobile sau khi kết thúc hiệu ứng đổi viewport. Container preview đã được dừng sau kiểm tra; không có app demo đang được triển khai bởi phiên này.

## Rà soát tiếp ngày 03/11/2026

Đã đọc PLAN/IMPLEMENTATION, kiểm tra git status/diff, đối chiếu log nghiệm thu đợt đầu và giữ toàn bộ thay đổi chưa commit tại baseline `40e1d86`. Lượt này tiếp tục hoàn thiện phạm vi đăng ký, không mở thêm module.

- Sửa kiểm tra giữ chỗ khi duyệt đổi địa chỉ: `pending_expires_at` hiện được booking lưu theo múi giờ ứng dụng, nên so với `now()` cùng quy ước. Test tại Asia/Ho_Chi_Minh chứng minh giữ chỗ còn hạn chặn relocation, giữ chỗ hết hạn không chặn. Không đổi định dạng thời gian booking cũ; thời điểm review/capture mới vẫn UTC.
- Khi chuyển lịch qua luồng xử lý ảnh hưởng hiện có, cập nhật snapshot sang chi nhánh đích với `basis=AT_BRANCH_TRANSFER`; snapshot trước chuyển được giữ trong hồ sơ ảnh hưởng. Phần thông tin địa chỉ bổ sung không tham gia hash nghiệp vụ, để các hồ sơ ảnh hưởng cũ không tự mở lại chỉ vì thêm trường lịch sử. Đổi giờ trong cùng chi nhánh vẫn giữ snapshot đã đặt.
- Form doanh nghiệp và chi nhánh giữ trạng thái chưa khai loại cho hồ sơ legacy, hiển thị lựa chọn tương ứng và không tự gán loại mới lúc lưu. Hồ sơ mới giữ mặc định đã thiết kế; việc tạo draft chi nhánh gửi ngay loại đăng ký đã chọn. Nhãn bắt buộc của số đăng ký pháp nhân theo đúng loại đã khai.
- Bổ sung kiểm tra backfill snapshot cho booking legacy đã xóa mềm, giữ địa chỉ quan sát trước đổi, provenance `LEGACY_CURRENT_AT_CHANGE` và thời điểm UTC.

Hồi quy cuối: **34 test PHP/MySQL / 194 assertions đạt**, 108,48 giây (`RegistrationPortTest`, `TestDatabaseSafetyTest`, `ImpactTest`, `BranchLifecycleTest`, `OnboardingTest`), gồm test metadata capture không làm mất hiệu lực kết quả xử lý ảnh hưởng đã lưu; **128 test JavaScript đạt**. Build Laravel và React đạt, PHP lint các file thay đổi và `git diff --check` đạt. Test form doanh nghiệp chạy logic hydrate/payload thực tế để kiểm tra legacy, hồ sơ mới và loại đã khai. Không chạy lại full PHP suite trong lượt này; số 300 tests phía trên thuộc đợt đầu. Cảnh báo font/dependency của build vẫn như đợt đầu, không thay lockfile.

Bằng chứng bổ sung ở `C:/Users/User/.codex/visualizations/2026/11/03/01a1a1bb-6cf9-7191-9582-27f5103ac228/`: `registration-followup-red-tests.log` ghi lỗi trước sửa, `registration-followup-php-final-tests.log` là lượt PHP cuối (lượt trước trong `registration-followup-php-tests.log`), `registration-followup-js-tests.log`, `registration-followup-build.log`, `registration-followup-react-build.log`, `registration-followup-php-lint.log`. Bước build React đầu tiên bị sandbox từ chối đọc thư mục cha; chạy lại riêng bước đó đã thành công.

Browser regression cuối đạt bốn bước: owner tải PDF/gửi yêu cầu; bộ chọn đăng ký mới trên mobile; lưu nháp chi nhánh legacy qua API thật và kiểm tra `registrationType=null` cả request/response; admin từ chối với địa chỉ công khai giữ nguyên. Kiểm tra 375px/desktop không tràn ngang, không có page error; đã xem lại ảnh mobile sau khi lưu hoàn tất. Log `registration-followup-browser.log`, kết quả JSON và ảnh trong `registration-followup-browser/` cùng thư mục bằng chứng trên. Một lượt lặp trước gặp 401 rồi timeout lúc chuyển trang sát đăng nhập (`registration-admin-diagnostic.log`); script đã thêm chờ tải trang sau đăng nhập, lượt cuối đạt. Không thay code xác thực sản phẩm trong lượt này. Preview đã dừng sau kiểm tra.

## Chạy lại

Docker project kiểm thử là `beautybook-registration-test`, tách volume/network khỏi `beautybook-php`. Không chạy hai suite cùng schema. Test PHP yêu cầu cấu hình hiệu lực `mysql_test/beautybook_test` bằng user test, không dùng config cache.

`.env.testing` đã tạo cục bộ, không commit, chỉ gồm:

```dotenv
APP_ENV=testing
APP_TIMEZONE=Asia/Ho_Chi_Minh
```

```powershell
docker compose -p beautybook-registration-test -f docker-compose.laravel.yml up -d --wait mysql_test
docker compose -p beautybook-registration-test -f docker-compose.laravel.yml run --build --rm --no-deps app php artisan test --filter="RegistrationPortTest|TestDatabaseSafetyTest"
```

Nhóm hồi quy đầy đủ của lượt rà soát này dùng filter `RegistrationPortTest|TestDatabaseSafetyTest|ImpactTest|BranchLifecycleTest|OnboardingTest`. Test JavaScript chạy bằng `node --test` với toàn bộ file `resources/beautybook/src/**/*.test.js` (liệt kê file bằng `rg --files` trên PowerShell).

Để chạy browser regression cần một app local kết nối DB test đã seed, module Playwright, Chrome và các biến `APP_URL`, `PLAYWRIGHT_MODULE`, `EVIDENCE_DIR`, `REGISTRATION_TEST_DATABASE=beautybook_test`. Script từ chối URL ngoài localhost/127.0.0.1. `scripts/prepare-registration-preview.php` là helper **reset test DB**, yêu cầu acknowledgement, guard và lease trước khi thực thi; không dùng để chuẩn bị demo.

## Áp dụng và hoàn tác

Chưa chạy migration lên DB demo/nguồn. Khi đưa thay đổi vào môi trường dùng chung, sao lưu và áp dụng migration bổ sung theo quy trình của môi trường đó trước khi chạy code mới.

Hoàn tác code trước, giữ cột/bảng và lịch sử nếu đã có dữ liệu. Không chạy down migration để hoàn tác một yêu cầu đã duyệt. Đổi lại địa chỉ qua yêu cầu mới có xét duyệt; không xóa lịch sử tài liệu/snapshot. Xem PLAN.md cùng thư mục để biết contract và các invariant cần giữ.
