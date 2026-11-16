# Rà thuật ngữ theo ngữ cảnh — 14/11/2026

Đã áp dụng trực tiếp trên project. Dùng **rút tiền** cho yêu cầu của chủ cơ sở, **chi trả** cho việc nền tảng trả tiền cho cơ sở, **đối soát** cho so khớp giao dịch nạp ví và **bảng kê phí nền tảng** cho phần tổng hợp phí. Bỏ “quyết toán” khỏi các nhãn đang hoạt động vì không mô tả rõ thao tác cụ thể ở đây.

## Danh sách thuật ngữ

| Trước | Sau | Ngữ cảnh/lý do |
| --- | --- | --- |
| Tài chính và quyết toán | **Số dư và rút tiền** | Tên trang phản ánh số dư và thao tác của chủ cơ sở. |
| Yêu cầu quyết toán / Lịch sử quyết toán | **Yêu cầu rút tiền / Lịch sử rút tiền** | Đây là các yêu cầu do chủ cơ sở gửi. |
| Mã quyết toán | **Mã yêu cầu** | Mã định danh một yêu cầu rút tiền. |
| Có thể nhận | **Có thể rút** | Số tiền còn được phép tạo yêu cầu rút. |
| Đang quyết toán / Đang giữ cho yêu cầu | **Đang giữ để chi trả** | Gồm tiền đã giữ cho yêu cầu rút hoặc đợt chi trả chưa hoàn tất, kể cả yêu cầu cần xử lý lại. |
| Đã quyết toán / Đã rút (mô phỏng) | **Đã chi trả (mô phỏng)** | Nêu rõ việc xử lý mô phỏng, không khẳng định đã chuyển tiền ngân hàng thật. |
| Đối soát theo đợt / Tạo đợt đối soát lô | **Chi trả theo đợt / Tạo đợt chi trả** | Chức năng gom khoản tiền cơ sở được nhận và đánh dấu chi trả, không chỉ so khớp số liệu. |
| Các kỳ quyết toán trước đây | **Lịch sử chi trả theo đợt** | Phân biệt lịch sử đợt chi trả do quản trị viên tạo với lịch sử yêu cầu rút. |
| Có thể yêu cầu quyết toán / Đã đưa vào kỳ quyết toán | **Có thể rút / Đã đưa vào đợt chi trả** | Nhãn trạng thái khoản cọc cơ sở được nhận. |
| Đối soát nền tảng / Đối soát phí nền tảng | **Bảng kê phí nền tảng** | Đây là bảng tổng hợp phí và điều chỉnh phí, không phải tiền chi trả cho cơ sở. |
| Đang đối soát (REVIEW của bảng kê) | **Chờ duyệt** | Trạng thái bảng kê trước khi phát hành. |
| Mã đối soát | **Mã giao dịch ngân hàng** | Thông tin cần nhập khi xác minh giao dịch chuyển khoản. |
| Cần đối soát (phía khách hàng) | **Giao dịch đang được kiểm tra** | Khách không cần thuật ngữ nghiệp vụ để biết khoản nạp đang được xem xét. |
| Đối soát ví | **Đối soát nạp ví** | Nêu đúng phạm vi quản trị viên đang kiểm tra. |
| Biên nhận cần đối soát | **Giao dịch cần đối soát** | Dữ liệu là giao dịch nhận từ ngân hàng, không phải chứng từ biên nhận do hệ thống phát hành. |
| Đến ngày (không gồm) | **Trước ngày** | Mốc cuối không được tính vào khoảng gom khoản chi trả. |
| Cộng tiền vào ledger | **Ghi nhận vào số tiền đã thanh toán của lịch hẹn** | Mô tả tác dụng nghiệp vụ thay vì chi tiết kỹ thuật. |
| Yêu cầu liên kết mới | **Yêu cầu mã đặt lại mật khẩu** | Đích đến là luồng gửi mã qua email. |
| Tạm ngưng nhận lịch (trạng thái nhân sự INACTIVE) | **Đã ngừng làm việc** | Trạng thái nhân sự tách biệt với tùy chọn cho phép nhận lịch. |
| Cấp tài khoản | **Gửi lời mời tạo tài khoản** | Thao tác chỉ gửi lời mời; người nhận phải chấp nhận trước khi có tài khoản liên kết. |
| Cấp tài khoản (nhãn nhóm lựa chọn khi tạo hồ sơ) | **Tài khoản đăng nhập** | Nhóm lựa chọn bao gồm để sau hoặc gửi lời mời qua email. |
| Chưa cấp tài khoản | **Chưa có tài khoản đăng nhập** | Mô tả trạng thái thay vì ngụ ý luôn có thao tác cấp ngay. |
| Đang chờ AI phân tích | **Đang xử lý phân tích** | Nhãn gộp hàng đợi, đang chạy và chờ thử lại, không khẳng định tác vụ chỉ đang chờ. |
| Force-cancel theo ngoại lệ / Force-cancel lịch hẹn | **Hủy lịch theo quyền quản trị** | Thao tác đặc biệt thuộc quyền quản trị viên và cần lý do. |
| Xác nhận force-cancel | **Xác nhận hủy lịch** | Nút xác nhận bằng tiếng Việt, trong hộp thoại quản trị rõ ràng. |

Các câu thông báo thành công, báo lỗi, trạng thái, phân trang, tiêu đề và nhãn hỗ trợ được đổi theo cùng thuật ngữ. Nhãn mô phỏng vẫn hiển thị; không biến mô phỏng thành lời xác nhận tiền thật.

## Kiểm tra

- 187 kiểm thử frontend và 5 kiểm tra quyền/route đạt.
- `WalletSettlementTest`: 5 ca đạt, 95 assertions, chỉ chạy trên DB test được bảo vệ.
- Build giao diện thành công; PHP lint và biên dịch Blade đạt.
- Playwright trên ứng dụng đang chạy: 15 mẫu tài chính owner/lễ tân/admin ở 320, 375, 414, 768, 1440px đạt; không tràn ngang, ảnh hỏng hoặc nút thiếu tên. Kiểm tra thêm nút yêu cầu mã đặt lại mật khẩu và đúng đường dẫn, không gửi email.
- Đã xem lại ảnh desktop của trang số dư/rút tiền. Các route, enum, quyền và phép tính tiền được giữ nguyên. Không ghi đổi số dư, tài khoản hay giao dịch trong DB chính.

Các thông báo mới do server tạo dùng từ mới. Nội dung thông báo lịch sử đã lưu trong DB không bị viết lại. Báo cáo kiểm thử cũ giữ nguyên nội dung lịch sử; hướng dẫn hiện hành đã được cập nhật.

## Đối chiếu câu cụ thể trong mã nguồn

Bảng dưới lưu các cặp câu chính xác, gộp những cặp giống nhau ở nhiều tệp. Tên enum/biểu thức đi kèm chỉ thể hiện vị trí sửa nhãn; định danh API không bị đổi.

| Cũ | Mới |
| --- | --- |
| `Quyết toán hiện được xử lý mô phỏng, chưa chuyển tiền ngân hàng.` | `Việc chi trả hiện được mô phỏng, chưa chuyển tiền qua ngân hàng.` |
| `Theo dõi tiền cọc và yêu cầu nhận số dư của bạn.` | `Theo dõi tiền cọc, số dư có thể rút và yêu cầu rút tiền.` |
| `Các kỳ quyết toán trước đây` | `Lịch sử chi trả theo đợt` |
| `Có thể yêu cầu quyết toán` | `Có thể rút` |
| `Đã đưa vào kỳ quyết toán` | `Đã đưa vào đợt chi trả` |
| `Tài chính và quyết toán` | `Số dư và rút tiền` |
| `Phân trang quyết toán` | `Phân trang rút tiền` |
| `Yêu cầu quyết toán` | `Yêu cầu rút tiền` |
| `Lịch sử quyết toán` | `Lịch sử rút tiền` |
| `yêu cầu quyết toán` | `yêu cầu rút tiền` |
| `số dư có thể nhận` | `số dư có thể rút` |
| `Đang quyết toán` | `Đang giữ để chi trả` |
| `Đã quyết toán` | `Đã chi trả (mô phỏng)` |
| `Mã quyết toán` | `Mã yêu cầu` |
| `Có thể nhận` | `Có thể rút` |
| `SIMULATED_COMPLETED: 'Hoàn tất (mô phỏng)'` | `SIMULATED_COMPLETED: 'Đã chi trả (mô phỏng)'` |
| `SIMULATED_PAID: 'Đã hoàn tất (mô phỏng)'` | `SIMULATED_PAID: 'Đã chi trả (mô phỏng)'` |
| `Không thể tải dữ liệu đối soát` | `Không thể tải dữ liệu ví` |
| `Cọc đang giữ (chưa hoàn tất)` | `Cọc đang giữ` |
| `Đang giữ cho yêu cầu` | `Đang giữ để chi trả` |
| `Đối soát theo đợt` | `Chi trả theo đợt` |
| `Đã rút (mô phỏng)` | `Đã chi trả (mô phỏng)` |
| `đợt đối soát lô` | `đợt chi trả` |
| `đợt đối soát` | `đợt chi trả` |
| `Đợt đối soát` | `Đợt chi trả` |
| `Chuyển trạng thái đối soát sang ${status}` | `Chuyển trạng thái bảng kê phí sang ${statusLabel(status, paymentLabel(status))}` |
| `Đã tạo bản đối soát nháp` | `Đã tạo bản kê phí nháp` |
| `Đã chuyển đối soát sang` | `Đã chuyển bảng kê phí sang` |
| `Đối soát nền tảng` | `Bảng kê phí nền tảng` |
| `Tạo đối soát` | `Tạo bảng kê phí` |
| `Thao tác này sẽ cộng tiền vào ledger.` | `Khoản thu sẽ được ghi nhận vào số tiền đã thanh toán của lịch hẹn.` |
| `Mã đối soát` | `Mã giao dịch ngân hàng` |
| `SETTLEMENT: 'Thanh toán'` | `SETTLEMENT: 'Chi trả cho cơ sở'` |
| `Cần đối soát:` | `Giao dịch đang được kiểm tra:` |
| `SIMULATED_COMPLETED: 'Đã quyết toán (mô phỏng)'` | `SIMULATED_COMPLETED: 'Đã chi trả (mô phỏng)'` |
| `SIMULATED_PAID: 'Đã quyết toán (mô phỏng)'` | `SIMULATED_PAID: 'Đã chi trả (mô phỏng)'` |
| `SETTLEMENT: 'Quyết toán'` | `SETTLEMENT: 'Chi trả cho cơ sở'` |
| `khoản cọc đủ điều kiện đối soát` | `khoản cọc đủ điều kiện chi trả` |
| `Khoảng thời gian đối soát` | `Khoảng thời gian chi trả` |
| `Thông tin đối soát` | `Thông tin chi trả` |
| `Yêu cầu đối soát` | `Yêu cầu chi trả` |
| `'Invalid review fields': 'Thông tin đối soát không hợp lệ.` | `'Invalid review fields': 'Thông tin kiểm tra giao dịch không hợp lệ.` |
| `Đối soát mô phỏng đã hoàn tất` | `Đợt chi trả đã hoàn tất (mô phỏng)` |
| `Đã tạo đợt đối soát mô phỏng` | `Đã tạo đợt chi trả (mô phỏng)` |
| `Đợt đối soát ` | `Đợt chi trả ` |
| `Quản trị viên sẽ đối soát.` | `Quản trị viên sẽ kiểm tra giao dịch.` |
| `Khoản nạp ví cần đối soát` | `Khoản nạp ví đang được kiểm tra` |
| `Mã đối soát không khớp giao dịch đã xác minh.` | `Mã giao dịch ngân hàng không khớp giao dịch đã xác minh.` |
| `Mã yêu cầu hoặc mã đối soát đã được sử dụng.` | `Mã yêu cầu hoặc mã giao dịch ngân hàng đã được sử dụng.` |
| `"SETTLEMENT": "Thanh toán cho doanh nghiệp"` | `"SETTLEMENT": "Chi trả cho cơ sở"` |
| `Đối soát phí nền tảng` | `Bảng kê phí nền tảng` |
| `Tạo kỳ đối soát phí` | `Tạo bảng kê phí` |
| `các kỳ đối soát` | `các bảng kê phí nền tảng` |
| `>Đối soát {{` | `>Bảng kê phí {{` |
| `>Đối soát<` | `>Bảng kê phí nền tảng<` |
| `Gói liệu trình, hóa đơn và đối soát` | `Gói liệu trình, hóa đơn và bảng kê phí` |
| `'REVIEW' => 'Đang đối soát'` | `'REVIEW' => 'Chờ duyệt'` |
| `Đối soát ví` | `Đối soát nạp ví` |
| `Không có biên nhận cần đối soát` | `Không có giao dịch cần đối soát` |
| `Biên nhận không khớp yêu cầu nạp ví sẽ hiện ở đây.` | `Giao dịch chưa khớp yêu cầu nạp ví sẽ hiện ở đây.` |
| `Biên nhận khác` | `Giao dịch khác` |
| `Biên nhận cần đối soát` | `Giao dịch cần đối soát` |
| `Đến ngày (không gồm)` | `Trước ngày` |
| `Kỳ {day(item.periodStart)} – {day(item.periodEnd)} (không gồm ngày cuối)` | `Khoản phát sinh từ {day(item.periodStart)} đến trước {day(item.periodEnd)}` |
| `PENDING: 'Chờ hoàn tất'` | `PENDING: 'Chờ chi trả'` |
| `'Đã chi trả (mô phỏng)' : 'Đang chờ'` | `'Đã chi trả (mô phỏng)' : 'Chờ chi trả'` |
| `Liên kết không hợp lệ hoặc đã hết hạn. Yêu cầu một liên kết mới.` | `Liên kết không hợp lệ hoặc đã hết hạn. Hãy yêu cầu mã đặt lại mật khẩu.` |
| `Liên kết đặt lại không hợp lệ. Hãy yêu cầu một liên kết mới.` | `Liên kết đặt lại không hợp lệ. Hãy yêu cầu mã đặt lại mật khẩu.` |
| `Yêu cầu liên kết mới` | `Yêu cầu mã đặt lại mật khẩu` |
| `INACTIVE: 'Tạm ngưng nhận lịch'` | `INACTIVE: 'Đã ngừng làm việc'` |
| `{statusLabel(staff.status)}` | `{staff.status === 'INACTIVE' ? 'Đã ngừng làm việc' : statusLabel(staff.status)}` |
| `Cấp tài khoản nhân viên` | `Gửi lời mời tạo tài khoản nhân viên` |
| `Chưa cấp tài khoản` | `Chưa có tài khoản đăng nhập` |
| `Cấp tài khoản` | `Gửi lời mời tạo tài khoản` |
| `label="Cấp tài khoản"` | `label="Tài khoản đăng nhập"` |
| `Đang chờ AI phân tích` | `Đang xử lý phân tích` |
| `Ngoại lệ do nền tảng phê duyệt, luôn được lưu vào nhật ký` | `Thao tác dành cho quản trị viên, yêu cầu lý do và được lưu vào nhật ký` |
| `Đã force-cancel lịch hẹn và ghi nhận lý do` | `Đã hủy lịch theo quyền quản trị và lưu lý do` |
| `Không thể force-cancel lịch hẹn` | `Không thể hủy lịch theo quyền quản trị` |
| `Force-cancel theo ngoại lệ` | `Hủy lịch theo quyền quản trị` |
| `Force-cancel lịch hẹn` | `Hủy lịch theo quyền quản trị` |
| `Xác nhận force-cancel` | `Xác nhận hủy lịch` |
