# BeautyBook — Kiến Trúc Hệ Thống (System Architecture)
**Môn học:** Lập trình Mã nguồn mở (Open Source Programming)  
**Phiên bản:** BeautyBook — Open Source Programming Edition (Laravel 11 Full-Stack)

---

## 1. Tổng Quan Kiến Trúc (Architecture Overview)

Phiên bản **BeautyBook Open Source Edition** được thiết kế lại toàn diện từ phiên bản tham chiếu (NestJS microservices + Next.js/React + PostgreSQL) sang kiến trúc **Modern Monolithic MVC** dựa trên **Laravel 11** và **MySQL 8.4**.

Mô hình này tối ưu hóa tính độc lập mã nguồn mở, giảm thiểu sự phức tạp vận hành (devops overhead), đáp ứng chuẩn mực học thuật và thực tiễn của một hệ thống thương mại điện tử đặt lịch dịch vụ làm đẹp hoàn chỉnh.

```
+-------------------------------------------------------------------------------+
|                            CLIENT BROWSER / USER                              |
|   (Responsive Web - Tailwind CSS + Alpine.js + Vanilla Fetch AJAX + Leaflet)  |
+---------------------------------------+---------------------------------------+
                                        | HTTP / HTTPS (RESTful + Blade SSR)
                                        v
+-------------------------------------------------------------------------------+
|                        ROUTING & MIDDLEWARE PIPELINE                          |
|  - routes/web.php                                                             |
|  - auth (Laravel Breeze Session Auth)                                         |
|  - customer (EnsureUserIsCustomer) / owner (EnsureUserIsOwner)                |
+---------------------------------------+---------------------------------------+
                                        |
                 +----------------------+----------------------+
                 |                                             |
                 v                                             v
+----------------------------------+         +----------------------------------+
|      PUBLIC / GUEST DOMAIN       |         |       AUTHENTICATED DOMAINS      |
|  - ExploreController (Live AJAX) |         |  Customer:                       |
|  - SalonController               |         |  - BookingController (State M.)  |
|  - PublicBranchController (OSM)  |         |  - ReviewController, Favorites   |
|  - PublicServiceController       |         |  Owner:                          |
+----------------------------------+         |  - BranchController, StaffCRUD   |
                 |                           |  - ServiceController, Bookings  |
                 |                           +----------------------------------+
                 |                                             |
                 +----------------------+----------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
|                        APPLICATION LAYER (BUSINESS LOGIC)                     |
|  - Form Requests (Validation: BookingCreateRequest, BranchRequest, etc.)      |
|  - Policies (Authorization: BookingPolicy, BranchPolicy, StaffPolicy, etc.)   |
|  - Booking State Machine (pending -> confirmed -> in_progress -> completed)  |
|  - Audit Trail (BookingStatusHistory observer/event log)                      |
+---------------------------------------+---------------------------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
|                        PERSISTENCE LAYER (ELOQUENT ORM)                       |
|  - Models: User, Business, Branch, Service, Staff, Booking, Review, etc.      |
|  - SoftDeletes, Casts, Query Scopes, Relationship Cascades                    |
+---------------------------------------+---------------------------------------+
                                        | PDO MySQL
                                        v
+-------------------------------------------------------------------------------+
|                        DATABASE ENGINE: MySQL 8.4 LTS                         |
|  - 10 Migration Batches / 19 Tables                                           |
|  - InnoDB Engines, UTF8MB4, Foreign Key Constraints, B-Tree Indexes           |
+-------------------------------------------------------------------------------+
```

---

## 2. So Sánh Kiến Trúc (Architecture Comparison)

| Tiêu chí | BeautyBook Gốc (Reference) | BeautyBook Open Source Edition | Lý do tinh chỉnh |
| :--- | :--- | :--- | :--- |
| **Mô hình** | Microservices / Monorepo đa tầng | Monolithic MVC Chuẩn mực | Dễ triển khai, kiểm thử và chuyển giao trong mã nguồn mở |
| **Backend Framework**| NestJS (Node.js, TypeScript) | Laravel 11 (PHP 8.3+) | Tận dụng hệ sinh thái phong phú, Eloquent ORM mạnh mẽ |
| **Frontend Framework** | React / Next.js SPA | Blade Templates + Tailwind CSS + Alpine.js | Giảm độ trễ SSR, không cần 2 server dev riêng biệt |
| **Cơ sở dữ liệu** | PostgreSQL (Prisma ORM, 80+ tables)| MySQL 8.4 (Laravel Migrations, 19 tables) | Rút gọn schema trọng tâm vào luồng nghiệp vụ cốt lõi |
| **Bản đồ số** | Mapbox / Google Maps API (Proprietary)| Leaflet.js + OpenStreetMap (FOSS) | Hoàn toàn miễn phí, chuẩn mã nguồn mở không phụ thuộc API key |
| **Xác thực** | Keycloak / JWT Tokens phức tạp | Laravel Breeze Session Auth + Role Middleware | Bảo mật Session cookie chuẩn Web, chống CSRF mặc định |

---

## 3. Các Thành Phần Cốt Lõi (Core Components)

### 3.1. Phân Tầng MVC (Model - View - Controller)
- **Model (`app/Models/`):** Kế thừa `Illuminate\Database\Eloquent\Model`. Tận dụng triệt để:
  - Quan hệ đa dạng: `hasMany`, `belongsTo`, `belongsToMany` (cho yêu thích dịch vụ và dịch vụ nhân viên), quan hệ lồng nhau.
  - Eloquent Scopes: `scopeActive()`, `scopeFilter()` lọc động theo từ khóa, danh mục, chi nhánh, khoảng giá, địa phương.
  - Accessors: `cover_image`, `effective_price`, `avatar_url`, `formatted_address`.
  - An toàn dữ liệu: `SoftDeletes` trên tất cả tài nguyên kinh doanh và người dùng.

- **Controller (`app/Http/Controllers/`):**
  - Được chia theo namespace rõ rệt: `App\Http\Controllers\Customer` và `App\Http\Controllers\Owner`.
  - Phân tách trách nhiệm (Single Responsibility Principle): Controller chỉ điều phối request, gọi Form Request xác thực, thực thi logic qua Model và trả về View/JSON.

- **View (`resources/views/`):**
  - Cấu trúc theo Component Blade (`<x-public-layout>`, `<x-customer-layout>`, `<x-owner-layout>`).
  - Giao diện thiết kế theo hệ thống màu Rose/Pink sang trọng, hiện đại, hỗ trợ mobile responsive 100%.

### 3.2. Bảo Mật & Phân Quyền (Security & Authorization)
- **Role-based Authentication:** 2 Actor chính (`customer`, `business_owner`).
- **HTTP Middleware (`bootstrap/app.php`):**
  - `EnsureUserIsCustomer`: Ngăn chặn chủ salon can thiệp luồng khách hoặc đặt lịch của chính mình.
  - `EnsureUserIsOwner`: Bắt buộc người dùng phải là chủ salon hợp lệ mới được truy cập portal quản trị.
- **Eloquent Policies (`app/Policies/`):**
  - `BranchPolicy`, `ServicePolicy`, `StaffPolicy`: Đảm bảo chủ salon A không thể xem, sửa hoặc xóa tài nguyên thuộc quyền sở hữu của chủ salon B.
  - `BookingPolicy`: Khách chỉ xem/hủy lịch của mình; Chủ chỉ quản lý lịch thuộc các chi nhánh của salon mình.

### 3.3. Máy Trạng Thái Đặt Lịch (Booking State Machine)
Hệ thống triển khai luồng trạng thái 6 bước nghiêm ngặt:
1. `pending`: Khách vừa tạo đơn đặt lịch hẹn.
2. `confirmed`: Chủ tiệm / nhân viên tiếp nhận và xác nhận giữ chỗ.
3. `in_progress`: Khách đã đến tiệm và đang thực hiện dịch vụ.
4. `completed`: Dịch vụ hoàn tất; cho phép khách hàng đánh giá (Review).
5. `cancelled`: Đơn bị hủy (Khách có thể tự hủy khi còn ở trạng thái `pending`; Chủ tiệm có thể hủy kèm lý do).
6. `no_show`: Khách không đến đúng giờ hẹn.

Mọi chuyển đổi trạng thái đều được tự động ghi nhận vào bảng `booking_status_histories` kèm `user_id`, thời điểm và ghi chú.

---

## 4. Tương Tác Bán Đồng Bộ (AJAX & Real-time Experience)
- **Live Search (`/search/ajax`):** Sử dụng Vanilla `fetch()` với debounce, tìm kiếm tức thì theo salon, dịch vụ, danh mục không cần reload trang.
- **Tương tác nhanh (Micro-interactions):**
  - Đổi trạng thái lịch hẹn ngay trên danh sách của Owner qua AJAX.
  - Hủy đơn hẹn của khách hàng kèm modal xác nhận và xử lý AJAX mượt mà.
  - Trả lời đánh giá khách hàng (Review Reply) cập nhật DOM lập tức.
  - Lưu / Bỏ lưu dịch vụ yêu thích (Favorite Toggle) phản hồi tức thì với hiệu ứng tim động.

---

## 5. Bản Đồ Nguồn Mở (Open Source Mapping)
- Tích hợp thư viện mã nguồn mở **Leaflet.js** kết hợp bản đồ nền **OpenStreetMap (OSM)**.
- Tự động hiển thị marker chính xác theo tọa độ `latitude`/`longitude` của từng chi nhánh.
- Có popup hiển thị tên chi nhánh, số điện thoại, địa chỉ và nút dẫn đường qua Google Maps/OSM.
