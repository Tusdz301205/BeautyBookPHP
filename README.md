# BeautyBook — Open Source Programming Edition

[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat&logo=php&logoColor=white)](https://php.net)
[![Laravel Framework](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com)
[![MySQL](https://img.shields.io/badge/MySQL-8.4%20LTS-4479A1?style=flat&logo=mysql&logoColor=white)](https://mysql.com)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC?style=flat&logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![Tests](https://img.shields.io/badge/PHPUnit-42%2F42%20Passed-brightgreen?style=flat&logo=phpunit&logoColor=white)](https://phpunit.de)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> **Hệ Thống Đặt Lịch Dịch Vụ Làm Đẹp Trực Tuyến Mã Nguồn Mở**  
> Dự án triển khai cho học phần: **LẬP TRÌNH MÃ NGUỒN MỞ (Open Source Programming)**  
> Được phân tích, thiết kế lại và phát triển dựa trên nền tảng nghiệp vụ của BeautyBook Reference Platform.

---

## 📖 1. Giới Thiệu Dự Án (Introduction)

**BeautyBook — Open Source Programming Edition** là nền tảng thương mại điện tử kết nối các cơ sở làm đẹp (Spa, Salon tóc, Thẩm mỹ viện, Nail studio) với khách hàng có nhu cầu đặt lịch trực tuyến.

Hệ thống được phát triển theo mô hình **Modern Monolithic MVC** hoàn chỉnh bằng **Laravel 11**, loại bỏ sự cồng kềnh của kiến trúc microservices/SPA đa tầng, thay vào đó tối ưu hóa trải nghiệm mượt mà với **Server-Side Rendering (SSR Blade)** kết hợp **Alpine.js**, **Tailwind CSS**, tương tác bán đồng bộ **Vanilla Fetch AJAX** và bản đồ nguồn mở **Leaflet / OpenStreetMap**.

### ✨ Điểm Nổi Bật (Highlights)
- **Chuẩn Mã Nguồn Mở 100%:** Không sử dụng bất kỳ thư viện hoặc API bản quyền tính phí nào (toàn bộ bản đồ hiển thị bằng OpenStreetMap/Leaflet, không phụ thuộc Google Maps API).
- **Phân Quyền 2 Vai Trò Thực Tế:** 
  - `customer`: Tìm kiếm thông minh, xem salon/chi nhánh, đặt lịch hẹn trực quan, lưu dịch vụ yêu thích, quản lý đơn hẹn, hủy đơn, đánh giá dịch vụ sau khi hoàn thành.
  - `business_owner`: Quản lý thông tin salon, danh mục, chi nhánh, dịch vụ, nhân sự kỹ thuật viên, theo dõi doanh thu/đơn hẹn, xử lý vòng đời đơn hẹn (State Machine), phản hồi đánh giá.
- **Máy Trạng Thái & Lịch Sử Kiểm Toán:** 6 trạng thái đặt lịch (`pending`, `confirmed`, `in_progress`, `completed`, `cancelled`, `no_show`) kèm bảng audit trail tự động lưu từng lần thay đổi trạng thái.
- **Tìm Kiếm Tức Thì (Live AJAX Search):** Gợi ý dịch vụ, salon, danh mục ngay khi gõ không cần tải lại trang.
- **Kiểm Thử Tự Động Toàn Diện:** Bộ test PHPUnit tích hợp 42 bài kiểm thử (116 assertions) đạt tỷ lệ vượt qua 100%.

---

## 🛠️ 2. Công Nghệ Sử Dụng (Technology Stack)

- **Backend:** PHP 8.3+, Laravel 11.x
- **Cơ sở dữ liệu:** MySQL 8.4 LTS (InnoDB, UTF8MB4)
- **Frontend:** Blade Templates, Tailwind CSS 3.x, Alpine.js
- **Bản đồ số:** Leaflet.js + OpenStreetMap (FOSS)
- **Xác thực:** Laravel Breeze (Session & Cookie-based, CSRF protection)
- **Tương tác Frontend:** Vanilla JavaScript Fetch API (AJAX có kèm CSRF token)
- **Kiểm thử:** PHPUnit 11 (Feature Tests, Unit Tests, SQLite In-Memory)

---

## 🚀 3. Hướng Dẫn Cài Đặt & Chạy Nhanh (Quick Start)

### Yêu cầu môi trường
- PHP 8.3 hoặc mới hơn (kèm extensions: `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sqlite3`, `zip`, `gd`, `exif`)
- Composer 2.x
- Node.js 18+ & NPM
- MySQL Server 8.0+

### Các bước cài đặt

#### Bước 1: Di chuyển vào thư mục dự án
```bash
cd beautybook-laravel
```

#### Bước 2: Cài đặt thư viện phụ thuộc
```bash
composer install
npm install
```

#### Bước 3: Thiết lập tệp môi trường `.env`
Sao chép tệp `.env.example` thành `.env` (hoặc cấu hình trực tiếp `.env`):
```env
APP_NAME="BeautyBook"
APP_ENV=local
APP_KEY=base64:YOUR_GENERATED_KEY
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=beautybook
DB_USERNAME=root
DB_PASSWORD=
```

#### Bước 4: Tạo App Key và liên kết thư mục Storage
```bash
php artisan key:generate
php artisan storage:link
```

#### Bước 5: Chạy Migration và Seed dữ liệu mẫu
```bash
php artisan migrate:fresh --seed
```
*Lệnh này sẽ tự động tạo 19 bảng CSDL, nạp danh mục địa giới hành chính mẫu (Hà Nội, TP.HCM, Đà Nẵng) và khởi tạo tài khoản demo, salon, chi nhánh, dịch vụ, nhân viên và các đơn hẹn thực tế.*

#### Bước 6: Build giao diện Frontend
```bash
npm run build
```

#### Bước 7: Khởi động máy chủ ứng dụng
```bash
php artisan serve
```
Truy cập ứng dụng tại: [http://localhost:8000](http://localhost:8000)

---

## 👥 4. Tài Khoản Trải Nghiệm Mẫu (Demo Credentials)

| Vai trò | Email đăng nhập | Mật khẩu | Chức năng chính |
| :--- | :--- | :--- | :--- |
| **Business Owner (Chủ Salon)** | `owner@beautybook.vn` | `password` | Quản lý Salon "Aura Beauty Spa", xem báo cáo doanh thu, duyệt đơn hẹn, CRUD chi nhánh/dịch vụ/nhân viên, trả lời đánh giá |
| **Customer (Khách Hàng)** | `customer@beautybook.vn` | `password` | Tìm kiếm dịch vụ, đặt lịch hẹn tại chi nhánh, quản lý danh sách đơn, hủy lịch, đánh giá dịch vụ |

*Bạn cũng có thể đăng ký tài khoản mới trực tiếp tại trang `/register` và lựa chọn vai trò "Khách hàng" hoặc "Chủ cơ sở làm đẹp".*

---

## 🧪 5. Kiểm Thử Tự Động (Automated Testing)

Dự án đi kèm bộ kiểm thử tự động toàn diện kiểm tra từ luồng xác thực đến luồng nghiệp vụ kinh doanh:

```bash
php vendor/bin/phpunit --no-coverage
```

### Kết quả kiểm thử:
```
PHPUnit 11.5.7 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.16
Configuration: phpunit.xml

..........................................                        42 / 42 (100%)

Time: 00:02.313, Memory: 42.00 MB

OK (42 tests, 116 assertions)
```

---

## 📁 6. Cấu Trúc Thư Mục Dự Án (Project Structure)

```
beautybook-laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Customer/             # Controller dành riêng cho Khách hàng
│   │   │   │   ├── BookingController.php
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── FavoriteController.php
│   │   │   │   ├── ProfileController.php
│   │   │   │   └── ReviewController.php
│   │   │   ├── Owner/                # Controller dành riêng cho Chủ salon
│   │   │   │   ├── BookingController.php
│   │   │   │   ├── BranchController.php
│   │   │   │   ├── BusinessController.php
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── ReviewController.php
│   │   │   │   ├── ServiceCategoryController.php
│   │   │   │   ├── ServiceController.php
│   │   │   │   └── StaffController.php
│   │   │   ├── ExploreController.php # Trang chủ, tìm kiếm công khai, Live AJAX
│   │   │   ├── SalonController.php
│   │   │   ├── PublicBranchController.php
│   │   │   └── PublicServiceController.php
│   │   ├── Middleware/               # EnsureUserIsCustomer, EnsureUserIsOwner
│   │   └── Requests/                 # 6 Form Requests kiểm tra tính hợp lệ dữ liệu
│   ├── Models/                       # 16 Eloquent Models (User, Business, Branch,...)
│   ├── Policies/                     # 6 Eloquent Policies bảo mật tài nguyên
│   └── View/Components/              # Blade Layout Components
├── database/
│   ├── migrations/                   # 10 migration files (19 tables)
│   └── seeders/                      # BeautyBookSeeder, LocationSeeder
├── docs/
│   └── course/                       # Tài liệu chuyên sâu môn Lập trình mã nguồn mở
│       ├── ARCHITECTURE.md           # Thiết kế kiến trúc Monolith MVC
│       ├── ERD.md                    # Sơ đồ quan hệ thực thể Mermaid chi tiết
│       └── RUBRIC_TRACEABILITY.md    # Bảng đối chiếu tiêu chuẩn chấm điểm
├── resources/
│   ├── css/                          # Tailwind CSS custom styles
│   └── views/
│       ├── auth/                     # Giao diện đăng ký, đăng nhập
│       ├── customer/                 # Portal khách hàng (Dashboard, Đặt lịch,...)
│       ├── owner/                    # Portal chủ salon (Dashboard, Quản lý,...)
│       ├── explore/                  # Trang khám phá & tìm kiếm dịch vụ
│       ├── salons/                   # Trang chi tiết salon
│       ├── branches/                 # Trang chi tiết chi nhánh (bản đồ Leaflet)
│       └── layouts/                  # Master layouts (public, customer, owner)
├── routes/
│   ├── web.php                       # 75 routes phân nhóm rõ ràng
│   └── auth.php                      # Breeze authentication routes
└── tests/
    ├── Feature/
    │   ├── BeautyBookFlowTest.php    # 16 test cases nghiệp vụ cốt lõi
    │   └── Auth/                     # 6 test cases xác thực
    └── Unit/
```

---

## 📑 7. Tài Liệu Môn Học (Course Documentation)

Để nắm rõ hơn về cách thức thiết kế và triển khai đáp ứng đề cương môn học, vui lòng tham khảo các tài liệu trong thư mục `docs/course/`:
- 🏗️ [**docs/course/ARCHITECTURE.md**](docs/course/ARCHITECTURE.md): Phân tích kiến trúc hệ thống, đối chiếu với bản gốc NestJS và lý do chọn Laravel 11.
- 🗄️ [**docs/course/ERD.md**](docs/course/ERD.md): Toàn bộ lược đồ CSDL 19 bảng kèm biểu đồ quan hệ Mermaid và chiến lược Indexing.
- 🎯 [**docs/course/RUBRIC_TRACEABILITY.md**](docs/course/RUBRIC_TRACEABILITY.md): Bảng đối chiếu chi tiết từng tiêu chí chấm điểm của giảng viên với mã nguồn thực tế.

---

## 📄 8. Bản Quyền (License)

Dự án được phát hành theo giấy phép [MIT License](LICENSE) dành cho mục đích học tập và nghiên cứu môn Lập trình Mã nguồn mở.
