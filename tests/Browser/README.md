# Kiểm tra trình duyệt BeautyBook

Mô hình hiện hành là **ví khách hàng**, nạp vào tài khoản chung và giữ cọc 30% từ ví khi đặt lịch. `wallet-booking.mjs` kiểm tra luồng này trên QA port 8013 sau `fixture.php`, với wallet mode và tài khoản SePay giả lập bật, direct deposit tắt. `owner-finance.mjs` dùng một fixture mới riêng cùng `owner-finance-fixture.php`. `direct-switch.mjs` giữ lại để kiểm tra cấu hình cọc trực tiếp tương thích, không phải hành vi mặc định. Xem `docs/PAYMENT_SETUP.md`.

Các script `.mjs` dùng Playwright điều khiển Chrome thật. Bản chạy ngày 14/11/2026 dùng Playwright trong runtime Codex được import ở đầu mỗi file; khi chạy ở máy khác cần đổi đường dẫn import sang bản Playwright đã cài. Không thêm Playwright vào bundle ứng dụng.

## Cô lập dữ liệu

- `visual.mjs`: chỉ đọc giao diện ứng dụng chính tại `localhost:8000` và đăng nhập bằng tài khoản demo. Chạy sau khi build hoàn tất. Ảnh/JSON nằm trong `storage/app/private/fe-review`.
- Các script còn lại mặc định dùng QA tại `127.0.0.1:8013`, với `APP_ENV=testing`, `DB_HOST=mysql_test`, `DB_DATABASE=beautybook_test`. Không trỏ các bài có ghi dữ liệu sang ứng dụng chính.
- `fixture.php` gọi `Tests\TestCase::assertSafeDatabaseConfiguration`, giữ khóa suite, rồi migrate/seed **DB test**. Chỉ chạy sau khi PHPUnit và mọi browser khác đã kết thúc. Các bài browser chia sẻ dữ liệu này; không chạy PHPUnit hoặc reset fixture giữa các bước.
- Server QA dùng `MAIL_MAILER=log`, `LOG_CHANNEL=single`, storage riêng `storage/app/private/full-qa/runtime`. Không dùng SMTP. Không xuất log mail, OTP, token, cookie hoặc storage state ra báo cáo.
- SePay trong QA dùng tài khoản, khóa và webhook giả dành riêng cho kiểm thử. Rút tiền/chi trả theo đợt dùng provider mô phỏng. Không có chuyển tiền thật.

## Nhóm kiểm tra

| File | Nội dung |
| --- | --- |
| `visual.mjs` | Duyệt khách/owner/lễ tân/admin/public ở 320, 375, 414, 768, 1440px; kiểm tra HTTP điều hướng, ảnh, tràn ngang, tên nút, ID, JavaScript và API. Giữ các HTTP 401 đã được retry thành công trong bằng chứng. |
| `identity.mjs` | Đăng ký, profile/avatar, mật khẩu, thu hồi phiên, OTP sai/đã dùng và reset phiên cũ. Tạo tài khoản riêng; hỗ trợ resume theo report. |
| `customer.mjs` | Lưu dịch vụ, đặt nhiều lượt/mã giảm giá, mất phản hồi rồi gửi lại, combo, định kỳ, chat/ảnh, đánh giá/ảnh/phản hồi, đổi/hủy và thông báo. |
| `management.mjs` | Dịch vụ/biến thể/combo/nhân sự/lời mời/ưu đãi/voucher; người dùng, báo cáo, cấu hình và duyệt hồ sơ admin. |
| `wallet-booking.mjs` | Ví trống chặn đặt lịch; tạo mã nạp; webhook ký hợp lệ, chống cộng tiền lặp; đặt lịch giữ cọc; lịch sử ví và chi tiết owner; màn hình 375px. |
| `finance.mjs` | Tài khoản ngân hàng, xác minh, rút tiền hủy/duyệt/từ chối, chi trả theo đợt mô phỏng. `finance-fixture.php` tạo khoản phải trả qua ledger từ số dư test, không nạp tiền thật. |
| `discovery-moderation.mjs` | Tìm kiếm/bộ lọc/trạng thái rỗng, lỗi mạng rồi thử lại, deep link, guest guard, taxonomy cha/con/ngừng dùng/gộp, report/ẩn/khiếu nại/khôi phục đánh giá. |
| `operations.mjs` | Lịch nghỉ/giờ làm/kiểm tra chồng ca/khôi phục cấu hình, phạm vi nhân viên/lễ tân, thu phần tiền còn lại bằng CASH và kiểm tra số dư phải thu bằng 0. |
| `ai-predict.php`, `ai-read.mjs` | Gọi PhoBERT thật với mẫu tổng hợp; đọc kết quả worker trên giao diện owner. Không dùng API giả cho kết quả AI. |

Thứ tự bản chạy: toàn bộ PHPUnit → fixture QA → baseline nạp ví/webhook → `customer.mjs` → worker AI và `ai-read.mjs` → các nhóm độc lập → đóng browser/server QA → hồi quy PHPUnit bị ảnh hưởng. Những bước phụ thuộc cần dữ liệu đã tạo ở bước trước; không coi đây là một lệnh chạy song song độc lập.

Để chạy toàn bộ test frontend hiện có trên PowerShell:

```powershell
$testFiles = @(rg --files resources/beautybook/src -g '*.test.js')
node --test @testFiles
node --test realtime/server.test.mjs
node --test scripts/frontend-parity-cleanup.test.mjs
npm run build
```

Kết quả và giới hạn thực tế nằm trong `docs/FULL_TEST_RESULTS_2026-11-14.md`. Chạy lại một case dùng `QA_CASES`, `QA_MANAGEMENT_CASES`, `QA_IDENTITY_CASES`/`QA_RESUME_RUN`, `QA_DISCOVERY_CASES` hoặc tùy chọn resume được ghi trong file tương ứng. Report cũ được giữ để phân biệt lỗi thực tế, lỗi selector và các lần kiểm tra lại.

## Owner finance theo GlowMe

`owner-finance.mjs` kiểm tra riêng bố cục mới và các lệnh tài chính qua UI. Trên QA port 8013, chạy `fixture.php` rồi `owner-finance-fixture.php` khi mọi suite khác đã dừng. Fixture tạo 90.000đ khoản cọc được hưởng và 30.000đ cọc đang giữ từ ledger test; không dùng ngân hàng thật. Browser kiểm tra 12 điểm: chặn khi chưa có tài khoản, đăng ký/duyệt ngân hàng, số tiền không hợp lệ, mất phản hồi sau khi ghi và replay cùng khóa, lịch sử/hủy, duyệt mô phỏng, tài khoản thay thế/mặc định, tách nguồn lưu trữ, chi trả theo đợt, công cụ PHP mở rộng và lỗi tải/thử lại. `QA_OWNER_FINANCE_CASES` chọn các case cần chạy lại trên đúng dữ liệu đang có.

`owner-finance-visual.mjs` chỉ đăng nhập/đọc ứng dụng chính, kiểm tra owner/reception/admin ở 5 chiều rộng, thao tác bàn phím và việc chỉ tải công cụ thu tiền sau khi mở. Artifact riêng trong `storage/app/private/owner-finance-qa`, log `storage/logs/owner-finance-*.log`. Kết quả lần này: 12 acceptance + 15 mẫu giao diện đạt, 0 page exception; 182 frontend test + 5 parity test đạt, production build thành công. Thông tin chức năng nằm trong `docs/PAYMENT_SETUP.md`.
