# Kết quả kiểm tra BeautyBook — 14/11/2026

Đã chạy toàn bộ bộ test PHP và frontend hiện có, sau đó dùng Playwright/Chrome kiểm tra giao diện và các thao tác nghiệp vụ trên ứng dụng thật. Đã sửa 4 lỗi sản phẩm phát hiện qua trình duyệt và chạy lại các luồng bị ảnh hưởng.

## Kết quả cuối

| Nhóm | Đạt | Lỗi còn trong các case đã chạy | Bỏ qua |
| --- | ---: | ---: | ---: |
| PHP, Feature + Unit | 569 | 0 | 3 |
| Frontend, toàn bộ 39 file Node test | 182 | 0 | 0 |
| Kiểm tra nguồn frontend/parity | 5 | 0 | 0 |
| Realtime transport | 1 | 0 | 0 |
| Các điểm kiểm tra acceptance bằng Chrome và fixture tích hợp | 107 | 0 | 0 |
| Giao diện: 60 lượt trang theo vai trò × 5 chiều rộng | 300 mẫu | 0 | 0 |

PHP có 572 trường hợp, tổng 7.065 assertion sau khi gộp kết quả chạy lại theo đúng tên testcase. Không cộng số lần rerun vào số test. Lần chạy toàn bộ ban đầu có một fixture ngày bị trùng; đã sửa và chạy lại cả class 20/20. Sau thay đổi quản lý biến thể, chạy lại cả `BeautybookManagementApiTest`: 13/13, 157 assertion.

Ba skip thuộc subclass R2, dành cho kiểm tra chỉ áp dụng disk local: `R2ResultPhotoTest::external_provenance_requires_typed_private_evidence_in_the_same_scope`, `R2ReviewPhotoTest::upload_uses_file_content_and_rejects_over_size_and_staging_limit`, `R2ReviewPhotoTest::review_and_photo_links_rollback_together_then_retry_with_same_ids`. Các testcase tương đương trên local đã đạt. Các bài R2 còn lại chạy với MinIO thật trên giao thức S3.

107 là số điểm kiểm tra đã có kết quả, bao gồm bước chuẩn bị, kiểm tra âm và callback ký bằng khóa test; không phải 107 hành trình độc lập từ đầu đến cuối. Các lần thất bại trước sửa, lỗi selector và lần chạy lại được giữ trong báo cáo gốc.

## Các chức năng đã thao tác

| Nhóm acceptance | Số điểm đạt | Nội dung chính |
| --- | ---: | --- |
| Danh tính/tài khoản | 25 | Đăng ký, dữ liệu sai, đăng nhập sai, profile/avatar, đổi mật khẩu, danh sách/thu hồi phiên, OTP sai, reset thành công, dùng lại mã bị từ chối, phiên cũ mất quyền, đăng nhập bằng mật khẩu mới. |
| Owner/admin quản lý | 20 | Tạo/sửa dịch vụ, biến thể tạm ngưng/bật lại, ngưng/bật dịch vụ tại chi nhánh, tạo/sắp thứ tự/sửa/lưu trữ combo với mô tả trống và có mô tả, nhân sự/phân công/lời mời, ưu đãi, voucher/cấp/thu hồi, khóa/mở user test, báo cáo, cấu hình save/restore, yêu cầu bổ sung hồ sơ, mobile. |
| Khách hàng | 13 | Lưu/bỏ lưu dịch vụ, nhiều lượt và mã BEAUTY20, mất phản hồi sau khi ghi rồi replay chỉ một lịch, chat hai phía và ảnh, combo, định kỳ/hủy từng lần/hủy chuỗi, hoàn tất lịch, đánh giá/ảnh/phản hồi, đề nghị đổi lịch/duyệt/hủy, thông báo. |
| Ví/cọc | 9 | Ví trống chặn đặt; tạo yêu cầu nạp 200.000đ; callback ký đúng gửi hai lần chỉ cộng một lần; cọc 30%, số dư/tiền còn lại, ledger giữ cọc, hiển thị owner và chuyển hướng đường dẫn cũ. |
| Rút tiền/đối soát | 6 | Gửi/xác minh tài khoản test, yêu cầu rút và hủy, admin duyệt mô phỏng, từ chối hoàn phần dự trữ, tạo/hoàn tất đối soát lô. Đối soát dùng khoản phải trả nguyên vẹn riêng; khoản đã phân bổ cho rút tiền bị loại đúng quy tắc. |
| Tìm kiếm/hậu kiểm | 7 | Tìm kiếm/bộ lọc giá/sắp xếp/reload, không có kết quả và reset; một phản hồi 503 được giả lập để kiểm tra nút thử lại; deep link/guest booking guard; taxonomy cha/con/ngừng dùng/gộp; owner report → admin ẩn → khách khiếu nại → admin khôi phục. |
| Vận hành/nhân viên/lễ tân | 15 | Lịch nghỉ CRUD, giờ làm save/reload/restore, chồng ca bị từ chối, phạm vi staff/reception, mã lịch không tồn tại bị từ chối, thu CASH 70.000đ còn lại của fixture đã cọc 30.000đ; reload còn phải thu 0đ. |
| Đăng ký owner/cơ sở | 11 | Owner mới vào đúng hồ sơ, lỗi mã số thuế trùng, lưu pháp lý, upload giấy tờ riêng tư, submit, tạo đúng một chi nhánh, địa chỉ/giờ làm/chính sách, reload không tạo trùng và gửi duyệt. Một bước duyệt doanh nghiệp dùng API qua Playwright; những bước form dùng UI. |
| AI owner | 1 | Worker thật phân tích đánh giá vừa gửi, lưu kết quả và hiện đủ bốn khía cạnh; 1 đã phân tích, 0 lỗi. |

Ngoài điểm AI trên giao diện, đã gọi trực tiếp model PhoBERT được pin với 3 văn bản tổng hợp: 3/3 phản hồi hợp lệ. Đây là kiểm tra tích hợp model, không phải đo độ chính xác trên tập dữ liệu gán nhãn.

Các nhóm chức năng API còn lại được đối chiếu trong [FULL_TEST_COVERAGE_2026-11-14.md](FULL_TEST_COVERAGE_2026-11-14.md): phân quyền/tenant/role hết hạn, đặt lịch và xung đột đồng thời, tài chính/refund/reversal, media riêng tư, quản trị, vận hành/chuyển chủ, notification/push, privacy và các nhánh tương thích legacy.

## Lỗi đã sửa

1. **Combo không hiện dịch vụ hợp lệ:** màn hình đọc `status/active` trong khi API quản lý trả `isActive/bookable`. Dùng đúng hai trường quản lý; picker hiện dịch vụ đang nhận lịch.
2. **Combo không lưu khi mô tả trống:** API trả `description=null`; form gọi `.trim()` trên null. Chuẩn hóa mô tả khi mở form sửa/sao chép. Đã tạo/sửa/lưu trữ lại qua UI với mô tả trống và có mô tả.
3. **Biến thể tạm ngưng biến mất:** form quản lý đọc API public chỉ trả biến thể ACTIVE. Endpoint chi tiết quản lý có kiểm tra owner nay trả cả biến thể inactive; form dùng endpoint này. API public vẫn lọc ACTIVE. Hồi quy PHP kiểm tra inactive/active và từ chối owner khác doanh nghiệp.
4. **Trang quên mật khẩu chưa dùng OTP:** đã nối `/forgot-password` vào `RecoveryCodeForm` hiện có. Luồng OTP và thu hồi phiên đã qua Chrome.

Đồng thời sửa fixture của `BookingDepositLifecycleTest`: ngày +8 rơi Chủ nhật được dời sang Thứ hai, trùng lịch +9 của case kế tiếp. Dời lịch thứ hai sang +10; giữ nguyên các assertion và kiểm tra xung đột nghiệp vụ.

## Giao diện và môi trường

- Duyệt public, customer, owner, receptionist, admin trên ứng dụng chính; staff trên QA: 60 lượt trang theo vai trò ở 320/375/414/768/1440px. Đây là lượt trang theo vai trò, có màn hình dùng chung.
- Lượt ổn định cuối: HTTP điều hướng hợp lệ, 0 page exception/API lỗi, ảnh hỏng, ID trùng, nút trống tên và tràn ngang nội dung. Halo trang trí không làm tăng chiều rộng tài liệu được loại khỏi lỗi nội dung. Đây là kiểm tra giao diện cơ bản, không phải chứng nhận WCAG.
- Đã giữ lần dò bị HTTP 500 trong lúc build đang thay manifest; sau khi build hoàn tất chạy lại trọn 55 lượt trên ứng dụng chính và đạt. Kết quả cuối dùng `full-retest-stable.json`, không dùng lượt bị gián đoạn.
- Các HTTP 401 khởi tạo, dữ liệu sai và các lần cũ nằm trong report gốc. Chỉ đánh dấu retry hồi phục khi có phản hồi thành công cùng request được ghi nhận; các 401 lịch sử thiếu bằng chứng retry không tự chuyển thành pass API.
- Build production cuối thành công; toàn bộ 182 frontend test và 5 parity check chạy lại sau bản sửa cuối đạt.
- Mọi thao tác ghi nghiệp vụ chạy trên `mysql_test/beautybook_test`, server QA port 8013. Không reset/seed hoặc CRUD nghiệp vụ trên `beautybook_php`; ứng dụng chính chỉ được đăng nhập/đọc. Server QA đã đóng trước hồi quy PHP cuối.

## Giới hạn cần phân biệt

- Không thực hiện chuyển khoản thật, gọi webhook SePay qua HTTPS công khai, Google OAuth thật, gửi SMTP thật, dùng tài khoản Cloudflare R2 thật hoặc giao push đến thiết bị thật. Tương ứng đã kiểm tra callback ký bằng khóa test, hợp đồng API, log mail, MinIO và push trial/transport.
- Chưa đi toàn bộ UI cho chuyển chủ/pháp nhân/payout version, xử lý impact/offboarding, service dependency/phụ thu, sao chép combo, package/refund/reversal và mọi tổ hợp quyền/thời gian. Các nhóm này có bằng chứng backend trong bản đồ coverage; không tính thành UI đạt.
- Các nhánh OTP hết hạn/resend/rate-limit/exhaustion và email verification có test backend; acceptance Chrome lần này tập trung reset mã sai/đúng/đã dùng và phiên cũ.
- Tạo lịch tại quầy, chỉnh từng dịch vụ/giờ thực tế và một số màn hình legacy cố ý không có trên UI hiện tại theo [BOOKING_FLOW.md](BOOKING_FLOW.md). Reversal chưa có điều khiển trong UI thanh toán mới. Không tính việc không có các nút này thành hành trình UI đã chạy thành công.
- Thu CASH thành công dùng booking fixture đã COMPLETED; không có một hành trình browser duy nhất nối từ đặt online → lễ tân check-in → owner hoàn tất → thu tiền. Các đoạn đặt online/hoàn tất/thu tiền và kiểm tra trạng thái có bằng chứng riêng.
- Không đo phần trăm line/branch coverage hoặc kiểm thử tải đồng thời ở quy mô production. Các test tranh chấp DB hiện có đã chạy trong full suite.

## Bằng chứng và chạy lại

Mã browser và hướng dẫn: [tests/Browser/README.md](../tests/Browser/README.md). Bản tổng hợp tự kiểm tra: `node tests/Browser/summarize.mjs`.

Các artifact được giữ local, nằm trong đường dẫn bị Git bỏ qua:

- `storage/app/private/full-qa/summary.json`, `backend-final.json`, `phpunit.xml`, `phpunit-rerun.xml`, `phpunit-management-final.xml`.
- `storage/app/private/fe-review/full-retest-stable.json`, `staff.json` và ảnh theo trang/chiều rộng.
- `storage/app/private/full-qa/customer-report.json`, `finance-report.json`, `discovery-moderation.json`, `operations-report.json`, `ai-read.json`, `ai-predict.json`.
- `storage/app/private/full-qa/management-identity-consolidated.json` và từng report/ảnh nguồn; `onboarding/browser-report.json`, `onboarding/branch-report.json`.
- `storage/app/private/booking-qa/wallet-report.json`.
- `storage/logs/full-qa-*.log` cho PHPUnit, Node, realtime, parity, build và browser.

Không đưa OTP, token, session state hoặc mail log vào Git hay báo cáo. Các JSON/ảnh riêng tư chỉ phục vụ kiểm tra cục bộ.
