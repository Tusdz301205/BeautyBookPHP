# Ví GlowMe và rà soát dịch thuật — 14/11/2026

> Thuật ngữ giao diện được chỉnh tiếp theo ngữ cảnh: xem [danh sách thay đổi](TERMINOLOGY_REVIEW_2026-11-14.md). Các số kiểm thử bên dưới là bằng chứng của lượt triển khai ví trước đó.

## Kết quả

Khôi phục mô hình ví khách hàng: khách nạp vào tài khoản chung của nền tảng, đặt lịch giữ cọc 30% từ ví. Cọc chỉ được ghi nhận cho cơ sở khi đủ điều kiện; chủ cơ sở xem số dư, đăng ký tài khoản nhận, yêu cầu rút và theo dõi quyết toán. Bố cục tài chính ưu tiên bốn số dư và yêu cầu rút, các nghiệp vụ phụ nằm trong mục mở rộng. Giữ nguyên nguồn tiền của các lịch lịch sử.

Trên máy hiện tại: wallet mode bật, direct deposit tắt, nạp tiền mới qua SePay tắt vì thiếu cấu hình nhận chung. Không thêm tiền giả hay cấu hình tài khoản từ ảnh QR lên DB chính. Quyết toán ra ngân hàng vẫn là mô phỏng và được ghi rõ trên giao diện. Xem [thiết lập ví](PAYMENT_SETUP.md).

## Dịch thuật

- Rà các nhóm trang công khai/đăng nhập/khách hàng/chủ cơ sở/lễ tân/nhân viên/quản trị, biểu mẫu và hộp thoại.
- Việt hóa câu trộn tiếng Anh, nhãn lựa chọn, trạng thái lịch/ưu đãi, phương thức thanh toán, sổ tài chính, nguồn đặt lịch, bảo mật và chú giải biểu đồ.
- Dùng chung từ điển trạng thái và nhãn thanh toán. Bổ sung 69 thông báo API cùng các thông báo ví; dịch tại giao diện để giữ nguyên hợp đồng API.
- Bổ sung 5 tệp ngôn ngữ Laravel: xác thực, mật khẩu, phân trang, xác minh email, email đặt lại mật khẩu và 137 nhóm thông báo kiểm tra biểu mẫu. Rà 30 mẫu Blade có nội dung cần đổi. Mặc định ứng dụng dùng tiếng Việt.
- Giữ nguyên mã API/enum/route, tên thương hiệu, dữ liệu do người dùng nhập, định dạng tệp và thuật ngữ kỹ thuật cần đối chiếu khi cài SePay. Không dịch bình luận mã nguồn hoặc nội dung riêng của khách hàng.

## Bằng chứng kiểm tra lần này

| Nhóm | Kết quả |
| --- | --- |
| Backend ví/cọc/rút/quyết toán/đồng thời | 64 ca đạt, 1.127 assertions |
| Kiểm thử frontend | 187 đạt |
| Kiểm tra quyền và route | 5 đạt |
| Chrome: nạp ví → webhook ký hợp lệ/gửi lặp → giữ cọc → lịch sử và owner | 9 checkpoint đạt |
| Chrome: tài khoản ngân hàng/rút tiền/mất phản hồi/gửi lại/hủy/duyệt/quyết toán | 12 checkpoint đạt |
| Giao diện theo vai trò và trang công khai | 55 lượt trang × 5 kích thước = 275 mẫu |
| Tài chính owner/lễ tân/admin, bàn phím và tải phần phụ | 15 mẫu đạt |
| Giao diện nhân viên trên QA | 5 trang × 5 kích thước = 25 mẫu |
| Laravel | Biên dịch Blade đạt; kiểm tra thực thi thông báo biểu mẫu và biến trong câu email đạt; không gửi mail |

300 mẫu giao diện trên không có lỗi JavaScript, API chưa phục hồi, ảnh hỏng, nút thiếu tên, ID trùng hoặc tràn bố cục. Không phát hiện nhãn tiếng Anh/mã máy trong nhóm điều khiển được quét. Phản hồi 401 tạm thời chỉ được coi là đã phục hồi khi có phản hồi thành công cùng URL và phương thức. Một lượt kiểm tra tài chính riêng gặp giới hạn đăng nhập 429 khi chạy đồng thời; bộ kiểm tra đã được sửa để chờ đúng hạn rồi thử lại, không thay đổi giới hạn của ứng dụng.

Các ca DB ghi dữ liệu đều dùng `mysql_test/beautybook_test`, kiểm tra cấu hình và khóa suite. DB ứng dụng chính chỉ được đọc khi duyệt giao diện. Kết quả lần này là hồi quy cho phần thay đổi, không phải một lần chạy lại toàn bộ backend của dự án. Chưa thử chuyển tiền ngân hàng thật hoặc giao email SMTP thật.

Bằng chứng JSON và ảnh nằm trong `storage/app/private/wallet-return-qa`, `owner-finance-qa`, `fe-review`; được giữ ngoài Git để tránh đưa dữ liệu kiểm thử/phiên vào kho mã.
