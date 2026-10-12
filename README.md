# BeautyBookPHP — Laravel cho môn Lập trình mã nguồn mở

BeautyBookPHP là đồ án PHP/Laravel/MySQL cho môn Lập trình mã nguồn mở. Backend, Eloquent, migration, middleware và Blade entry nằm tại thư mục gốc. Frontend BeautyBook gốc được sao chép vào `resources/beautybook`, giữ React, component, CSS, state và tương tác; API thật do Laravel xử lý. Nguồn người dùng bổ sung tại `../beauty-booking-api-main` chỉ được đọc, không chỉnh sửa.

## Chạy bản PHP

Runtime được kiểm tra qua Docker: PHP 8.4, MySQL 8.4, Laravel theo `composer.lock`. Lockfile hiện yêu cầu PHP >=8.4.1; PHP 8.3 cục bộ không chạy được bộ dependency này. Dùng Docker Desktop ở Windows, hoặc Docker Compose trên Linux.

```bash
docker compose -f docker-compose.laravel.yml up -d --wait mysql mysql_test
docker compose -f docker-compose.laravel.yml build app
npm ci
npm run build
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan migrate --seed
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan db:seed --class=BeautybookSourceReferenceSeeder
docker compose -f docker-compose.laravel.yml up -d --wait app realtime web scheduler
```

Trong Visual Studio Code, chọn **File → Open Folder → BeautyBookPHP**, mở **Terminal → New Terminal** rồi chạy các lệnh trên. Cần Docker Desktop đang chạy và Node.js 22. `npm run build` build cả giao diện Blade và React nguồn.

Thư viện PHP được cài trong image và volume Linux `beautybook_vendor`, tránh đọc hàng nghìn file `vendor` qua ổ Windows mỗi request. Code ứng dụng vẫn cập nhật qua bind mount. Khi `composer.lock` thay đổi, entrypoint cài lại dependency đúng lockfile; không reset database. Composer chạy trong container quản lý volume này, không phải thư mục `vendor` trên Windows. Nếu đã chạy dự án trước đây, dùng `docker compose -f docker-compose.laravel.yml up -d --build --wait app realtime web scheduler` để áp dụng runtime mới.

Mở [BeautyBook](http://localhost:8000/beautybook). Địa chỉ `http://localhost:8000` tự chuyển tới giao diện này; nginx chỉ redirect trang `/`, các route Laravel hiện có được giữ. Nginx phục vụ asset và WebSocket cùng origin; port 8001 dùng chẩn đoán app, không thay cho port 8000 khi demo realtime. Readiness ở `/ready`. Compose dùng MySQL demo `beautybook_php` ở port 3307 và MySQL test `beautybook_test` ở port 3308, tách database BeautyBook nguồn. Không chạy test hoặc migration vào database nguồn. Cấu hình demo chỉ dành cho local; xem `.env.example` và Compose để cấu hình máy riêng.

Tài khoản seed Laravel: `customer@beautybook.local`, `owner@beautybook.local`, `owner2@beautybook.local`, `receptionist@beautybook.local`, `staff@beautybook.local`, `admin@beautybook.local`; mật khẩu fixture `BeautyBook123!`. Seeder chỉ tạo dữ liệu tổng hợp ở môi trường local/testing, không sao chép dữ liệu cá nhân của source.

## Dữ liệu tham chiếu BeautyBook

Fixture `database/data/beautybook-source-reference.json` lấy tên/địa chỉ/UUID từ entity plan và template seed nguồn. Để thêm 5 doanh nghiệp, 11 chi nhánh và 116 dịch vụ cùng ánh xạ minh họa gốc vào database demo đã migrate:

```bash
docker compose -f docker-compose.laravel.yml exec -T app php artisan db:seed --class=BeautybookSourceReferenceSeeder
```

Seeder bổ sung, không reset dữ liệu hoặc ghi đè chỉnh sửa sau import; giữ tài khoản demo ở trên. Chủ doanh nghiệp tham chiếu dùng email/mật khẩu template nguồn `Password123!`; nhân viên dùng mẫu cố định `Demo Nhân viên`. Giá/thời lượng là `TEMPLATE_BASE_PRICE`, không phải export giá ngẫu nhiên/rating/booking/lịch sử thanh toán database gốc. Ảnh giữ `illustration: true`, không gán thành ảnh thực tế.

Khi cần tạo lại fixture từ cây nguồn còn được giữ, chạy `node scripts/export-source-reference.mjs`; script chỉ đọc hằng số seed/entity plan và ghi JSON trong đồ án, không chạy seed/database nguồn. Runtime thông thường không cần cây nguồn bên cạnh.

## Kiểm tra và tác vụ nền

```bash
docker compose -f docker-compose.laravel.yml exec -T app php artisan test
docker compose -f docker-compose.laravel.yml exec -T app php artisan beautybook:maintain
docker compose -f docker-compose.laravel.yml logs --tail=20 scheduler
```

`beautybook:maintain` xử lý giữ chỗ/yêu cầu thay đổi hết hạn, database inbox outbox, dọn gói privacy export, offer waitlist hết hạn, chuyển chủ đến hạn và hồ sơ ảnh hưởng quá hạn. Container `scheduler` tự chạy lịch mỗi phút; log cho biết các lần thực thi. Outbox `SENT` ở đây là thông báo trong hộp thư database.

Giao diện mặc định là React BeautyBook ở `/beautybook`. Bộ màn hình Blade cũ và các route mutation phụ đã tắt mặc định bằng `BEAUTYBOOK_LEGACY_UI=false`; chỉ bật `true` khi cần kiểm tra bản Blade tương thích. Media, xác minh email, API React và readiness vẫn hoạt động độc lập. Test Blade hiện có bật cờ này trong `phpunit.xml`; `SourceSurfaceTest` kiểm tra riêng trạng thái mặc định đã tắt. Không xóa dữ liệu lịch sử hay các service dùng chung. Xem [đợt đối chiếu và đo hiệu năng 11/10/2026](docs/course/PARITY_PERFORMANCE_AUDIT_2026-10-11.md), [rubric](docs/course/RUBRIC_TRACEABILITY.md) và [ERD/use case/demo](docs/course/ERD_USE_CASE.md).

Phạm vi chuyển đổi, chứng cứ và các giới hạn còn lại được ghi tại [IMPLEMENTATION_STATUS](docs/course/IMPLEMENTATION_STATUS.md) và [đối chiếu giao diện/luồng với nguồn](docs/course/UI_SOURCE_PARITY.md). Xem [prompt triển khai đã áp dụng](docs/course/PORT_IMPLEMENTATION_PROMPT.md) và [phạm vi](docs/course/COURSE_SCOPE.md). Chưa tuyên bố parity toàn bộ BeautyBook hay phát hành công khai.

Danh sách tài liệu phục vụ báo cáo và thuyết trình nằm trong [docs/README.md](docs/README.md).
