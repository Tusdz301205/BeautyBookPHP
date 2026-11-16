# Luồng đặt lịch BeautyBook PHP

Luồng đang dùng (`BEAUTYBOOK_LEGACY_UI=false`) được đối chiếu trực tiếp với mã nguồn GlowMe tại `api/src/bookings`, `web/src/pages/Customer/CustomerBookingForm.jsx`, `CustomerRecurringForm.jsx` và các thành phần booking. Giữ kiến trúc Laravel, ID và dữ liệu PHP; giao diện dùng tông hồng hiện tại.

## Khách hàng

- `/beautybook/book?serviceId=ID` hoặc `?comboId=ID`: dịch vụ/lựa chọn/số lượng → nhân viên → ngày/giờ trống → thông tin liên hệ → kiểm tra giá, ưu đãi và xác nhận.
- Số lượng 1–100; backend nhận nhiều selection của cùng dịch vụ với biến thể, nhân viên riêng. Combo mở rộng thành các dòng dịch vụ thật, giới hạn tổng 100 dòng.
- Mỗi lượt dịch vụ hoặc combo mua chọn một ưu đãi tốt nhất. Ưu đãi cố định tính theo từng lượt; so sánh đường giảm theo dịch vụ gốc với đường giảm trên giá combo. Bảng giá thể hiện cả phần giảm từ combo.
- Giá combo và giảm giá được phân bổ bằng số nguyên với phần dư ổn định; tổng các dòng bằng tổng lịch. Lưu lựa chọn, nhân viên, thời gian và giá tại lúc đặt.
- Có báo giá, danh sách mã ưu đãi, lựa chọn nhân viên tự động, kiểm tra lại giờ trống khi ghi và yêu cầu đồng ý sử dụng thông tin liên hệ.
- Một yêu cầu có khóa idempotency cố định. Nếu mất phản hồi, nút kiểm tra lại gửi chính yêu cầu đó; không phát sinh lịch mới. Chặn thay đổi yêu cầu trong lúc chưa rõ kết quả và kiểm tra phiên đăng nhập.
- `repeat=1`: tuần/hai tuần/tháng, 1–12 lần. Ngày cuối tháng được chặn theo ngày gốc (31/1 → 28 hoặc 29/2 → 31/3). Toàn chuỗi tạo trong một transaction; không tạo dở khi xung đột hoặc thiếu tiền cọc.
- Tiền cọc nhận đúng envelope `terms` của PHP. Lịch mới giữ cọc 30% từ ví khách hàng, mỗi kỳ gửi đúng báo giá cọc của kỳ đó. Thiếu số dư thì chặn đặt lịch hoặc rollback toàn chuỗi; khách nạp ví qua tài khoản nhận chung của nền tảng. Cọc được hoàn về ví hoặc ghi nhận cho cơ sở theo vòng đời/chính sách. Xem [PAYMENT_SETUP.md](PAYMENT_SETUP.md).
- `/beautybook/customer/recurring`: xem kế hoạch, hủy một lần hoặc các lần còn lại.

## Owner và lễ tân

`PENDING → CONFIRMED → CHECKED_IN → COMPLETED`.

Owner/lễ tân xác nhận, từ chối có lý do, check-in, hủy hoặc đánh dấu vắng theo quyền và thời gian. Chỉ owner hoàn tất lịch sau check-in; không cần bắt đầu hay hoàn thành từng dịch vụ và không phải chờ hết thời lượng dự kiến. Mỗi lệnh HTTP đổi trạng thái phải gửi `expectedVersion`; dữ liệu đã thay đổi trả xung đột để tải lại.

Giao diện mới không có hiệu chỉnh giờ thực tế, thêm/sửa từng dòng, phân công lại trực tiếp, kéo/đổi độ dài lịch hay tạo lịch tại quầy. Các API sửa lịch/từng dòng cũ bị middleware chặn khi tắt legacy UI. Dữ liệu lịch cũ, trạng thái `IN_PROGRESS` và các cột thời gian thực tế vẫn được giữ để đọc lịch sử và xử lý lịch đang tồn tại.

## Kiểm tra

Tất cả bài kiểm thử DB chạy trên `mysql_test/beautybook_test`, có kiểm tra cấu hình và khóa tránh hai suite cùng sửa dữ liệu. Không reset hoặc seed DB ứng dụng thật.

- Hồi quy backend: API booking, giỏ dịch vụ, vòng đời, đổi/hủy, ưu đãi, tiền cọc từng kỳ và tranh chấp đồng thời.
- `BookingRecipeFlowTest`: nhiều đơn vị, lựa chọn/nhân viên riêng, combo, tổng giá, replay, phiên bản, hết hạn, từ chối, định kỳ và tắt API vận hành từng dòng, ưu đãi cố định từng lượt và trả lượt combo khi hủy.
- `RecurringDepositQuoteTest`: recipe mới với cọc chuyển khoản theo từng ngày; ví thiếu tiền rollback toàn chuỗi rồi tạo thành công sau nạp thêm.
- Node: lịch tháng/ngày Việt Nam, envelope tiền cọc, tổng cọc khác nhau giữa các kỳ, điều kiện số dư, cùng các kiểm thử frontend hiện có.
- Chrome thật trên ứng dụng QA riêng: 5 bước, số lượng 2, tìm/áp mã, giả lập mất phản hồi sau khi lưu rồi replay, combo, tạo/hủy định kỳ, owner hoàn tất và màn hình 375px.

Các thử nghiệm không thực hiện chuyển khoản ngân hàng thật.

Luồng cọc hiện tại theo quyết định mới nhất: ví khách hàng → giữ cọc 30% → ghi nhận cho cơ sở khi đủ điều kiện → yêu cầu rút/chi trả. Chi trả ra ngân hàng hiện được mô phỏng. Lịch sử từng dùng cọc trực tiếp giữ nguyên nguồn tiền; không tự chuyển đổi lịch sử. Tải trước route theo tương tác, chạy báo giá/giờ trống song song; các lệnh ghi vẫn kiểm tra lại trên server.

Kiểm tra sửa lỗi: 37 backend (321 assertions), 181 frontend và 14 kiểm tra Chrome đạt; build thành công. Đã thử cả hạn chế vừa hết hạn khi form mở, tải lại giờ sau 422, và không tự gửi form khi quay lại bước xác nhận. Các số này là lượt kiểm tra liên quan đến thay đổi, không phải khẳng định đã chạy toàn bộ hệ thống.

Kết quả ngày 13/11/2026: nhóm hồi quy 82 ca đã sửa đủ 3 lỗi phát hiện và kiểm tra lại thành công; bổ sung lựa chọn riêng, quy tắc pending, cọc recipe và ưu đãi từng lượt. Lượt backend cuối đạt 41/41 (244 assertions); nhóm vòng đời/cọc trước đó đạt 28/28 (254 assertions). Frontend đạt 177/177. Build production thành công. Chrome đạt 9 kiểm tra luồng, không có lỗi JavaScript hoặc API chưa xử lý. Các ca trùng giữa lượt hồi quy và lượt kiểm tra lại không cộng vào số ca độc lập.
