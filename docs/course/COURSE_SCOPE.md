# BeautyBook — Phạm Vi Đề Tài Môn Học (Course Scope)
**Môn học:** Lập trình Mã nguồn mở (Open Source Programming)  
**Phiên bản:** BeautyBook — Open Source Programming Edition (Laravel 11 Full-Stack)

---

## 1. Mục Tiêu & Định Vị Đề Tài

Dự án **BeautyBook — Open Source Programming Edition** được thiết kế riêng nhằm hiện thực hóa các chuẩn mực cốt lõi của môn học **Lập trình mã nguồn mở**:
- Ứng dụng mô hình kiến trúc MVC hướng đối tượng trên nền tảng **PHP 8.3 & Laravel 11**.
- Sử dụng hoàn toàn công nghệ, cơ sở dữ liệu và thư viện **FOSS (Free and Open-Source Software)**: MySQL 8.4, Blade Template Engine, Tailwind CSS, Alpine.js, OpenStreetMap kết hợp Leaflet.js.
- Cung cấp trải nghiệm đặt lịch làm đẹp trực tuyến thực tế, đảm bảo an toàn giao dịch, kiểm soát quyền hạn phân tầng và bảo vệ dữ liệu người dùng.

---

## 2. Phạm Vi Nghiệp Vụ Trong Scope (In-Scope Features)

### 2.1. Phân Hệ Người Dùng & Xác Thực (Authentication & Authorization)
- **Đăng ký tài khoản (Register):** Tùy chọn vai trò trực tiếp (`customer` hoặc `business_owner`). Khởi tạo cơ sở kinh doanh tự động cho chủ tiệm.
- **Đăng nhập & Đăng xuất (Login/Logout):** Xác thực phiên làm việc an toàn với Laravel Breeze, băm mật khẩu chuẩn Bcrypt.
- **Phân quyền 2 tầng (RBAC):**
  - Tầng Middleware: `EnsureUserIsCustomer` và `EnsureUserIsOwner` cô lập hoàn toàn không gian quản trị và không gian khách hàng.
  - Tầng Policy: `BusinessPolicy`, `BranchPolicy`, `ServicePolicy`, `StaffPolicy`, `BookingPolicy`, `ReviewPolicy` bảo vệ dữ liệu giữa các doanh nghiệp độc lập (Multi-tenancy isolation).

### 2.2. Phân Hệ Khám Phá & Tìm Kiếm Công Khai (Public Exploration)
- **Trang chủ & Khám phá dịch vụ:** Hiển thị danh sách dịch vụ nổi bật, danh mục dịch vụ, lọc theo khoảng giá, chi nhánh và địa phương.
- **Tìm kiếm trực tiếp bán đồng bộ (Live AJAX Search):** Tự động gợi ý kết quả khi người dùng nhập từ khóa mà không cần tải lại toàn bộ trang.
- **Xem chi tiết Salon & Chi nhánh:** Tích hợp bản đồ mã nguồn mở **Leaflet / OpenStreetMap** định vị trực quan cơ sở làm đẹp.

### 2.3. Phân Hệ Khách Hàng (Customer Experience)
- **Đặt lịch hẹn trực quan (Booking Flow):** Chọn chi nhánh, ngày hẹn, giờ hẹn và dịch vụ cần làm; tính toán tự động tổng tiền và tổng thời lượng.
- **Quản lý đơn hẹn cá nhân:** Theo dõi danh sách đơn hẹn theo các trạng thái, xem dòng thời gian (Audit Trail) của từng lần thay đổi trạng thái.
- **Hủy đơn hẹn:** Cho phép khách hàng tự hủy đơn hẹn khi đơn còn ở trạng thái `pending`.
- **Đánh giá dịch vụ (Reviews):** Gửi đánh giá sao (1-5 sao) và nhận xét đối với các đơn hẹn đã hoàn thành.
- **Lưu yêu thích (Favorites):** Lưu / bỏ lưu dịch vụ yêu thích với hiệu ứng AJAX tức thì.
- **Quản lý hồ sơ cá nhân:** Cập nhật thông tin và ảnh đại diện.

### 2.4. Phân Hệ Quản Trị Cơ Sở (Business Owner Workspace)
- **Bảng điều khiển doanh thu (Owner Dashboard):** Thống kê số lượng đơn hẹn, doanh thu thực tế, khách hàng và đánh giá trung bình.
- **Hồ sơ Salon (Business Profile):** Cập nhật tên thương hiệu, mô tả, hotline, email, logo và ảnh bìa salon.
- **Quản lý Chi nhánh (Branches CRUD):** Thêm mới cơ sở, địa chỉ chi tiết theo cấp Tỉnh/Quận/Phường, tọa độ bản đồ và giờ đóng/mở cửa.
- **Quản lý Danh mục & Dịch vụ (Categories & Services CRUD):** Phân loại dịch vụ, quản lý giá niêm yết, giá khuyến mãi, thời lượng và thư viện hình ảnh.
- **Quản lý Kỹ thuật viên (Staff CRUD):** Quản lý nhân sự làm đẹp, ảnh đại diện, chức danh và trạng thái công tác.
- **Quản lý Đơn hẹn & Vòng đời trạng thái (Booking Management & State Machine):**
  - Chuyển đổi trạng thái linh hoạt: `pending` ➔ `confirmed` ➔ `in_progress` ➔ `completed` / `cancelled` / `no_show`.
  - Tích hợp AJAX status update trực tiếp trên bảng dữ liệu.
- **Phản hồi Đánh giá (Review Moderation & Reply):** Xem nhận xét của khách hàng và gửi phản hồi chính thức từ salon.

---

## 3. Phạm Vi Ngoài Scope (Explicitly Out-of-Scope)

Để giữ cho repository tập trung tối đa vào tiêu chí học thuật của môn học, tránh phân tán và quá tải vận hành, các tính năng sau đây **chủ động không đưa vào scope**:
- ❌ Cổng thanh toán trực tuyến bên thứ ba (VNPAY, MoMo, ZaloPay, Stripe) — Sử dụng thanh toán trực tiếp tại quầy khi hoàn thành dịch vụ.
- ❌ Hệ thống chấm công nhân sự phức tạp (HR Timesheet, Payroll, Attendance, tính lương).
- ❌ Hồ sơ bệnh án chuyên sâu (Clinical Medical Records, Consultation forms bảo mật y tế).
- ❌ Hạ tầng Real-time phân tán cấp Enterprise (Socket.IO cluster, Redis Pub/Sub, Kafka, distributed locking).
- ❌ Xử lý trí tuệ nhân tạo / Xử lý ngôn ngữ tự nhiên (AI Sentiment Analysis, Machine Learning Recommender).
