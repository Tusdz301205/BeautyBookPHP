# Ví khách hàng và tiền cọc 30%

Theo quyết định mới nhất ngày 14/11/2026, BeautyBook dùng mô hình ví như GlowMe: **khách hàng nạp tiền vào ví của mình**, tiền chuyển vào tài khoản nhận chung của nền tảng. Tài khoản ngân hàng của cơ sở dùng để nhận chi trả, không phải tài khoản nạp ví của khách.

## Luồng sử dụng

1. Khách mở **Ví của tôi**, nhập số tiền và tạo mã nạp ví. Chuyển đúng số tiền/nội dung vào tài khoản chung được hiển thị.
2. Hệ thống chỉ cộng số dư khi nhận được thông báo SePay hợp lệ. Thông báo gửi lặp không cộng tiền lần hai. Khoản sai số tiền, nội dung hoặc đã hết hạn được chuyển sang đối soát.
3. Bước cuối đặt lịch hiển thị **cọc 30% từ ví**, số dư và phần còn lại. Thiếu số dư thì cần nạp thêm trước khi xác nhận. Đặt định kỳ cần đủ tổng cọc của cả chuỗi.
4. Khi xác nhận đặt lịch, cọc được giữ trong ví; chưa trở thành số dư có thể rút của cơ sở. Không yêu cầu khách chuyển cọc lần hai.
5. Hủy đúng chính sách sẽ hoàn cọc về ví. Khi hoàn tất dịch vụ hoặc khách mất cọc theo chính sách, cọc được ghi nhận cho cơ sở. Phần tiền còn lại thu tại cơ sở.
6. Chủ cơ sở mở **Ví & thanh toán** để xem số dư có thể rút, cọc đang giữ, tiền đang giữ để chi trả, lịch sử và đăng ký tài khoản nhận tiền. Gửi yêu cầu rút tiền hoặc quản trị viên tạo đợt chi trả.

**Chi trả ra ngân hàng hiện là mô phỏng**, chưa có lệnh chuyển tiền ngân hàng thật. Giao diện ghi rõ điều này. Việc nạp ví qua SePay và việc chi trả cho cơ sở là hai luồng riêng.

## Cấu hình nạp tiền thật

```dotenv
WALLET_BOOKING_DEPOSIT_ENABLED=true
DIRECT_DEPOSIT_ENABLED=false
BEAUTYBOOK_PAYMENT_MODE=
SEPAY_WALLET_ENABLED=true
SEPAY_WALLET_BANK_CODE=<ma ngan hang duoc ho tro>
SEPAY_WALLET_ACCOUNT_NUMBER=<tai khoan nhan chung>
SEPAY_WALLET_ACCOUNT_NAME=<ten chu tai khoan>
SEPAY_WALLET_WEBHOOK_SECRET=<khoa bi mat du dai>
APP_URL=https://dia-chi-cong-khai-cua-ban
```

1. Kết nối tài khoản nhận chung với SePay và kiểm tra ngân hàng/loại tài khoản có hỗ trợ thông báo giao dịch cho kết nối đó.
2. Trong SePay, cấu hình thông báo **tiền vào**, kiểu dữ liệu **JSON**, URL `https://dia-chi-cong-khai-cua-ban/api/v1/payments/sepay/wallet-live-lab/webhook`.
3. Dùng **HMAC-SHA256** với cùng khóa bí mật ở `SEPAY_WALLET_WEBHOOK_SECRET`. Không đưa khóa vào chat/Git hay giao diện khách hàng.
4. Áp dụng cấu hình bằng `npm start`. Localhost cần địa chỉ HTTPS công khai trỏ về ứng dụng để nhận thông báo từ SePay.
5. Kiểm tra một khoản nạp nhỏ: đúng người nhận, số tiền và mã; chỉ ghi nhận thành công sau thông báo hợp lệ. Chưa đối chiếu ngân hàng thật thì không coi là đã kiểm thử thanh toán thật.

Tên đường dẫn chứa `wallet-live-lab` là tên API tương thích cũ; giao diện khách dùng tên **Ví của tôi**. Danh sách ngân hàng trong mã nguồn không thay thế việc kiểm tra khả năng hỗ trợ của tài khoản/kết nối SePay thực tế.

## Trạng thái máy hiện tại

- Đã bật mô hình ví và cọc 30%; đã tắt chế độ cọc trực tiếp cho lịch mới.
- `SEPAY_WALLET_ENABLED=true`; đã cấu hình tài khoản nhận chung ACB và khóa webhook riêng trong `.env` theo yêu cầu. Biểu mẫu nạp ví đã xuất hiện trên giao diện khách.
- Thông tin tài khoản do người dùng xác nhận trực tiếp; không thêm tiền giả hoặc thay đổi số dư trên DB chính. Khóa và thông tin nhận tiền chỉ lưu trong `.env` được Git bỏ qua.
- Các cọc chuyển khoản trực tiếp và ví lịch sử giữ nguyên nguồn tiền/điều khoản gốc để tiếp tục xử lý đúng. Giữ nguyên khóa mã hóa kết nối cũ nếu đã có.
- Các thử nghiệm ngân hàng giả lập chỉ chạy trên DB test.

Kiểm tra cấu hình SePay sau khi thiết lập: Laravel đọc cấu hình hợp lệ; ngrok trả HTTP 200 ở `/up`; endpoint webhook trả 401 cho yêu cầu không ký; Chrome xác nhận biểu mẫu nạp tiền hiện trên trang ví. Không tạo yêu cầu nạp giả, không cộng tiền thử trên DB chính. Cần cấu hình đúng URL/HMAC-SHA256/JSON/tiền vào trong SePay và thử một yêu cầu nạp thật để xác nhận kết nối ngân hàng.

Xác nhận 15/11/2026: đã xử lý thành công một khoản nạp thật 2.000đ bằng thông báo ngân hàng gốc có chữ ký SePay. Thông báo ban đầu gửi nhầm đường dẫn `/` nên nhận HTTP 302; gửi lại nguyên nội dung/chữ ký còn hiệu lực vào đúng endpoint đã ghi nhận tiền. Cần sửa URL webhook trong SePay thành đầy đủ `/api/v1/payments/sepay/wallet-live-lab/webhook` để lần nạp sau tự động xử lý. Không sửa tay số dư hoặc tạo thông báo ngân hàng giả.
