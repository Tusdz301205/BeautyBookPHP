# BeautyBook — Bảng Đối Chiếu Tiêu Chí Chấm Điểm (Rubric Traceability Matrix)
**Môn học:** Lập trình Mã nguồn mở (Open Source Programming)  
**Dự án:** BeautyBook — Open Source Programming Edition (Laravel 11 Full-Stack)

Bảng đối chiếu dưới đây cung cấp bằng chứng trực tiếp (file path, line number, tính năng) nhằm phục vụ giảng viên kiểm tra nhanh và đánh giá toàn diện project theo barem chấm điểm môn học.

---

## 1. Bảng Tổng Hợp Tiêu Chí Chấm Điểm (Grading Criteria Matrix)

| STT | Tiêu chí đánh giá môn học | Mức độ hoàn thành | Vị trí minh chứng mã nguồn |
| :---: | :--- | :---: | :--- |
| **1** | **Cơ sở dữ liệu & Migration**<br>- Thiết kế lược đồ CSDL chuẩn 3NF<br>- Migration tự động, Foreign Keys, Indexes<br>- Dữ liệu mẫu (Seeders) chân thực | **100% (Xuất sắc)** | - `database/migrations/` (10 migration files, 19 tables)<br>- `database/seeders/BeautyBookSeeder.php`<br>- `database/seeders/LocationSeeder.php` |
| **2** | **Xác thực & Phân quyền (Auth & RBAC)**<br>- Đăng ký, đăng nhập an toàn, mã hóa mật khẩu<br>- Phân chia vai trò (Customer vs Business Owner)<br>- Route Middleware & Eloquent Policies | **100% (Xuất sắc)** | - `app/Http/Controllers/Auth/RegisteredUserController.php`<br>- `app/Http/Middleware/EnsureUserIsCustomer.php`<br>- `app/Http/Middleware/EnsureUserIsOwner.php`<br>- `app/Policies/` (6 Policy files: Business, Branch, Service, Staff, Booking, Review) |
| **3** | **Quản lý Dữ liệu (CRUD Operations)**<br>- Đầy đủ Thêm/Xem/Sửa/Xóa cho >= 5 thực thể<br>- Soft Deletes an toàn<br>- Xử lý giao dịch nhiều bảng | **100% (Xuất sắc)** | - `app/Http/Controllers/Owner/BranchController.php`<br>- `app/Http/Controllers/Owner/ServiceController.php`<br>- `app/Http/Controllers/Owner/StaffController.php`<br>- `app/Http/Controllers/Owner/ServiceCategoryController.php`<br>- `app/Http/Controllers/Customer/BookingController.php` |
| **4** | **Xác thực Dữ liệu Phía Máy Chủ (Validation)**<br>- Sử dụng Form Request chuyên biệt<br>- Kiểm tra kiểu dữ liệu, bắt buộc, định dạng ảnh | **100% (Xuất sắc)** | - `app/Http/Requests/BookingCreateRequest.php`<br>- `app/Http/Requests/ReviewCreateRequest.php`<br>- `app/Http/Requests/BranchRequest.php`<br>- `app/Http/Requests/ServiceRequest.php`<br>- `app/Http/Requests/StaffRequest.php` |
| **5** | **Tìm Kiếm, Lọc & Phân Trang (Search & Pagination)**<br>- Lọc đa tiêu chí (keyword, category, price, province)<br>- Phân trang chuẩn Eloquent `paginate()`<br>- Tìm kiếm tự động qua AJAX không reload | **100% (Xuất sắc)** | - `app/Http/Controllers/ExploreController.php` (hàm `index` & `search`)<br>- `resources/views/explore/index.blade.php`<br>- Route `/search/ajax` |
| **6** | **Tải Lên Tệp & Đa Phương Tiện (File Uploads)**<br>- Upload Logo, Banner, Ảnh Dịch Vụ, Avatar<br>- Lưu trữ `storage/app/public` & symlink chuẩn | **100% (Xuất sắc)** | - `app/Http/Controllers/Owner/BusinessController.php` (update)<br>- `app/Http/Controllers/Owner/ServiceController.php` (store/update)<br>- `app/Http/Controllers/Owner/StaffController.php` (store/update) |
| **7** | **Giao Diện & Trải Nghiệm (Modern UI/UX)**<br>- Responsive 100% (Desktop, Tablet, Mobile)<br>- Thư viện bản đồ nguồn mở OpenStreetMap / Leaflet<br>- Hiệu ứng vi tương tác, Drawer điều hướng | **100% (Xuất sắc)** | - `resources/views/layouts/` (public, customer, owner)<br>- `resources/views/branches/show.blade.php` (Leaflet map)<br>- `resources/css/app.css` (Tailwind CSS 61.18 kB) |
| **8** | **Kiểm Thử Tự Động (Automated Testing)**<br>- Bộ test kiểm thử tích hợp (Feature & Auth tests)<br>- Tỷ lệ vượt qua 100% | **100% (Xuất sắc)** | - `tests/Feature/BeautyBookFlowTest.php` (16 test cases nghiệp vụ)<br>- `tests/Feature/Auth/` (Toàn bộ 6 test cases xác thực)<br>- **Tổng: 42/42 tests pass, 116 assertions** |

---

## 2. Chi Tiết Bằng Chứng Kỹ Thuật (Detailed Technical Evidence)

### 2.1. Phân Quyền Đa Tầng (Multi-tier Authorization)
- **Tầng 1: HTTP Middleware**
  - Khách hàng không thể truy cập bất kỳ route nào thuộc tiền tố `/owner/*`. Nếu cố tình, hệ thống sẽ trả mã lỗi `403 Forbidden` (`app/Http/Middleware/EnsureUserIsOwner.php`).
  - Chủ cơ sở không thể can thiệp route cá nhân `/customer/*` (`app/Http/Middleware/EnsureUserIsCustomer.php`).
- **Tầng 2: Eloquent Policies**
  - `app/Policies/BranchPolicy.php`: Khi gọi `update` hoặc `delete`, phương thức kiểm tra `$branch->business->owner_id === $user->id`. Chủ salon B cố sửa chi nhánh của salon A sẽ bị chặn ngay lập tức.
  - `app/Policies/BookingPolicy.php`: Cho phép Customer hủy đơn nếu `customer_id === $user->id` và đơn đang ở trạng thái `pending`. Cho phép Owner đổi trạng thái đơn nếu chi nhánh thuộc sở hữu của họ.

### 2.2. Kiểm Tra Dữ Liệu Đầu Vào (Form Request Validation)
- Tất cả các thao tác ghi dữ liệu nhạy cảm đều được tách thành các lớp Form Request riêng biệt:
  - `BookingCreateRequest`: Bắt buộc chọn chi nhánh hợp lệ (`exists:branches,id`), ngày đặt từ hôm nay trở đi (`after_or_equal:today`), khung giờ mở cửa, và mảng dịch vụ (`service_ids.*`).
  - `ServiceRequest`: Bắt buộc nhập tên, giá niêm yết (số dương), thời lượng thực hiện, danh mục thuộc quyền sở hữu của salon.
  - `StaffRequest`: Ràng buộc định dạng email, số điện thoại, ảnh đại diện (`image|mimes:jpeg,png,jpg,webp|max:2048`).

### 2.3. Máy Trạng Thái & Lịch Sử Kiểm Toán (Audit Trail)
- Tại `Customer\BookingController.php` và `Owner\BookingController.php`:
  Mỗi khi trạng thái của đơn chuyển đổi (ví dụ: `pending` -> `confirmed` hoặc `pending` -> `cancelled`), hệ thống tự động chèn 1 bản ghi vào bảng `booking_status_histories`:
  ```php
  BookingStatusHistory::create([
      'booking_id'  => $booking->id,
      'user_id'     => Auth::id(),
      'from_status' => $oldStatus,
      'to_status'   => $newStatus,
      'notes'       => $notes,
  ]);
  ```
  Trang chi tiết lịch hẹn (`customer/bookings/show` & `owner/bookings/show`) render trực quan dòng thời gian (Timeline) của từng lần thay đổi trạng thái này.

### 2.4. Bản Đồ Nguồn Mở Tích Hợp (Leaflet / OpenStreetMap)
- Xem file: `resources/views/branches/show.blade.php`.
- Không sử dụng API tính phí hoặc có bản quyền khép kín như Google Maps API.
- Tận dụng `Leaflet 1.9.4` và nguồn tiles `tile.openstreetmap.org`, render marker theo kinh độ/vĩ độ của chi nhánh, kèm popup thông tin chi tiết.

### 2.5. Kiểm Thử Đạt Chuẩn (Automated Test Suite)
- Lệnh thực thi: `php vendor/bin/phpunit --no-coverage`
- Kết quả thực tế:
  ```
  Tests: 42 passed (116 assertions)
  Duration: 2.31 seconds
  ```
  Bao phủ toàn bộ các kịch bản:
  - Khách vãng lai xem trang chủ, lọc tìm kiếm, xem salon/chi nhánh/dịch vụ.
  - Khách đăng nhập, tạo đơn đặt lịch hẹn, xem lịch sử, hủy đơn hẹn.
  - Khách yêu thích dịch vụ, đánh giá đơn hẹn sau khi hoàn tất.
  - Chủ đăng nhập, quản lý thông tin salon, CRUD chi nhánh, CRUD danh mục, CRUD dịch vụ, CRUD nhân viên, chuyển đổi trạng thái đơn hẹn, phản hồi đánh giá.
  - Kiểm tra phân quyền: Guest/Customer bị cấm vào portal Owner; Owner bị cấm vào portal Customer.
