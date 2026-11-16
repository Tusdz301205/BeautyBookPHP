# BeautyBookPHP

Ứng dụng đặt lịch và quản lý cơ sở làm đẹp, xây dựng bằng **Laravel, React và MySQL** cho đồ án môn Lập trình mã nguồn mở. Khách hàng có thể tìm dịch vụ, đặt lịch, thanh toán cọc và trao đổi với chi nhánh; cơ sở quản lý lịch hẹn, nhân sự, dịch vụ và tài chính trên cùng hệ thống.

Backend Laravel cung cấp API, phân quyền và xử lý nghiệp vụ. Frontend React được phục vụ cùng origin qua Nginx. Dịch vụ Python tùy chọn sử dụng PhoBERT để phân tích đánh giá tiếng Việt.

## Mục lục

- [Chức năng](#chức-năng)
- [Công nghệ và yêu cầu](#công-nghệ-và-yêu-cầu)
- [Cài đặt](#cài-đặt)
- [Tài khoản demo](#tài-khoản-demo)
- [Cấu hình tích hợp](#cấu-hình-tích-hợp)
- [Chạy và dừng](#chạy-và-dừng)
- [Kiểm thử](#kiểm-thử)
- [Cấu trúc dự án](#cấu-trúc-dự-án)
- [Tài liệu](#tài-liệu)
- [Đóng góp và giấy phép](#đóng-góp-và-giấy-phép)

## Chức năng

| Vai trò | Chức năng chính |
| --- | --- |
| Khách hàng | Tìm cơ sở và dịch vụ; chọn chuyên viên, thời gian; đặt lịch đơn, combo hoặc định kỳ; quản lý lịch, ví, ưu đãi, đánh giá và tin nhắn. |
| Chủ doanh nghiệp | Đăng ký doanh nghiệp, chi nhánh; quản lý dịch vụ, nhân sự, khuyến mãi, lịch hẹn, đánh giá và tài chính trong phạm vi được cấp. |
| Lễ tân | Vận hành lịch hẹn, trao đổi với khách, xem nhân sự và xử lý thanh toán tại chi nhánh. |
| Nhân viên | Xem lịch được giao, cập nhật tài khoản và nhận thông báo. |
| Quản trị viên | Duyệt doanh nghiệp, quản lý người dùng, lịch hẹn, đánh giá, tài chính và báo cáo toàn nền tảng. |

Hệ thống phân quyền theo doanh nghiệp/chi nhánh, kiểm tra lịch trống và khóa giao dịch khi đặt lịch. Tin nhắn và thông báo cập nhật qua dịch vụ realtime. AI phân tích bốn khía cạnh: chất lượng dịch vụ, thái độ nhân viên, thời gian chờ và giá cả.

**Thanh toán:** chế độ ví giữ cọc 30% khi đặt lịch. Nạp ví qua SePay cần cấu hình riêng; chi trả ra ngân hàng cho cơ sở hiện dùng mô phỏng. Xem [hướng dẫn thanh toán](docs/PAYMENT_SETUP.md) trước khi bật tích hợp.

## Công nghệ và yêu cầu

| Thành phần | Công nghệ |
| --- | --- |
| Backend | PHP 8.4, Laravel 13, Eloquent |
| Frontend | React 18, React Router 6, Zustand, Tailwind CSS, Vite |
| Dữ liệu | MySQL 8.4; database ứng dụng và kiểm thử tách riêng |
| Realtime | Node.js, WebSocket, Nginx |
| AI tùy chọn | Python 3.13, PyTorch, PhoBERT, VnCoreNLP |
| Kiểm thử | PHPUnit, Node.js Test Runner, Playwright |
| Môi trường chạy | Docker Compose |

Cần **Git**, **Docker Compose v2** và **Node.js >= 22.12** cùng npm. Trên Windows/macOS, mở Docker Desktop trước khi chạy. Các cổng mặc định: `8000` (web), `8001` (app), `3307` (MySQL), `3308` (MySQL test).

Lockfile PHP hiện yêu cầu **PHP >= 8.4.1**; nên dùng container có sẵn thay vì PHP 8.3 trên máy. Phiên bản dependency được cố định trong `composer.lock` và `package-lock.json`. Nếu dùng AI, cần thêm **Git LFS**, **Python 3.13** và **JDK** có cả `java` lẫn `javac`.

## Cài đặt

### 1. Lấy mã nguồn và cấu hình

```sh
git clone https://github.com/Tusdz301205/BeautyBookPHP.git
cd BeautyBookPHP
cp .env.example .env
```

Trên PowerShell có thể dùng `Copy-Item .env.example .env`. Chỉ sao chép khi chưa có `.env`; giữ cấu hình riêng nếu đang dùng database có sẵn.

Checkpoint AI được quản lý bằng Git LFS. Người chỉ chạy web có thể đặt biến môi trường `GIT_LFS_SKIP_SMUDGE=1` trước khi clone để bỏ qua tải checkpoint; khi cần AI, tải bằng lệnh ở phần [AI](#ai-phân-tích-đánh-giá).

### 2. Cài dependency và build

```sh
npm ci
npm run build
docker compose -f docker-compose.laravel.yml build app
```

Composer được cài trong image PHP. Dependency PHP nằm trong volume Linux `beautybook_vendor`; không cần cài PHP/Composer trên host. Khi `composer.lock` thay đổi, entrypoint cập nhật volume theo lockfile.

Tạo khóa ứng dụng:

```sh
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan key:generate --show
```

Gán giá trị `base64:...` vừa tạo cho **`LARAVEL_APP_KEY`** trong `.env`. Compose đọc biến này để thiết lập `APP_KEY` trong container. Không đổi khóa sau khi đã lưu dữ liệu mã hóa.

### 3. Khởi tạo database mới

```sh
docker compose -f docker-compose.laravel.yml up -d --wait mysql mysql_test
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan migrate --seed
```

Tùy chọn, thêm bộ cơ sở/dịch vụ minh họa:

```sh
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan db:seed --class=BeautybookSourceReferenceSeeder
```

Các lệnh seed dành cho database demo mới. Với database đang dùng, chỉ chạy migration cần thiết; không chạy `migrate:fresh`, reset hoặc seed lại dữ liệu.

### 4. Khởi động

```sh
docker compose -f docker-compose.laravel.yml up -d --wait app realtime web scheduler
```

- Ứng dụng: [http://localhost:8000/beautybook](http://localhost:8000/beautybook)
- Đăng nhập: [http://localhost:8000/beautybook/login](http://localhost:8000/beautybook/login)
- Kiểm tra dữ liệu/dịch vụ: [http://localhost:8000/ready](http://localhost:8000/ready)

Truy cập cổng **8000** để dùng đầy đủ Nginx, asset và WebSocket. Cổng `8001` phục vụ chẩn đoán Laravel trực tiếp.

## Tài khoản demo

Sau khi chạy `migrate --seed` trên **database mới**, các tài khoản sau dùng chung mật khẩu **`BeautyBook123!`**:

| Vai trò | Email |
| --- | --- |
| Khách hàng | `customer@beautybook.local` |
| Chủ doanh nghiệp | `owner@beautybook.local` |
| Chủ doanh nghiệp thứ hai | `owner2@beautybook.local` |
| Lễ tân | `receptionist@beautybook.local` |
| Nhân viên | `staff@beautybook.local` |
| Quản trị viên | `admin@beautybook.local` |

Tài khoản vận hành đăng nhập bằng không gian **Cơ sở**; quản trị viên chọn không gian quản trị. Database đã nhập dữ liệu GlowMe có bộ tài khoản riêng, ghi tại [DEMO_ACCOUNTS](docs/DEMO_ACCOUNTS.md). Những tài khoản đó không được tạo tự động khi clone repo. Database local, mật khẩu đã đổi và ảnh tải lên của người dùng không nằm trong mã nguồn.

## Cấu hình tích hợp

`.env.example` liệt kê biến và giá trị mẫu. Chỉ điền thông tin riêng vào `.env` hoặc môi trường máy chủ.

| Tích hợp | Biến chính | Tài liệu |
| --- | --- | --- |
| Ví và nạp tiền SePay | `WALLET_BOOKING_DEPOSIT_ENABLED`, `SEPAY_WALLET_ENABLED`, `SEPAY_WALLET_*` | [PAYMENT_SETUP](docs/PAYMENT_SETUP.md) |
| AI | `ABSA_SERVICE_TOKEN`, `ABSA_SERVICE_URL`, `ABSA_WORKER_ENABLED` | [ML_SETUP](docs/ML_SETUP.md) |
| Email | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` | `.env.example`; mặc định `log` |
| Google đăng nhập | `GOOGLE_CLIENT_ID` | `.env.example` |
| Firebase Push | `PUSH_ENABLED`, `FCM_*` | `.env.example` |
| Media riêng tư trên R2 | `MEDIA_PRIVATE_DISK`, `R2_*` | `.env.example` |

Compose chỉ truyền biến được khai báo trong file Compose. Khi thêm tích hợp, kiểm tra cấu hình tương ứng trong `docker-compose.laravel.yml` và tạo lại container để áp dụng thay đổi.

### AI phân tích đánh giá

AI được cài riêng; web có thể chạy khi chưa cài môi trường Python. Cấu hình AI đi kèm đã được kiểm tra trên **Windows với Docker Desktop**. Trên Linux chạy Docker Engine trực tiếp, container không truy cập được service chỉ bind loopback của host theo cấu hình này; chưa có quy trình AI cho Linux được kiểm chứng. Chỉ thêm ánh xạ `host.docker.internal` là chưa đủ. Dùng phần web hoặc thiết lập phương án kết nối riêng trước khi chạy AI.

```sh
git lfs install
git lfs pull --include="ml/glowme_ml/release/phobert-beauty-v2/best_model.pt"
npm run ai:install
```

Sau khi cấu hình token và bật worker theo [ML_SETUP](docs/ML_SETUP.md):

```sh
npm start
npm run ai:status
```

`npm start` khởi động AI trên host và Docker với profile `ml`; chỉ dùng sau khi hoàn tất cài đặt AI. Đánh giá được lưu trước khi đưa vào hàng đợi phân tích. Mô hình hiện chưa được xác nhận chất lượng trên dữ liệu người dùng thật; xem [model card](ml/glowme_ml/release/phobert-beauty-v2/model_card.md).

## Chạy và dừng

```sh
# Chạy web khi không dùng AI
docker compose -f docker-compose.laravel.yml up -d --wait app realtime web scheduler

# Kiểm tra dịch vụ và log
docker compose -f docker-compose.laravel.yml ps
docker compose -f docker-compose.laravel.yml logs --tail=100 app scheduler realtime

# Build lại sau khi sửa frontend
npm run build

# Dừng container; giữ dữ liệu trong volume
docker compose -f docker-compose.laravel.yml down

# Dừng AI trên host nếu đã bật
npm run ai:stop
```

Scheduler xử lý các tác vụ định kỳ qua `schedule:work`. Chạy một lượt bảo trì bằng `docker compose -f docker-compose.laravel.yml exec -T app php artisan beautybook:maintain`.

## Kiểm thử

### Backend

```sh
docker compose -f docker-compose.laravel.yml up -d --wait mysql_test
docker compose -f docker-compose.laravel.yml exec -T app php artisan test
```

`phpunit.xml` cố định database kiểm thử `beautybook_test` trên service `mysql_test`. Không đổi cấu hình test sang database ứng dụng. Các test tích hợp R2 chỉ chạy khi có môi trường tương ứng; xem `docker-compose.r2-test.yml`.

### Frontend và realtime

Trên PowerShell:

```powershell
$testFiles = @(Get-ChildItem resources/beautybook/src -Recurse -Filter *.test.js | ForEach-Object FullName)
node --test @testFiles realtime/server.test.mjs scripts/frontend-parity-cleanup.test.mjs
npm run build
```

Kiểm thử trình duyệt và cách cô lập dữ liệu được mô tả trong [tests/Browser/README.md](tests/Browser/README.md). Một số script cũ dùng đường dẫn Playwright của máy phát triển; cần cấu hình thư viện trên máy mới theo tài liệu trước khi chạy.

## Cấu trúc dự án

```text
app/                         Controllers, models, policies, services và commands
config/                      Cấu hình Laravel, thanh toán, media và AI
database/                    Migrations, seeders và dữ liệu minh họa
docker/                      PHP runtime, entrypoint và Nginx
docs/                        Nghiệp vụ, triển khai và kết quả kiểm thử
lang/                        Bản dịch tiếng Việt
ml/                          Dịch vụ inference, tokenizer và model qua Git LFS
public/                      Điểm vào, font, ảnh và tài nguyên tĩnh
realtime/                    WebSocket gateway và kiểm thử
resources/beautybook/src/     Giao diện React
resources/views/             Blade entry và giao diện tương thích
routes/                      API và web routes
scripts/                     Công cụ kiểm tra và vận hành AI
tests/                       PHPUnit và kịch bản trình duyệt
```

Giao diện chính là React. Bộ màn hình Blade tương thích phục vụ kiểm thử và tắt mặc định bằng `BEAUTYBOOK_LEGACY_UI=false`. Hai pipeline Vite vẫn được build bởi `npm run build`.

Repo giữ mã nguồn, lockfile, tài nguyên cần khi chạy và bằng chứng kiểm thử có liên kết trong tài liệu. Dependency, build output, cache, virtualenv, thư viện skills local, log, `.env`, database dump và tệp người dùng tải lên được Git bỏ qua.

## Tài liệu

- [Danh mục tài liệu](docs/README.md)
- [Phạm vi đồ án](docs/course/COURSE_SCOPE.md)
- [ERD, Usecase và kịch bản demo](docs/course/ERD_USE_CASE.md)
- [Luồng đặt lịch](docs/BOOKING_FLOW.md)
- [Ví, tiền cọc và thanh toán](docs/PAYMENT_SETUP.md)
- [Cài đặt và vận hành AI](docs/ML_SETUP.md)
- [Kết quả kiểm thử](docs/FULL_TEST_RESULTS_2026-11-14.md)
- [Kiểm tra trước khi xuất bản 16/11/2026](docs/VALIDATION_2026-11-16.md)
- [Kết quả nhập dữ liệu GlowMe](docs/migration/2026-11-12-glowme-data-import/IMPLEMENTATION.md)

Các báo cáo có ngày phản ánh trạng thái tại thời điểm ghi nhận; dùng cấu hình, lockfile và kết quả test hiện tại để kiểm tra phiên bản đang chạy.

## Đóng góp và giấy phép

Tạo nhánh cho thay đổi, mô tả vấn đề cùng cách kiểm tra trong pull request và chạy các kiểm thử liên quan trước khi gửi. Không đưa `.env`, khóa truy cập, dữ liệu khách hàng hoặc dependency cài trên máy vào commit.

Dependency, font, PhoBERT và VnCoreNLP giữ giấy phép riêng trong các thư mục tương ứng. Metadata Composer khai báo MIT; repository hiện chưa có tệp `LICENSE` riêng cho toàn bộ ứng dụng.
