# Chuyển sang cọc trực tiếp — 14/11/2026

> Báo cáo lịch sử của lượt triển khai cọc trực tiếp. Quyết định mới hơn đã khôi phục ví khách hàng như GlowMe; cấu hình và hành vi hiện tại xem [PAYMENT_SETUP.md](PAYMENT_SETUP.md).

Quyết định của người dùng: tiền cọc chuyển thẳng vào tài khoản của cơ sở. Lịch mới giữ mô hình cọc 30%, phần còn lại thu tại cơ sở; khách không cần nạp ví và owner không phải rút tiền từ nền tảng.

## Đã triển khai

- Bật `DIRECT_DEPOSIT_ENABLED`; tắt nạp ví chung và giữ cọc từ ví. Tạo/giữ nguyên khóa mã hóa secret trong `.env`, không xuất khóa ra log/Git.
- Owner có hai mục chính: **Thanh toán lịch hẹn** và **Tài khoản nhận cọc**. Cấu hình tài khoản/SePay riêng của cơ sở; các công cụ ví cũ nằm trong mục lịch sử.
- Khách xem cọc và người nhận trước khi gửi lịch, sau đó nhận mã chuyển khoản của từng lịch. Giao diện không yêu cầu số dư ví cho lịch mới.
- Cơ sở chưa có kết nối đã xác minh/đang bật: preview báo lý do, frontend khóa xác nhận, backend từ chối tạo lịch có phí. Dịch vụ miễn phí không yêu cầu cọc.
- Chỉ webhook ký hợp lệ, đúng tài khoản/mã/số tiền/thời hạn mới ghi nhận thanh toán. Quote ràng buộc cả phiên bản và thông tin tài khoản; thay đổi tài khoản yêu cầu chấp nhận báo giá mới.
- Lịch định kỳ kiểm tra điều kiện từng lần; không tạo dở chuỗi khi chưa sẵn sàng nhận cọc.
- Giữ nguyên nguồn tiền và điều khoản lịch sử. Lịch/cọc/số dư cũ không bị xóa hoặc tự chuyển đổi. Luồng hoàn cọc trực tiếp dùng tài khoản khách → cơ sở báo chuyển trả → khách xác nhận.

## Kiểm tra

- Toàn nhóm `DirectDeposit`, `RecurringDepositQuote`, `BookingRecipeFlow`, `BookingDepositLifecycle`: 83 testcase đạt sau khi chạy lại các fixture được sửa. Hai lỗi fixture: tạo bản ghi vi phạm CHECK constraint thay vì kiểm tra API từ chối; secret sai được tạo bằng cách thay ký tự đầu thành `f`, đôi khi trùng secret thật. Đã sửa fixture, giữ kiểm tra API/xác thực.
- 185 test frontend và 5 parity test đạt; production build thành công.
- Chrome QA port 8013, DB `mysql_test/beautybook_test`: thiếu tài khoản bị khóa trên UI; owner tạo kết nối Test/xác minh/bật; khách đặt lịch không qua ví và thấy đúng người nhận của cơ sở; callback ký ghi nhận cọc và callback trùng không tạo thanh toán mới. Kiểm tra không tràn ở 320/375/768/1440px; không có page exception.
- Chrome trên ứng dụng chính chỉ đăng nhập/đọc: owner thấy giao diện mới và cấu hình được tài khoản, khách thấy ví lịch sử; 6 mẫu theo vai trò/chiều rộng đạt.
- Cấu hình thực tế sau `npm start`: direct deposit bật, wallet mode và nạp ví chung tắt. Chưa có kết nối Live đã xác minh/đang bật; chưa thử chuyển tiền ngân hàng thật.

Artifact: `storage/app/private/direct-switch-qa/`, JUnit `storage/app/private/direct-switch-phpunit*.xml`, log `storage/logs/direct-switch-*.log`. Browser acceptance có mã trong `tests/Browser/direct-switch.mjs`; webhook test ký bằng secret tạm, không lưu secret vào report.

## Bước cấu hình để nhận tiền thật

Owner cần cung cấp tài khoản nhận tiền của cơ sở, kết nối SePay và URL webhook HTTPS công khai. Không cấu hình ngân hàng mẫu trên DB chính. Hướng dẫn hiện hành ở [PAYMENT_SETUP.md](PAYMENT_SETUP.md). Số dư ví nhập trước đây tiếp tục được giữ và xử lý theo nguồn gốc, không tự quyết toán thành giao dịch ngân hàng thật.
