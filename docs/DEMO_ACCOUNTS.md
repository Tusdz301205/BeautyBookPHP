# Tài khoản demo trên database local đã nhập

Cập nhật đến ngày 16/11/2026. Đây là tài khoản trên database PHP local đã nhập dữ liệu GlowMe; không được tạo tự động khi clone repository. Với database mới dùng seeder, xem bảng tài khoản `@beautybook.local` trong [README](../README.md#tài-khoản-demo).

Mật khẩu demo của 7 tài khoản dưới đây: `BeautyBook123!`. Các tài khoản khác giữ mật khẩu riêng.

| Vai trò | Email |
|---|---|
| Admin | `admin@beautybook.vn` |
| Chủ cơ sở Da Mộc | `owner.damoc@beautybook.vn` |
| Chủ cơ sở Lụa | `owner.lua@beautybook.vn` |
| Lễ tân Tân Định | `letan.tandinh@beautybook.vn` |
| Chủ cơ sở An Trú · Hiệp Bình | `owner.antru@beautybook.vn` |
| Lễ tân An Trú · Hiệp Bình | `letan.hiepbinh@beautybook.vn` |
| Khách hàng | `mai.thi.nga.0098@beautybook.vn` |

Đăng nhập tại [BeautyBook local](http://localhost:8000/beautybook/login). Dùng owner Da Mộc để demo màn hình AI. Đánh giá mới có nội dung được worker phân tích; trạng thái backfill lịch sử được ghi tại [ML_SETUP](ML_SETUP.md).

Đã đổi 213 email tài khoản và email trong liên hệ lịch hẹn/doanh nghiệp/metadata hiển thị, cùng tên thương hiệu, thông báo và slug/code danh mục sang BeautyBook. Mật khẩu các tài khoản khác không đổi. Link/OTP khôi phục gắn với email cũ bị vô hiệu hóa; phiên của 5 tài khoản đổi mật khẩu đã thu hồi trước khi kiểm tra đăng nhập mới.

Ngày 16/11/2026, hai tài khoản An Trú · Hiệp Bình đã được đặt lại mật khẩu, thu hồi phiên cũ và kiểm tra đăng nhập/đăng xuất qua HTTP thành công. Thay đổi nằm trong database local, không nằm trong seed hay migration.

Giữ mã đối chiếu nguồn, mã phiên bản chính sách, idempotency key, lịch sử kiểm toán và đường dẫn tệp ảnh để giữ liên kết hoạt động. Không sửa snapshot dữ liệu nhập dùng đối chiếu kỹ thuật.
