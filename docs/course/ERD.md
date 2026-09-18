# BeautyBook — Sơ Đồ Thực Thể Quan Hệ (Entity Relationship Diagram - ERD)
**Môn học:** Lập trình Mã nguồn mở (Open Source Programming)  
**Hệ Quản Trị Cơ Sở Dữ Liệu:** MySQL 8.4 LTS / InnoDB Engine  
**Charset/Collation:** `utf8mb4_unicode_ci`

---

## 1. Sơ Đồ ERD Toàn Diện (Mermaid ERD)

```mermaid
erDiagram
    USERS ||--o| BUSINESSES : "owns"
    USERS ||--o{ BOOKINGS : "places"
    USERS ||--o{ REVIEWS : "writes"
    USERS ||--o{ SAVED_SERVICES : "favorites"
    USERS ||--o{ BOOKING_STATUS_HISTORIES : "triggers"

    PROVINCES ||--o{ DISTRICTS : "contains"
    DISTRICTS ||--o{ WARDS : "contains"
    WARDS ||--o{ BRANCHES : "locates"

    BUSINESSES ||--o{ BRANCHES : "operates"
    BUSINESSES ||--o{ SERVICE_CATEGORIES : "organizes"
    BUSINESSES ||--o{ STAFF : "employs"

    BRANCHES ||--o{ SERVICES : "offers"
    BRANCHES ||--o{ STAFF : "assigns"
    BRANCHES ||--o{ BOOKINGS : "hosts"

    SERVICE_CATEGORIES ||--o{ SERVICES : "categorizes"
    SERVICES ||--o{ SERVICE_IMAGES : "has"
    SERVICES ||--o{ STAFF_SERVICES : "performed_by"
    SERVICES ||--o{ BOOKING_ITEMS : "included_in"
    SERVICES ||--o{ SAVED_SERVICES : "favorited_by"

    STAFF ||--o{ STAFF_WORKING_HOURS : "works"
    STAFF ||--o{ STAFF_SERVICES : "can_do"
    STAFF ||--o{ BOOKING_ITEMS : "assigned_to"

    BOOKINGS ||--o{ BOOKING_ITEMS : "consists_of"
    BOOKINGS ||--o{ BOOKING_STATUS_HISTORIES : "tracks"
    BOOKINGS ||--o| REVIEWS : "receives"

    USERS {
        bigint id PK
        string name
        string email UK
        string phone
        string password
        enum role "customer, business_owner"
        string avatar
        string gender
        date date_of_birth
        string address
        boolean is_active
        timestamp email_verified_at
        timestamp last_login_at
        timestamp deleted_at
    }

    BUSINESSES {
        bigint id PK
        bigint owner_id FK
        string name
        string slug UK
        text description
        string logo
        string cover_image
        string phone
        string email
        string website
        enum status "pending, active, suspended"
        timestamp deleted_at
    }

    BRANCHES {
        bigint id PK
        bigint business_id FK
        string name
        string slug
        string phone
        string email
        string address_line
        string ward_id FK
        string district_id FK
        string province_id FK
        decimal latitude
        decimal longitude
        time opening_time
        time closing_time
        enum status "active, inactive"
        timestamp deleted_at
    }

    SERVICE_CATEGORIES {
        bigint id PK
        bigint business_id FK
        string name
        string slug
        text description
        integer sort_order
        boolean is_active
    }

    SERVICES {
        bigint id PK
        bigint branch_id FK
        bigint category_id FK
        string name
        string slug
        text description
        decimal price
        decimal sale_price
        integer duration_minutes
        boolean is_bookable
        enum status "active, inactive"
        timestamp deleted_at
    }

    SERVICE_IMAGES {
        bigint id PK
        bigint service_id FK
        string image_path
        boolean is_primary
        integer sort_order
    }

    STAFF {
        bigint id PK
        bigint business_id FK
        bigint branch_id FK
        string name
        string phone
        string email
        string avatar
        string title
        text bio
        enum status "active, inactive"
        timestamp deleted_at
    }

    STAFF_WORKING_HOURS {
        bigint id PK
        bigint staff_id FK
        tinyint day_of_week
        time start_time
        time end_time
        boolean is_day_off
    }

    STAFF_SERVICES {
        bigint id PK
        bigint staff_id FK
        bigint service_id FK
    }

    BOOKINGS {
        bigint id PK
        string booking_code UK
        bigint customer_id FK
        bigint branch_id FK
        date booking_date
        time start_time
        time end_time
        enum status "pending, confirmed, in_progress, completed, cancelled, no_show"
        decimal total_amount
        text notes
        text cancellation_reason
        timestamp cancelled_at
        timestamp completed_at
    }

    BOOKING_ITEMS {
        bigint id PK
        bigint booking_id FK
        bigint service_id FK
        bigint staff_id FK
        decimal price
        integer duration_minutes
        time start_time
        time end_time
    }

    BOOKING_STATUS_HISTORIES {
        bigint id PK
        bigint booking_id FK
        bigint user_id FK
        string from_status
        string to_status
        text notes
        timestamp created_at
    }

    REVIEWS {
        bigint id PK
        bigint booking_id FK
        bigint customer_id FK
        bigint branch_id FK
        tinyint rating "1-5"
        text comment
        text owner_reply
        timestamp owner_replied_at
        boolean is_visible
    }

    SAVED_SERVICES {
        bigint id PK
        bigint user_id FK
        bigint service_id FK
        timestamp created_at
    }

    PROVINCES {
        string code PK
        string name
        string name_en
        string full_name
        string code_name
    }

    DISTRICTS {
        string code PK
        string name
        string name_en
        string full_name
        string code_name
        string province_code FK
    }

    WARDS {
        string code PK
        string name
        string name_en
        string full_name
        string code_name
        string district_code FK
    }
```

---

## 2. Danh Mục Các Bảng & Mô Tả Chi Tiết (Table Catalog)

### 2.1. Nhóm Người Dùng & Quản Trị (Auth & Users)
1. **`users`**: Chứa thông tin đăng nhập, vai trò (`customer` hoặc `business_owner`), thông tin cá nhân và thời điểm hoạt động.
2. **`password_reset_tokens`**: Quản lý mã khôi phục mật khẩu.
3. **`sessions`**: Lưu trữ phiên làm việc của người dùng khi sử dụng database session driver.

### 2.2. Nhóm Địa Giới Hành Chính Việt Nam (Vietnam Locations)
4. **`provinces`**: Danh mục Tỉnh/Thành phố trực thuộc Trung ương (Hà Nội, TP. Hồ Chí Minh, Đà Nẵng, Cần Thơ, v.v.).
5. **`districts`**: Danh mục Quận/Huyện liên kết với Tỉnh/Thành.
6. **`wards`**: Danh mục Phường/Xã liên kết với Quận/Huyện.

### 2.3. Nhóm Doanh Nghiệp & Chi Nhánh (Business & Branches)
7. **`businesses`**: Doanh nghiệp làm đẹp / thương hiệu chính, thuộc quyền sở hữu của `User` có vai trò `business_owner`.
8. **`branches`**: Các cơ sở / chi nhánh thực tế của thương hiệu. Mỗi chi nhánh có tọa độ kinh/vĩ độ, khung giờ mở cửa và địa chỉ hành chính.

### 2.4. Nhóm Dịch Vụ & Danh Mục (Services & Categories)
9. **`service_categories`**: Danh mục phân loại dịch vụ nội bộ của thương hiệu (Chăm sóc da, Làm tóc, Nail Art, Massage trị liệu,...).
10. **`services`**: Dịch vụ cụ thể được cung cấp tại chi nhánh kèm giá niêm yết, giá khuyến mãi và thời lượng thực hiện (phút).
11. **`service_images`**: Thư viện ảnh của dịch vụ phục vụ hiển thị carousel và banner.
12. **`saved_services`**: Bảng nối đa-đa thể hiện danh sách dịch vụ yêu thích của khách hàng.

### 2.5. Nhóm Nhân Sự (Staff & Scheduling)
13. **`staff`**: Đội ngũ chuyên viên, kỹ thuật viên tại từng chi nhánh.
14. **`staff_working_hours`**: Lịch làm việc tuần (Thứ Hai đến Chủ Nhật) cùng khung giờ trực ca của nhân viên.
15. **`staff_services`**: Bảng nối kỹ năng dịch vụ mà nhân viên có thể thực hiện.

### 2.6. Nhóm Đặt Lịch & Đánh Giá (Bookings, Audit Trail & Reviews)
16. **`bookings`**: Đơn hẹn chính giữa khách hàng và chi nhánh, lưu trữ mã đặt lịch định danh duy nhất (VD: `BK20260918-ABCD`), thời gian, tổng chi phí và trạng thái hiện tại.
17. **`booking_items`**: Chi tiết dịch vụ và nhân viên được chỉ định cho từng dịch vụ trong lịch hẹn.
18. **`booking_status_histories`**: Nhật ký kiểm toán (Audit Trail) ghi lại mọi thay đổi trạng thái kèm người thực hiện và ghi chú.
19. **`reviews`**: Đánh giá số sao (1-5), nhận xét của khách hàng sau khi dịch vụ hoàn tất, và phản hồi chính thức từ chủ tiệm.

---

## 3. Chiến Lược Đánh Chỉ Mục & Toàn Vẹn Dữ Liệu (Indexes & Integrity)

- **Khóa chính & Khóa ngoại:** Tất cả bảng giao dịch đều sử dụng `bigint UNSIGNED AUTO_INCREMENT` làm khóa chính và có khóa ngoại `FOREIGN KEY` với các ràng buộc `ON DELETE CASCADE` hoặc `ON DELETE SET NULL` phù hợp.
- **Unique Indexes:**
  - `users.email`
  - `businesses.slug`
  - `bookings.booking_code`
  - `saved_services (user_id, service_id)`
  - `staff_services (staff_id, service_id)`
- **Search & Filter Indexes:**
  - `services (branch_id, status, is_bookable)`
  - `services (category_id, status)`
  - `bookings (customer_id, status, booking_date)`
  - `bookings (branch_id, status, booking_date)`
  - `reviews (branch_id, rating, is_visible)`
- **Soft Deletes:**
  - Áp dụng trên `users`, `businesses`, `branches`, `services`, `staff` để bảo toàn lịch sử giao dịch và thống kê doanh thu ngay cả khi người dùng xóa tài nguyên.
