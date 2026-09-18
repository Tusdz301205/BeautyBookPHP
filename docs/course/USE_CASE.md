# BeautyBook — Đặc Tả Ca Sử Dụng (Use Case Specification)
**Môn học:** Lập trình Mã nguồn mở (Open Source Programming)  
**Phiên bản:** BeautyBook — Open Source Programming Edition (Laravel 11 Full-Stack)

---

## 1. Sơ Đồ Ca Sử Dụng Tổng Quát (Mermaid Use Case Diagram)

```mermaid
graph LR
    subgraph Actors
        Guest["Khách vãng lai (Guest)"]
        Customer["Khách hàng (Customer)"]
        Owner["Chủ cơ sở (Business Owner)"]
    end

    subgraph "Khám phá & Tìm kiếm (Public)"
        UC1["UC-01: Xem danh sách dịch vụ / salon"]
        UC2["UC-02: Tìm kiếm dịch vụ (Live AJAX)"]
        UC3["UC-03: Lọc theo danh mục, giá, địa phương"]
        UC4["UC-04: Xem chi tiết chi nhánh & bản đồ Leaflet"]
    end

    subgraph "Xác thực (Authentication)"
        UC5["UC-05: Đăng ký tài khoản (Chọn vai trò)"]
        UC6["UC-06: Đăng nhập & Đăng xuất"]
    end

    subgraph "Nghiệp vụ Khách hàng (Customer Portal)"
        UC7["UC-07: Đặt lịch hẹn trực tuyến"]
        UC8["UC-08: Xem lịch sử & dòng thời gian đơn hẹn"]
        UC9["UC-09: Hủy đơn hẹn (khi còn Pending)"]
        UC10["UC-10: Đánh giá & nhận xét sau khi hoàn thành"]
        UC11["UC-11: Lưu / bỏ lưu dịch vụ yêu thích (AJAX)"]
    end

    subgraph "Quản trị Cơ sở (Owner Portal)"
        UC12["UC-12: Quản lý thông tin Salon & Upload ảnh"]
        UC13["UC-13: CRUD Chi nhánh cơ sở"]
        UC14["UC-14: CRUD Danh mục & Dịch vụ làm đẹp"]
        UC15["UC-15: CRUD Đội ngũ kỹ thuật viên"]
        UC16["UC-16: Quản lý & Cập nhật trạng thái đơn hẹn"]
        UC17["UC-17: Phản hồi đánh giá của khách hàng"]
        UC18["UC-18: Xem báo cáo thống kê doanh thu"]
    end

    Guest --> UC1
    Guest --> UC2
    Guest --> UC3
    Guest --> UC4
    Guest --> UC5
    Guest --> UC6

    Customer --> UC6
    Customer --> UC7
    Customer --> UC8
    Customer --> UC9
    Customer --> UC10
    Customer --> UC11

    Owner --> UC6
    Owner --> UC12
    Owner --> UC13
    Owner --> UC14
    Owner --> UC15
    Owner --> UC16
    Owner --> UC17
    Owner --> UC18
```

---

## 2. Chi Tiết Các Luồng Nghiệp Vụ Chính (Core Scenarios)

### 2.1. Đặt Lịch Hẹn Trực Tuyến (Customer Booking Flow)
- **Actor:** Khách hàng (Customer).
- **Điều kiện tiên quyết:** Đã đăng nhập vào hệ thống với vai trò `customer`.
- **Luồng chính:**
  1. Khách hàng xem trang dịch vụ hoặc chi nhánh, bấm nút **"Đặt lịch hẹn"**.
  2. Hệ thống chuyển hướng tới giao diện đặt hẹn (`/customer/bookings/create`).
  3. Khách hàng lựa chọn:
     - Chi nhánh làm đẹp;
     - Ngày thực hiện (tối thiểu từ ngày hiện tại);
     - Khung giờ mong muốn;
     - Một hoặc nhiều dịch vụ cần làm;
     - Ghi chú thêm (nếu có).
  4. Hệ thống tự động tính toán tổng số tiền thanh toán dự kiến và tổng thời lượng thực hiện.
  5. Khách hàng gửi yêu cầu; Form Request `BookingCreateRequest` kiểm tra tính hợp lệ dữ liệu phía máy chủ.
  6. Hệ thống tạo mã đặt lịch duy nhất (`BK-YYYYMMDD-XXXX`), lưu thông tin với trạng thái `pending`, ghi nhận bản ghi kiểm toán vào `booking_status_histories` và chuyển hướng về trang chi tiết đơn hẹn.

### 2.2. Xử Lý Vòng Đời Đơn Hẹn (Owner Booking Management Flow)
- **Actor:** Chủ cơ sở làm đẹp (Business Owner).
- **Luồng chính:**
  1. Chủ cơ sở truy cập portal quản trị (`/owner/bookings`).
  2. Bảng dữ liệu hiển thị toàn bộ đơn hẹn của các chi nhánh trực thuộc, phân loại theo trạng thái.
  3. Chủ cơ sở có thể thay đổi trạng thái đơn hẹn nhanh chóng qua hộp chọn AJAX:
     - `pending` ➔ `confirmed`: Xác nhận tiếp nhận lịch;
     - `confirmed` ➔ `in_progress`: Khách đã đến và bắt đầu thực hiện;
     - `in_progress` ➔ `completed`: Khách đã làm xong dịch vụ;
     - Chuyển sang `cancelled` (kèm lý do) hoặc `no_show` (nếu khách vắng mặt).
  4. Mỗi lần thay đổi trạng thái, hệ thống kích hoạt tạo bản ghi lịch sử kiểm toán ghi nhận người thao tác và thời điểm.
