# Bảy luồng cốt lõi theo ZIP: R2, Google, chat, push, SePay, sổ giao dịch, payout

Bàn giao ngày 09/11/2026 trên nhánh `feature/zip-core-flows-2026-11-09`, cắt từ `docs/audit-plan-2026-10-24` tại `6289bb4851f226a7f2303c9ec166e7fd4a868fb1`. Đây là phạm vi nghiệm thu demo: mỗi nhóm có một luồng chạy từ đầu đến cuối và có test. Biên bản này ghi rõ phần nào là dịch vụ test, sandbox, MOCK hay fixture và phần nào khác ZIP.

## Nguồn đã đọc

Hai ZIP trên Desktop được mở ở chế độ chỉ đọc (không giải nén). `README` và biên bản trong `docs/migration` của repo cũng đã đọc.

### ZIP API

- `src/payments/sepay-webhook.controller.ts`, `sepay-webhook.service.ts`, `sepay-webhook.validation.ts`, `live-wallet-inbox.ts`, `live-wallet-policy.ts`, `wallet-core.ts`, `wallet-topup-core.ts`, `payment-locks.ts`, `deposit-core.ts`, `business-withdrawal.service.ts`, `mock-payout.provider.ts`
- `src/chat/chat.controller.ts`, `chat.service.ts`, `chat-access.ts`, `chat.dto.ts`
- `src/notifications/fcm.service.ts`, `push-devices.service.ts`, `push.worker.ts`, `push-policy.ts`, `push-queue.ts`, `notifications.controller.ts`, `dto/push.dto.ts`
- `src/auth/google.service.ts`, `recovery.service.ts`, `mail.service.ts`, `token.service.ts`, `auth.controller.ts`, `docs/google-and-email-auth.md`
- `src/media/media-storage.ts`, `media-access.ts`
- `tests/backend/google-recovery.integration.test.cjs`, `r2-media-storage.test.cjs`, `sepay-webhook-fixture.cjs`, `chat-postgres.test.cjs`, `push-notifications.test.cjs`

### ZIP Web

- `src/components/chat/ChatInbox.jsx`, `src/styles/chat.css`
- `src/components/public/GoogleSignIn.jsx`, `RecoveryCodeForm.jsx`, `src/pages/Login/AuthActionPages.jsx`
- `src/api/apiClient.js` (đoạn `authApi`), `src/App.jsx` (route `/customer/messages`, `/salon/messages`)

## Khác ZIP và quyết định có thể đổi hành vi

| Nội dung | ZIP | Laravel (bản này) | Lý do |
| --- | --- | --- | --- |
| Định danh | UUID | ID số hiện có | Giữ schema Laravel |
| SePay Test mode | Webhook `test` chỉ ghi hộp thư, ví cộng tiền qua kênh riêng `wallet-live-lab` môi trường `live` | Webhook `test` khớp **giao dịch chuyển khoản đang chờ** (`payment_transactions`, `method=BANK_TRANSFER`) theo mã trong nội dung và ghi vào sổ `payment_ledger_entries` | Laravel chưa có ví. Tái sử dụng luồng thanh toán hiện có thay vì tạo ví mới |
| Ví | `Wallet`, `WalletTopUp`, `WalletTransfer` | **Không có** | Nằm ngoài một luồng nghiệm thu; cần quyết định sản phẩm riêng |
| Mã thanh toán | `WL` + 24 hex (nạp ví), `GM` (đặt cọc) | `BB` + 24 hex trên `payment_transactions.payment_code` | Cùng ý tưởng mã trong nội dung chuyển khoản |
| Người xác nhận | Hệ thống | `verified_by = NULL`, audit `SEPAY_VERIFY`; `actor_id` của sổ lấy người tạo giao dịch chờ | `payment_ledger_entries.actor_id` bắt buộc, không đổi schema cũ |
| Điều kiện rút tiền | Khoản phải trả tạo khi giải phóng đặt cọc | `business_payables` tạo khi SePay xác minh; chỉ rút được khi lịch `COMPLETED`, đã trừ hoàn tiền đang giữ/đã hoàn và giao dịch bị đảo | Không can thiệp vòng đời lịch hẹn |
| Phí nền tảng | Không có trong rút tiền | Số rút chưa trừ phí; phí vẫn đối soát qua `platform_fee_entries` hiện có | Giữ tương thích |
| Nonce Google | Cookie `bb_google_nonce` | Khóa `google_nonce` trong session (cookie HttpOnly sẵn có) | Laravel đã có session gắn trình duyệt |
| Mã khôi phục | 10–128 ký tự | Theo luật mật khẩu hiện có của Laravel (từ 8, hoa/thường/số) | Giữ tương thích đăng ký |
| Ảnh trong chat | Có (R2) | **Chưa** — chỉ văn bản và chia sẻ lịch hẹn | Ngoài tiêu chí; cần thêm quyết định lưu trữ |
| Thiết bị push | Bảng `push_devices` gắn phiên | Dùng lại `beautybook_device_tokens` (token mã hóa), thêm cột `active` | Tái sử dụng hạ tầng hiện có; token đã gắn tài khoản khác trả 409 |
| Chat realtime | Thăm dò (polling) | Giống ZIP (4 giây mở hội thoại, 8 giây danh sách) | Không đổi |

## Luồng và trạng thái

| Nhóm | Trạng thái | Mức xác minh |
| --- | --- | --- |
| 1. R2 | Đạt | Máy chủ S3 giao thức thật (MinIO) trong Docker test, **không phải R2 thật** |
| 2. Google và khôi phục | Đạt | Token Google ký bằng khóa RSA cục bộ, JWKS giả lập; thư qua mailer `array`. **Chưa** đăng nhập bằng tài khoản Google thật |
| 3. Chat | Đạt | MySQL test; UI React đã build, chưa kiểm tra bằng trình duyệt |
| 4. Push | Đạt | `Http::fake` thay FCM và OAuth Google. **Chưa** gửi tới Firebase thật |
| 5. SePay | Đạt | Fixture ký HMAC theo contract ZIP, **không phải SePay Test mode thật** |
| 6. Sổ giao dịch | Đạt | MySQL test, kể cả 6 tiến trình song song |
| 7. Payout | Đạt | Nhà cung cấp MOCK, MySQL test, 5 tiến trình song song |

### 1. R2 (ảnh riêng tư)

- `config('media.private_disk')` (`MEDIA_PRIVATE_DISK`, mặc định `local`) quyết định nơi ghi tệp riêng tư mới; đĩa `r2` là S3 API (`R2_ENDPOINT`, `R2_BUCKET_NAME`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_KEY_PREFIX`).
- `App\Services\Media\PrivateMediaDisk` chỉ di chuyển byte. Quyền vẫn do `MediaManagementService::canRead`, `Review::publiclyVisible()` và `ResultPhoto::publicMedia()` quyết định như trước, kể cả kiểm tra lại sau khi đọc kho.
- Mỗi dòng `media_files` giữ `disk` của nó, nên ảnh cũ trên `local` vẫn đọc được sau khi chuyển sang `r2`.
- Sự cố bucket trả `503` chung chung, không lộ endpoint hay khóa, và không rơi về đĩa khác.
- Phạm vi: ảnh đánh giá, ảnh kết quả, bằng chứng đồng ý và tệp riêng tư tải qua quản lý media. Ảnh đại diện và các tệp công khai giữ nguyên.
- Test: `R2PrivateMediaTest`, cùng hai bộ `ReviewPhotoTest`, `ResultPhotoTest` chạy lại với đĩa `r2` (`R2ReviewPhotoTest`, `R2ResultPhotoTest`) kiểm tra URL ảnh sau khi đánh giá bị ẩn và sau khi thu hồi quyền công khai.

### 2. Google và khôi phục mật khẩu

- `GET /api/v1/auth/google/config` trả `clientId` và nonce gắn session; `POST /api/v1/auth/google` xác minh chữ ký RS256 theo JWKS, `iss`, `aud`, `exp`, nonce và email đã xác minh (`GoogleIdTokenVerifier`).
- `sub` của Google là danh tính lâu dài (`external_identities`). Tài khoản email sẵn có chỉ tự liên kết khi Google là bên xác thực địa chỉ (`@gmail.com` hoặc có `hd`); ngược lại trả `409`. Tài khoản quản trị nền tảng và tài khoản khóa bị từ chối.
- `POST /api/v1/auth/forgot-password/code` và `/reset-password/code`: mã 6 số, hết hạn 10 phút, tối đa 5 lần thử, gửi lại sau 60 giây, tối đa 5 yêu cầu mỗi giờ, chỉ lưu HMAC. Phản hồi giống nhau cho email có và không có tài khoản. Đặt lại thu hồi phiên và vô hiệu các liên kết đặt lại còn lại.
- Luồng liên kết cũ (`forgot-password`, `reset-password`) giữ nguyên; test xác nhận token hết hạn và token đã dùng bị từ chối.

### 3. Chat

- `POST /api/v1/chat/{customer|branch}/conversations` mở hội thoại từ một booking trong phạm vi người gọi; `GET/POST .../{id}/messages`, `POST .../{id}/read`.
- Phạm vi nhánh: chủ doanh nghiệp thấy mọi chi nhánh của doanh nghiệp, lễ tân chỉ thấy chi nhánh của mình. Nhân viên, quản trị nền tảng, doanh nghiệp khác, lễ tân chi nhánh khác và khách khác bị từ chối.
- Tin nhắn có `sequence` và `clientMessageId`: gửi lại cùng mã trả đúng tin nhắn cũ, đổi nội dung dưới cùng mã trả `409`. Mỗi tin nhắn tạo thông báo cho đúng người đối diện (đi qua `notifications` và `notification_outbox`).
- UI: `/customer/messages`, `/salon/messages`, nút **Nhắn tin** trong chi tiết lịch hẹn.

### 4. Push

- `POST /api/v1/notifications/device-token` (có sẵn) đăng ký thiết bị; `DELETE` gỡ thiết bị.
- `BookingNotificationService::record()` xếp hàng `push_deliveries` cho thiết bị đang hoạt động của đúng người nhận; worker (`beautybook:maintain`, mỗi phút) gửi qua FCM HTTP v1, thử lại có backoff (tôn trọng `Retry-After`), thu hồi token `UNREGISTERED`, bỏ qua khi tài khoản bị khóa hoặc thiết bị đổi chủ.
- Gửi lại: lỗi tạm thời tự thử lại tối đa 8 lần; `php artisan beautybook:push-retry [notificationId]` đưa các lần gửi `FAILED` về hàng đợi.
- Sự kiện: tạo/đổi trạng thái/dời lịch, yêu cầu đổi lịch, tin nhắn chat. Cần `PUSH_ENABLED=true`, `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_FILE`.

### 5–6. SePay và sổ giao dịch

- `POST /api/v1/payments/sepay/webhook` (công khai, miễn CSRF, `throttle:120,1`): kiểm tra `Content-Type`, giới hạn 32 KiB, `X-Sepay-Timestamp` ±300 giây, `X-Sepay-Signature: sha256=HMAC(timestamp + "." + rawBody)`, tài khoản nhận. Trả `401/413/415/400/503` trước khi ghi DB.
- Hộp thư `sepay_webhook_events` khóa duy nhất `(environment, provider_transaction_id)`: giao lặp không tạo bản ghi thứ hai; cùng mã nhưng khác nội dung trả `409`. Xử lý lỗi tạm thời trả `503` để SePay gửi lại; `beautybook:maintain` xử lý lại `RETRY_PENDING`.
- Khớp giao dịch chờ theo đúng một mã `BB…` trong nội dung, đúng số tiền, thời điểm hợp lệ. Thiếu/nhiều mã, không thấy giao dịch, thiếu/thừa tiền, lệch thời gian, giao dịch đã thanh toán, vượt tổng lịch đều vào `MANUAL_REVIEW` và **không cộng tiền**. Tiền ra chỉ lưu.
- Ghi sổ: `PaymentService::applyBankReceipt()` khóa doanh nghiệp → lịch → giao dịch (cùng thứ tự xác minh tay), chuyển `VERIFIED`, ghi `payment_ledger_entries` với khóa idempotent, cộng điểm và phí nền tảng như xác minh tay. `settlement_reference` duy nhất và `sepay_webhook_events.payment_transaction_id` duy nhất bảo vệ ở mức DB.
- Giao diện đối soát: tab **Đối soát SePay** trong trang Thanh toán (`GET /api/v1/payments/sepay/receipts`, chủ/lễ tân chỉ thấy giao dịch của mình, quản trị thấy tất cả); giao dịch chờ hiển thị mã chuyển khoản.

### 7. Payout (MOCK)

- Chủ doanh nghiệp: thêm tài khoản nhận (xác minh demo), yêu cầu rút với khóa idempotent, hủy; quản trị nền tảng: duyệt, từ chối, thử lại. Duyệt ghi trạng thái `PROCESSING` trước, sau đó `MockPayoutProvider` thực thi; `beautybook:maintain` tiếp tục các yêu cầu bị gián đoạn.
- Số dư dẫn xuất, không lưu: khoản phải trả của lịch `COMPLETED` đã thu qua SePay, trừ hoàn tiền/đảo giao dịch, trừ các phần đã phân bổ. Rút vượt số dư trả `409`; sai vai trò trả `403`; gửi lại cùng khóa trả cùng yêu cầu, khác nội dung trả `409`; duyệt lặp không chi lần hai (`payout_ledger_entries.operation_key` duy nhất).
- UI: tab **Rút tiền** (chủ: số dư, yêu cầu, hủy; quản trị: duyệt/từ chối/thử lại).

## Cấu hình tích hợp cần có để chạy thật

| Tích hợp | Biến môi trường | Tình trạng lượt này |
| --- | --- | --- |
| SePay Test mode | `SEPAY_WEBHOOK_ENABLED`, `SEPAY_ENVIRONMENT`, `SEPAY_WEBHOOK_SECRET` (≥32 ký tự), `SEPAY_WEBHOOK_BANK_GATEWAY`, `SEPAY_WEBHOOK_ACCOUNT_NUMBER` và URL công khai tới `/api/v1/payments/sepay/webhook` | Chưa có tài khoản/URL; chạy bằng fixture ký HMAC |
| Google OAuth | `GOOGLE_CLIENT_ID`, origin JavaScript được phép | Chưa có Client ID; chạy bằng token ký cục bộ |
| Thư thử | `MAIL_*` | Mailer `array` trong test, `log` ở demo; chưa thử SMTP thật |
| Firebase | `PUSH_ENABLED`, `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_FILE` | Chưa có dự án; chạy bằng `Http::fake` |
| R2 | `MEDIA_PRIVATE_DISK=r2`, `R2_*` | Chưa có bucket; chạy với MinIO |

Không có secret nào nằm trong repo, log hay biên bản. `docker-compose.r2-test.yml` chỉ chứa khóa dùng một lần cho container MinIO cục bộ.

## Migration và hoàn tác

| Migration | Nội dung | Hoàn tác |
| --- | --- | --- |
| `2026_11_10_000000_create_sepay_receipts` | Cột `payment_transactions.payment_code` (điền cho giao dịch chuyển khoản đang có), bảng `sepay_webhook_events` | Hoàn tác mã trước. Biên nhận là chứng từ tài chính: chỉ `down()` trên môi trường rỗng; có dữ liệu thì sao lưu |
| `2026_11_10_010000_create_business_payout_tables` | `business_payables`, `business_bank_accounts`, `business_withdrawals`, `withdrawal_allocations`, `withdrawal_attempts`, `withdrawal_events`, `payout_ledger_entries` | Như trên; không xóa lịch sử rút tiền khi đã có dữ liệu |
| `2026_11_10_020000_create_chat_and_push_tables` | `chat_conversations`, `chat_messages`, `push_deliveries`, cột `beautybook_device_tokens.active` | Sao lưu hội thoại trước khi quay lui |
| `2026_11_10_030000_create_external_identities_and_reset_codes` | `external_identities`, `password_reset_codes` | Sao lưu liên kết Google trước khi quay lui |

Tất cả là thêm mới, không sửa cột cũ. Dữ liệu cũ không cần backfill (chỉ `payment_code` cho giao dịch chuyển khoản đang chờ). Chưa chạy migration trên stack demo.

## Thay đổi phụ thuộc

`composer.json` thêm `league/flysystem-aws-s3-v3` (kéo theo `aws/aws-sdk-php`) để dùng đĩa S3/R2. `composer audit` báo 7 khuyến cáo ảnh hưởng 4 gói; chưa phân loại xem có thuộc gói mới hay không.

## File trong phạm vi

Xem `git status` của nhánh. Nhóm chính: `app/Services/Finance/{PaymentService,SepayWebhookService}.php`, `app/Services/Payout/*`, `app/Services/Chat/ChatService.php`, `app/Services/Push/*`, `app/Services/Identity/{GoogleIdTokenVerifier,GoogleAccountService,RecoveryCodeService}.php`, `app/Services/Media/PrivateMediaDisk.php`, các controller `Beautybook/{Sepay,Payout,Chat,Auth}Controller`, route `beautybook-finance*.php`, `beautybook-chat.php`, `beautybook.php`, bốn migration, `config/{push,media,services,filesystems}.php`, React `components/chat`, `components/public/{GoogleSignIn,RecoveryCodeForm}.jsx`, `pages/Salon/SepayPayoutPanels.jsx`.

## Kiểm thử

Xem phần kết quả ở cuối biên bản này.

## Kết quả kiểm thử

Môi trường: Docker project `beautybook-zip-core-test` (MySQL 8.4.13, PHP 8.4.27), worktree riêng, MinIO `bitnamilegacy/minio:2025.4.22` cho bucket thử. Trước mỗi lượt, một tiến trình PHP tách khỏi PHPUnit xác minh cấu hình Laravel hiệu lực (`testing`, config cache tắt) và kết nối PDO thực tới `mysql_test:3306/beautybook_test`, tài khoản `beautybook_test`, đúng hostname container; `Tests\TestCase` giữ guard và khóa `beautybook_test_suite`.

| Bộ | Kết quả |
| --- | --- |
| `SepayPayoutTest` (12), `ChatTest` (3), `PushNotificationTest` (4), `GoogleRecoveryTest` (7), `R2PrivateMediaTest` (4) | đạt toàn bộ |
| `ZZConcurrentSepayPayoutTest` | đạt: 6 tiến trình giao cùng một biên nhận, 4 biên nhận khác nhau cùng mã, 5 worker payout cùng một yêu cầu, đều ghi sổ đúng một lần |
| Hồi quy P07 và ảnh kết quả (`ReviewPhotoTest`, `ResultPhotoTest` với đĩa `local`) | đạt; chạy lại với đĩa `r2` (`R2ReviewPhotoTest`, `R2ResultPhotoTest`): đạt, 3 ca khẳng định nội dung đĩa `local` được bỏ qua có chủ đích |
| Toàn bộ PHPUnit | 366 test, 4052 assertion: 365 đạt, 3 bỏ qua; `ZZConcurrentBookingLifecycleRaceTest` trượt một lần do worker không kịp sẵn sàng trong 15 giây, chạy lại riêng đạt (42 assertion) |
| React | `npm run build:beautybook` và `npm run build:laravel` đạt. Repo không có bộ test JavaScript chạy được trong lượt này |
| Trình duyệt (preview trên stack test, Chrome pane) | Đăng nhập chủ cơ sở; chat gửi tin kèm chia sẻ lịch hẹn; webhook SePay qua HTTP thật (chữ ký sai 401, đúng 200, lặp 200, ghi sổ một lần, không cần CSRF); tab Đối soát SePay và Rút tiền hiển thị đúng. Chưa kiểm tra giao diện khách, Google và quên mật khẩu bằng trình duyệt |

Lưu ý và giới hạn:

- Workspace tài chính React bị tắt bởi quyết định sản phẩm `FINANCE_WORKSPACE_ENABLED = false`. Giao diện đối soát và rút tiền chỉ hiện khi cờ này bật; bản bàn giao **giữ cờ tắt**. Kiểm tra trình duyệt dùng bản build tạm bật cờ, sau đó đã khôi phục và build lại.
- Test Blade cũ cần `public/build` (`npm run build:laravel`) trong worktree mới.
- Phần ví (nạp ví), ảnh trong chat, đăng nhập Google thật, SePay Test mode thật, Firebase thật và R2 thật chưa chạy do thiếu tài khoản.
- `composer audit` báo 7 khuyến cáo ở `symfony/http-foundation`, `http-kernel`, `mime`, `routing`; các gói này đã có trong `composer.lock` của baseline, không do gói AWS mới.

## Lượt rà soát 2026-11-11 (thay thế các dòng cũ về kết quả toàn bộ, JavaScript và trình duyệt)

### Thay đổi trong lượt này

1. **Payout × hoàn tiền/đảo giao dịch** (`app/Services/Payout/WithdrawalService.php`). Lỗi tái hiện bằng 6 test mới: sau khi nhận tiền và tạo yêu cầu rút, một hoàn tiền (đang chờ, đã duyệt, đang xử lý hoặc đã hoàn) hay một đảo giao dịch vẫn cho duyệt, thử lại hoặc khôi phục và trả đủ số tiền cũ. Sửa:
   - `payableRows` không còn cắt về 0 theo từng khoản. Số âm nghĩa là tiền đã hoàn sau khi đã phân bổ; tổng `available` được cấn trừ vào các khoản nhận sau và không bao giờ âm.
   - `fundsIntact()` kiểm tra lại nguồn tiền của từng phân bổ dưới khóa doanh nghiệp, trước khi duyệt (`approve`), thử lại (`retry`) và ngay trước khi gọi nhà cung cấp trong `process()` (đường khôi phục). Duyệt/thử lại trả 409 "Nguồn tiền đã thay đổi…"; `process()` chuyển yêu cầu `PROCESSING` sang `FAILED` với lý do `SOURCE_FUNDS_CHANGED`, không ghi lần thử và không gọi MOCK. Chủ cơ sở hủy yêu cầu (nhả phân bổ) rồi tạo lại theo số dư thực.
   - Khóa doanh nghiệp được lấy trước lần đọc không khóa đầu tiên của giao dịch (`review`, `process`) để ảnh chụp của giao dịch đã chứa mọi hoàn tiền/đảo giao dịch đã commit. Luồng hoàn tiền hiện có không đổi (đã khóa doanh nghiệp → booking), không thêm chặn nào lên hoàn tiền.
   - Hoàn tiền bị từ chối hoặc thất bại nhả khoản giữ; yêu cầu gốc được duyệt trả đủ. Hoàn tiền sau khi payout đã hoàn tất được cấn trừ vào khoản nhận sau (số dư rút được giảm, không tạo số âm).
   - `ZZConcurrentSepayPayoutTest` kịch bản C cần phân bổ thật cho yêu cầu dựng tay.
2. **Chat**. Lỗi: sau khi gửi, client đẩy con trỏ lên `sequence` của chính tin vừa gửi, nên tin đối phương đến giữa lần poll cuối và lúc gửi không bao giờ được tải, và bị đánh dấu đã đọc khi chưa thấy. Logic chuyển sang `components/chat/chatSync.js` (không phụ thuộc React): con trỏ chỉ tiến theo những gì lần poll trả về, gửi không đụng con trỏ, gửi xong poll bù ngay, poll tuần tự có lặp lại, xả hết backlog nhiều trang, đánh dấu đã đọc chỉ đến phần đã tải. 10 test JS (máy chủ giả có `after`/`before`, hai phiên khách và cơ sở) và 1 test PHP hai phiên.
3. **Preview nghiệm thu**.
   - `FINANCE_WORKSPACE_ENABLED` đọc từ `VITE_FINANCE_WORKSPACE` lúc build. Mặc định (không đặt biến) vẫn **tắt**. Bật cho preview: `VITE_FINANCE_WORKSPACE=true npm run build:beautybook`.
   - `docker-compose.preview.yml` + `docker/preview/{bootstrap.sh,fixture.php}`: project `beautybook-zip-core-preview` riêng (mạng, MySQL `beautybook_preview`, kho riêng tư và khóa dịch vụ giả lập riêng), cổng 127.0.0.1:8016, scheduler chạy `beautybook:maintain`. `bootstrap.sh` từ chối chạy nếu DB không phải `beautybook_preview`. Mọi secret trong file là giá trị thử cục bộ.
   - Trang chi tiết giao dịch chờ hiện thêm "Số tiền chuyển" (trước đó danh sách hiện 0₫ cho khoản chờ).
4. **Push trên trình duyệt thử**.
   - `PushTrialController` + `routes/beautybook-push-trial-public.php`: giả lập giao thức của endpoint OAuth Google (kiểm tra chữ ký RS256 của khóa dịch vụ, `aud`, `exp`) và `projects/{p}/messages:send` của FCM (Bearer, 404 `UNREGISTERED` giống FCM). Chỉ tồn tại khi `PUSH_TRIAL_SINK=true` **và** môi trường `local`/`testing`; còn lại là 404. `FcmClient` thật không đổi: nó ký JWT, gọi các URL này và xử lý lỗi như với Google.
   - Trình duyệt: `utils/browserPush.js` (đăng ký thiết bị `WEB`, nhận, gỡ; thiết bị gắn với tài khoản đang đăng nhập; đăng xuất tự gỡ), nút Bật/Tắt trong trang Thông báo (`BrowserPush.jsx`), bộ nhận ngầm trong `AppShell` và `CustomerShell`, service worker `public/beautybook-push-sw.js`. API: `GET notifications/push-config`, `GET notifications/push-trial/messages?token=`, `DELETE notifications/device-token`.
   - **Chưa có** kênh Web Push thật qua Firebase (cần Firebase web config, VAPID key, SDK); `push-config` trả `mode: off` khi không ở chế độ thử.

### Kết quả (cây làm việc cuối, chưa commit)

| Bước | Lệnh/phạm vi | Exit | Kết quả |
| --- | --- | --- | --- |
| Kiểm tra cấu hình hiệu lực và PDO thật, tiến trình tách khỏi PHPUnit | `check.php` trước mỗi lượt | 0 | `testing`, config cache tắt, `mysql_test:3306/beautybook_test`, `beautybook_test@%`; `DB-CHECK: OK` |
| Test tập trung | `SepayPayoutTest` (+ concurrent), `ChatTest`, `PushNotificationTest`, `PushTrialSinkTest` | 0 | payout 6 test mới đỏ trước sửa, xanh sau; chat 4 test; push 5 + 3 |
| JavaScript hiện có | `node --test` toàn bộ `resources/beautybook/src/**/*.test.js` (143, gồm 15 mới) + `realtime/server.test.mjs` + `scripts/frontend-parity-cleanup.test.mjs` (6) | 0 | 149 đạt, 0 trượt, 0 bỏ qua |
| Build | `npm run build` (`build:laravel` + `build:beautybook`, cờ mặc định) | 0 | đạt; bản mặc định biên dịch cờ tài chính thành `false` |
| Toàn bộ PHPUnit | `phpunit` trong `beautybook-zip-core-test` | 0 | 378 test, 4075 assertion, 0 trượt, 13 bỏ qua: cả 13 là bộ R2 vì lượt này thiếu `R2_TEST_ENDPOINT` |
| Bộ R2 với MinIO | `--filter R2` có `R2_TEST_*` | 0 | 13 test, 179 assertion, 10 chạy, 3 bỏ qua có chủ đích (khẳng định nội dung đĩa `local`). Hai lượt cộng lại phủ đủ 378 test; 3 bỏ qua có chủ đích còn lại |

Trình duyệt (khung Chromium của ứng dụng Claude, **không phải Chrome của bạn**: tiện ích Claude in Chrome không kết nối), preview 127.0.0.1:8016, bản build bật cờ tài chính. Hai phiên song song bằng hai origin (`127.0.0.1` và `localhost` có cookie riêng):

| Vai trò | Kiểm tra | Kết quả |
| --- | --- | --- |
| Chủ cơ sở, desktop | webhook SePay ký bằng curl (sai secret 401; thiếu tiền → MANUAL_REVIEW `UNDERPAID`; đúng → APPLIED, sổ ghi 1 lần; gửi lại cùng id nhưng khác nội dung → 409); tab Đối soát và Rút tiền; yêu cầu rút 80.000₫ rồi hoàn 150.000₫ qua giao diện | đạt |
| Quản trị, desktop | hàng đợi rút tiền; duyệt khi đang có hoàn tiền → thông báo "Nguồn tiền đã thay đổi…"; từ chối hoàn tiền rồi duyệt → `SIMULATED_COMPLETED`, 1 bút toán, 1 lần thử | đạt |
| Khách, desktop | chat hai phiên với chủ cơ sở, tin đối phương đến giữa lúc poll và lúc gửi vẫn hiện đủ theo thứ tự, đã đọc = 3/3 | đạt |
| Khách, desktop | đăng ký thiết bị push, nhận push của tin chat qua `FcmClient` thật tới endpoint giả lập, gỡ (thiết bị `active=0`) | đạt, **với phần hiển thị thông báo hệ điều hành thay bằng giả lập** (xem giới hạn) |
| Khách / chủ cơ sở / quản trị, mobile 375×812 | chat, thông báo, thanh toán, đối soát, rút tiền, hàng đợi: không tràn ngang | đạt |

Giới hạn đã biết của lượt này:
- Khung trình duyệt của ứng dụng chặn quyền thông báo (`denied`) và không đăng ký được service worker (`unknown error when fetching the script`, tệp vẫn tải được 200 `application/javascript`). Để chạy phần còn lại, trang được vá tại chỗ: `Notification.permission` = granted, `serviceWorker.register`/`ready` và `showNotification` giả. Vì vậy hiển thị thông báo hệ điều hành và service worker thật **chưa được kiểm chứng**; cần chạy lại trên Chrome thật.
- Trang chi tiết giao dịch chờ có thêm "Số tiền chuyển" nhưng chưa được xem lại bằng mắt vì khoản chờ duy nhất của preview đã được thanh toán trước khi build; logic chỉ là một biểu thức hiển thị.
- Quan sát baseline ngoài phạm vi: bảng Sổ tài chính hiển thị `PAYMENT_RECEIVED` (CREDIT) với dấu "−" (`PaymentCorePanels.jsx:200`, đảo dấu CREDIT/DEBIT). Chưa sửa.
- Preview chạy bằng `php artisan serve` nhiều worker; chưa kiểm tải.

## Lượt tiếp theo 11/11/2026: ví, SePay đúng vai trò, cọc, chat ảnh, chính sách push

**Bảng đối chiếu có thể kiểm tra lại: [BANG-DOI-CHIEU.md](BANG-DOI-CHIEU.md).** Phần này thay thế các mục cũ nói về SePay tự khớp thanh toán booking, khoản phải trả sinh từ thanh toán, `PayoutController`/`payouts/*` và `payment_code`.

### Quyết định làm đổi nghiệp vụ so với bản trước (đã nêu theo yêu cầu)

1. **Endpoint SePay thường chỉ lưu biên nhận.** Nguồn ghi rõ nó là hộp thư bền, "never a wallet credit". Bản trước tự khớp mã `BB…` vào thanh toán booking: đã gỡ (`PaymentService` về baseline, bỏ cột `payment_code`). Thanh toán tay giữ nguyên.
2. **Tiền chỉ vào ví qua kênh Live-lab riêng** (`payments/sepay/wallet-live-lab/webhook`, bí mật riêng, tài khoản nhận riêng, danh sách người dùng/doanh nghiệp, hạn mức). Hóa đơn nạp ví mã `WL…`.
3. **Khoản phải trả sinh từ cọc đã giải phóng**, không còn từ thanh toán booking. Rút tiền, phân bổ và số dư dẫn xuất lấy từ đó, theo scope ví (`DEMO`/`LIVE_LAB`).
4. **Tài khoản ngân hàng cần admin xác minh** (PENDING → VERIFIED_DEMO), thay cho việc tự duyệt khi thêm.
5. **Route đổi sang `wallet/*`** theo `wallet.controller.ts`; `Idempotency-Key` là header.
6. **Push theo chính sách nguồn**: chỉ sự kiện đặt lịch (xác nhận, từ chối, hủy, kết quả đổi lịch, nhắc 1 giờ) và chỉ cho khách của lịch; **tin chat không còn gây push**.

### Cấu hình môi trường mới (không có bí mật trong repo)

`BEAUTYBOOK_PAYMENT_MODE` (`INTERNAL_WALLET`/`SIMULATED_WALLET`, rỗng = như cũ), `WALLET_LIVE_LAB_{ISOLATED,ENABLED,USER_IDS,BUSINESS_IDS,WEBHOOK_SECRET,BANK_CODE,ACCOUNT_NUMBER,ACCOUNT_NAME,MIN_TOPUP_VND,MAX_TOPUP_VND,MAX_TOTAL_VND}`. Lab bị từ chối khi `APP_ENV=production`. Endpoint thường vẫn dùng `SEPAY_WEBHOOK_*`.

### Migration và hoàn tác

Các migration trong lượt này chưa từng chạy ở demo, nên được sửa tại chỗ và vẫn chỉ **thêm**:

| Migration | Thêm | Ảnh hưởng dữ liệu cũ | Hoàn tác |
| --- | --- | --- | --- |
| `2026_11_10_000000_create_sepay_receipts` | `sepay_webhook_events` (kênh, trạng thái, lý do đối soát, `top_up_id`) | không | `down()` xóa bảng; biên nhận là chứng từ tài chính, chỉ xóa trên môi trường rỗng |
| `2026_11_10_005000_create_wallet_core` | `wallets`, `wallet_transfers`, `wallet_topups`, `booking_deposits`, `deposit_refunds` | không đụng booking cũ; booking cũ không có cọc (không thu hồi tố) | `down()` xóa theo thứ tự khóa ngoại; **số dư và sổ ví là dữ liệu tiền, sao lưu trước khi xóa** |
| `2026_11_10_010000_create_business_payout_tables` | `business_payables` (từ cọc), `business_bank_accounts`, `business_withdrawals`, `withdrawal_*` | không | như trên; bỏ bảng `payout_ledger_entries` (bút toán rút dùng `wallet_transfers`) |
| `2026_11_10_020000_create_chat_and_push_tables` | `chat_*` (thêm `chat_attachments`), `push_deliveries`, cột `beautybook_device_tokens.active` | cột mới mặc định `active = 1`; thiết bị cũ vẫn hợp lệ | `down()` xóa bảng và cột; **hội thoại và ảnh riêng tư là dữ liệu khách**: sao lưu bảng và các tệp `chat-images/` trước khi hoàn tác |
| `2026_11_10_030000_create_external_identities_and_reset_codes` | `external_identities`, `password_reset_codes` | không | `down()` xóa bảng |

Nếu một môi trường đã chạy bản migration cũ của lượt 10–11/11 (chỉ có stack test và preview), chạy `migrate:fresh` trên chính môi trường đó; demo `beautybook-php` chưa chạy các migration này.

### Preview nghiệm thu

`docker-compose.preview.yml` (project `beautybook-zip-core-preview`, 127.0.0.1:8016) nay bật ví Live-lab với giá trị thử cục bộ; `docker/preview/fixture.php` nạp ví, một lịch đã hoàn tất (khoản phải trả 30.000₫), một lịch đang giữ cọc, một hóa đơn nạp còn chờ và một tài khoản ngân hàng PENDING. Giao diện tài chính cần bản build `VITE_FINANCE_WORKSPACE=true`; bản build mặc định vẫn tắt theo quyết định sản phẩm.

### Kết quả chạy trên cây làm việc cuối (11/11/2026, chưa commit)

| Bước | Exit | Kết quả |
| --- | --- | --- |
| Kiểm tra cấu hình hiệu lực và PDO thật (tiến trình tách khỏi PHPUnit, trước mỗi lượt) | 0 | `testing`, config cache tắt, `mysql_test:3306/beautybook_test`, `beautybook_test@%`; `DB-CHECK: OK` |
| Test tập trung | 0 | `SepayInboxTest` 4, `WalletLiveLabTest` 7, `WalletDepositTest` 5, `WalletPayoutTest` 8, `ZZConcurrentWalletTest` 1 (5 kịch bản song song), `ChatTest` 6, `PushNotificationTest` 6, `PushTrialSinkTest` 3. Test tái hiện payout × hoàn tiền và con trỏ chat đã đỏ trên code cũ ở lượt trước |
| JavaScript | 0 | `node --test` toàn bộ `resources/beautybook/src/**/*.test.js` + `realtime/server.test.mjs` + `scripts/frontend-parity-cleanup.test.mjs`: 149 đạt, 0 trượt, 0 bỏ qua |
| Build | 0 | `npm run build` (cờ tài chính mặc định **tắt**) |
| Toàn bộ PHPUnit (có MinIO) | 0 | 389 test, 4567 assertion, 0 trượt, **3 bỏ qua có chủ đích** (ca R2 khẳng định nội dung đĩa `local`) |
| Trình duyệt (khung Chromium của ứng dụng, desktop, preview) | — | Khách: ví 75.000₫ + cọc giữ 45.000₫, tạo hóa đơn nạp có QR và mã `WL…`, biên nhận thiếu tiền vào "cần đối soát", biên nhận đúng tự cộng ví (95.000₫) sau ≤5 giây. Chủ cơ sở: khoản phải trả 30.000₫, tài khoản chưa xác minh không chọn được. Quản trị: xác minh tài khoản, thấy biên nhận cần đối soát, duyệt rút → `SIMULATED_COMPLETED`, một bút toán `SETTLEMENT`. Chưa chạy lại mobile và chưa chạy giao diện ảnh chat trong lượt này |

## Chặng vòng đời cọc booking (11/11/2026)

Đối chiếu lại ZIP API: `booking-aggregate.ts`, `booking-pricing.ts`, `recurring.service.ts`, `recurring-command.ts`, `deposit-core.ts`, `wallet-core.ts`, `booking-transitions/cancellation/change-request-review/pending-expiry`, `direct-deposit.service.ts` (`store-receipt`) và `docs/wallet-deposit-sepay-design-20261030.md`. Mỗi khoảng lệch có test viết trước (16/19 test đỏ trên cây cũ, đúng lý do), rồi mới sửa.

### Khoảng lệch đã đóng

| Khoảng lệch (cây trước) | Nguồn | Sửa |
| --- | --- | --- |
| Giờ hủy cố định 4 giờ ở quote, mốc hủy trực tiếp, ngưỡng "hủy muộn" của yêu cầu hủy | `branchBookingPolicy.cancellationHours` | `BranchBookingRules` đọc chính sách chi nhánh do giao diện chủ cơ sở ghi; cọc ghi ảnh chụp số giờ lúc đặt |
| `MANUAL` cố định, "Tự động xác nhận" trong giao diện chủ cơ sở không có tác dụng | `confirmationMode`, `confirmed` trong `writeBookingAggregate` | `CustomerBookingFinalizer`: AUTO xác nhận cùng transaction (lịch sử `AUTO_CONFIRMATION_POLICY`, hiệu lực khuyến mãi/voucher, thông báo), áp dụng cả khi ví tắt; đặt hộ tại quầy không bị AUTO |
| `pending_expires_at` theo cài đặt nền tảng, tách khỏi hạn cọc | `pendingHoldMinutes` chi nhánh, `deposit.expiresAt` | Giữ chỗ theo chi nhánh (mặc định vẫn là nền tảng); `booking.pending_expires_at` = `deposit.expires_at` = min(giờ đặt + giữ chỗ, giờ hẹn) |
| Đặt combo không giữ cọc | mọi lịch đi qua `writeBookingAggregate` | `CreateComboBookingService` gọi cùng `CustomerBookingFinalizer` (đơn, giỏ, combo dùng chung một hàm) |
| Lịch định kỳ không có cọc | quote từng kỳ, giữ cọc từng kỳ, all-or-nothing | preview trả cọc từng kỳ và tổng; tạo chuỗi nhận `walletDepositQuotes[index]`; thiếu số dư/quote cũ/thiếu quote → rollback toàn bộ; `skipConflicts` không giữ cọc cho kỳ bị bỏ qua |
| Duyệt yêu cầu hủy do cơ sở tạo bị tính là khách hủy muộn (tịch thu cọc) | `customerCancelled` chỉ khi khách yêu cầu | `customerCancelled = requested_by_type === 'CUSTOMER'` |
| Ràng buộc DB `chk_booking_violation_source_time` cố định 4 giờ | — | Migration mới `2026_11_11_000000_widen_late_cancellation_window` mở tới 168 giờ (tối đa chủ cơ sở được cấu hình) |
| Thu phần còn lại tại cửa hàng có thể thu cả tổng (trùng cọc), không chặn hủy sau khi đã thu | `store-receipt`, `storeReceivedAt` | `PaymentService::balance` tính cọc là đã trả (`deposit_cents`); chỉ thu khi khách đã đến; thu xong đánh dấu `booking_deposits.store_received_*` (cột mới `store_received_by_id`, `store_reference` trong migration ví chưa merge), đảo giao dịch xóa dấu; hóa đơn, phí nền tảng, hoàn tiền tính cọc là đã thanh toán |

### Quyết định làm đổi nghiệp vụ (nêu theo yêu cầu)

- Chi nhánh chưa lưu `cancellationHours` giữ **4 giờ** (hành vi trước đây) chứ không phải 2 giờ của nguồn; chi nhánh chưa lưu thời gian giữ chỗ giữ cài đặt nền tảng (30 phút), nguồn là 15 phút. Chi nhánh đã đi qua bước "Chính sách đặt lịch" (bắt buộc để nhận lịch) luôn có giá trị riêng.
- Thời gian giữ chỗ theo chi nhánh áp dụng cho **mọi** lịch tạo mới (kể cả đặt hộ tại quầy), không chỉ lịch có cọc.
- Lịch AUTO tự xác nhận cả khi không dùng ví.
- Thu tại cửa hàng cho lịch có cọc bị từ chối (409) trước khi khách check-in.
- `rescheduleHours`, `gracePeriodMinutes` (NO_SHOW), `earlyCheckInMinutes` của chi nhánh **chưa** được áp dụng (vẫn cố định như trước): ngoài phạm vi cọc.

### Migration và hoàn tác (thêm vào bảng trên)

| Migration | Thêm | Ảnh hưởng dữ liệu cũ | Hoàn tác |
| --- | --- | --- | --- |
| `2026_11_10_005000_create_wallet_core` (sửa tại chỗ, chưa merge) | `booking_deposits.store_received_by_id`, `store_reference` | không | cùng `down()` của bảng |
| `2026_11_11_000000_widen_late_cancellation_window` (mới) | thay CHECK `chk_booking_violation_source_time` (4 → 168 giờ) | các sự kiện cũ vẫn hợp lệ | `down()` đặt lại 4 giờ; **sẽ lỗi nếu đã có sự kiện hủy muộn ngoài 4 giờ**, khi đó phải xử lý (void/xóa có kiểm soát) các sự kiện đó trước khi hoàn tác |

Demo `beautybook-php` không bị chạm tới; preview chạy `migrate:fresh` trên DB `beautybook_preview` của riêng nó.

### Kết quả (cây làm việc cuối, chưa commit)

| Bước | Exit | Kết quả |
| --- | --- | --- |
| Cấu hình hiệu lực + PDO thật (tiến trình tách khỏi PHPUnit) | 0 | `mysql_test:3306/beautybook_test`, `beautybook_test@%`, config cache tắt; `DB-CHECK: OK` |
| `BookingDepositLifecycleTest` (mới) | 0 | 19 test, 219 assertion: chính sách chi nhánh (giờ hủy, giữ chỗ, AUTO/MANUAL), combo, định kỳ (báo giá, giữ, replay, rollback, skip, hủy một kỳ), mọi kết cục (từ chối, chủ hủy, hoàn tất, NO_SHOW, yêu cầu hủy của khách/cơ sở), thu phần còn lại |
| Toàn bộ PHPUnit (có MinIO) | 0 | **408 test, 4786 assertion, 0 trượt, 3 bỏ qua có chủ đích** (ca R2 khẳng định nội dung đĩa `local`); chạy 12 phút 27 giây |
| JavaScript | 0 | 155 đạt, 0 trượt, 0 bỏ qua (`walletDeposit.test.js` thêm 1 test cho chuỗi) |
| Build mặc định (cờ tài chính tắt) | 0 | `npm run build` |
| Trình duyệt (khung Chromium của ứng dụng, preview `127.0.0.1:8016`, desktop) | — | Chủ cơ sở đặt "Tự động xác nhận" + giữ chỗ 20 phút trong Hồ sơ chi nhánh (DB ghi đúng khóa, `BranchBookingRules` đọc ra AUTO/20); khách đặt lẻ: khối cọc ghi "hủy trước hạn 24 giờ", lịch `CONFIRMED`, `pending_expires_at` rỗng, cọc 54.000₫ `HELD` (AUTO, 24 giờ), ví 21.000₫/99.000₫; lịch định kỳ 4 kỳ: 4 × 54.000₫ = 216.000₫, ví thiếu → nút khóa và báo thiếu 195.000₫, nạp 200.000₫ (biên nhận ký cục bộ) → đặt được, 4 lịch `CONFIRMED` và 4 cọc `HELD`, ví 5.000₫/315.000₫; chủ cơ sở: màn Thanh toán hiện "Tổng 180.000₫ · đã cọc 54.000₫ · còn thu 126.000₫", thu tiền mặt để trống = 126.000₫, cọc được đánh dấu đã thu tại cơ sở |

Mức xác minh: fixture/MinIO/trial như các lượt trước; phần trình duyệt chạy trên preview cục bộ với biên nhận SePay Live-lab **ký cục bộ** (không phải SePay thật) và chính sách `cancellationHours = 24` ghi thẳng vào DB preview (giao diện chủ cơ sở chỉ sửa giờ hủy ở bước thiết lập chi nhánh, chưa kiểm tra bước đó). Lịch check-in cho thử thu tiền được đặt bằng cách sửa DB preview. Chưa kiểm tra mobile 375px cho khối cọc của lịch định kỳ và màn Thanh toán; chưa kiểm tra Chrome thật.

## Chặng ràng buộc push với phiên và kiểm tra ảnh chat (11/11/2026)

Đối chiếu nguồn: `notifications/push-devices.service.ts`, `push-queue.ts`, `push.worker.ts`, `dto/push.dto.ts`, migration `20261101100000_android_push`; `media/media-storage.ts` (`get`), `chat/chat.service.ts` (`content`). Test viết trước và chạy đỏ đúng lý do (10 test đỏ, trong đó có lỗi thật: nhắc lịch của lịch đã hủy vẫn được gửi), rồi mới sửa.

### Khoảng lệch đã đóng

| Khoảng lệch | Cách đóng |
| --- | --- |
| Thiết bị không gắn với phiên hay installation; token của tài khoản này bị khóa bởi tài khoản khác (409) | Cột `installation_id`, `session_id_hash` (migration chưa merge `2026_11_10_020000`, sửa tại chỗ); đăng ký cần `installationId` uuid; một installation một token; đăng ký lại từ phiên mới gắn lại; token đổi tay sang tài khoản khác thì gán lại |
| Push xếp hàng cho phiên cũ vẫn gửi sau khi thiết bị gắn lại | `push_deliveries.session_id_hash`; gửi chỉ khi trùng phiên hiện tại của thiết bị |
| Phiên hết hạn/thu hồi vẫn nhận push | `PushService::sessionAlive` (bảng `sessions`, driver `database`) khi xếp hàng và khi gửi; thu hồi chủ động ở đăng xuất, `revokeSession`, `revokeOthers`, đổi mật khẩu, đặt lại mật khẩu, `revokeSessions` kiểu web, tài khoản bị khóa |
| Gỡ thiết bị theo token từ phiên khác | Gỡ theo `installationId` hoặc token, chỉ khi cùng tài khoản và cùng phiên |
| Nhắc lịch gửi cho lịch đã hủy/dời giờ, khách đã xóa | Kiểm tra lại lúc gửi: CONFIRMED, chưa bắt đầu, `dedupe_key` đúng giờ hiện tại, hồ sơ khách chưa xóa; không nhắc tài khoản bị khóa |
| Payload FCM thiếu định danh phiên | Thêm `sessionId`, `data.body` (JSON), `title`, `message` |
| Đọc ảnh chat không kiểm tra byte | `PrivateMediaDisk::readVerified`: đọc tối đa size+1 byte, khớp độ dài và SHA-256 của `chat_attachments`, khớp `media_files.file_size`, 1 byte đến 10 MB; sai → 404 (kèm `report`), thiếu → 404, bucket lỗi → 503 |

Giao diện web: `browserPush.js` tạo `installationId` một lần cho mỗi trình duyệt (khóa `bb_push_installation`, không theo tài khoản), gửi khi đăng ký/gỡ, và `sync` gắn lại thiết bị mỗi lần vào ứng dụng sau đăng nhập.

### Quyết định làm đổi nghiệp vụ (nêu theo yêu cầu)

- Token đang gắn với tài khoản A mà tài khoản B đăng ký lại sẽ được gán cho B (như nguồn) thay cho 409 của bản trước; push xếp hàng cho A bị bỏ qua vì thiết bị không còn thuộc A.
- Gỡ thiết bị chỉ có hiệu lực từ phiên đã đăng ký thiết bị.
- Thiết bị đã đăng ký trước migration này không có phiên: migration đặt `active = false`, thiết bị phải đăng ký lại.
- Đăng ký thiết bị cũ thiếu `installationId` hoặc token ngắn hơn 32 ký tự bị từ chối (422).

### Migration và hoàn tác

`2026_11_10_020000_create_chat_and_push_tables` (chưa merge): thêm hai cột và hai chỉ mục trên `beautybook_device_tokens`, cột `session_id_hash` và khóa duy nhất mới trên `push_deliveries`; `down()` bỏ đúng những thứ này. Demo `beautybook-php` không bị chạm; preview chạy `migrate:fresh` trên `beautybook_preview` của riêng nó.

### Kết quả (cây làm việc, chưa commit)

| Bước | Exit | Kết quả |
| --- | --- | --- |
| Cấu hình hiệu lực + PDO thật (tiến trình tách khỏi PHPUnit) | 0 | `mysql_test:3306/beautybook_test`, `beautybook_test@%`; `DB-CHECK: OK` |
| `PushSessionBindingTest` (mới) + `PushNotificationTest` + `PushTrialSinkTest` | 0 | 18 test, 209 assertion |
| `ChatTest` (thêm đọc ảnh có xác minh) + `R2PrivateMediaTest` (thêm đọc có xác minh trên MinIO) | 0 | trong lượt toàn bộ |
| Toàn bộ PHPUnit (có MinIO) | 0 | **419 test, 4897 assertion, 0 trượt, 3 bỏ qua có chủ đích**; 14 phút 57 giây (trước khi thêm `sessionId` vào payload, sau đó chạy lại 18 test push: đạt) |
| JavaScript | 0 | 158 đạt, 0 trượt (`resources/beautybook/src/**/*.test.js` + `realtime/server.test.mjs` + `scripts/frontend-parity-cleanup.test.mjs`) |
| Build mặc định (cờ tài chính tắt) và build bật cờ cho preview | 0, 0 | `npm run build:beautybook` |
| Trình duyệt (khung Chromium, preview `127.0.0.1:8016`, phiên `database` thật) | — | Đăng nhập khách bằng giao diện; đăng ký thiết bị không `installationId` → 422, có → 200 và thiết bị gắn đúng hash phiên đang sống; chủ cơ sở (jar cookie riêng) xác nhận lịch → 1 push xếp hàng cho phiên đó và nhận qua trial FCM với `sessionId` đúng; khách đăng xuất → thiết bị tắt, chủ cơ sở hủy lịch sau đó không xếp hàng gì; đăng nhập lại và đăng ký lại → thiết bị gắn sang phiên mới. Ảnh chat: tải lên 201, đọc 200 đúng 68 byte; thay byte cùng độ dài trên đĩa preview → 404; khôi phục → 200 |

Giới hạn xác minh: nút "Bật" thông báo trong giao diện không bấm được vì khung trình duyệt của ứng dụng chặn quyền thông báo và service worker, nên đăng ký, đăng xuất và gắn lại thiết bị trên preview được gọi bằng API thật từ trang đã đăng nhập (cùng cookie phiên và CSRF), không bằng nút; `sync` chỉ có test JS. FCM là trial, không phải Firebase thật; MinIO không phải R2; Chrome thật chưa kiểm tra.

## Chặng cọc trực tiếp theo doanh nghiệp và đối soát lô (11–12/11/2026)

Đối chiếu nguồn: `payments/deposit-core.ts`, `direct-deposit.{controller,service}.ts`, `wallet.service.ts` (`createSettlement`, `completeSettlement`), `business-withdrawal.service.ts` (`withdrawalBalances`), migration `20261030010000_direct_business_deposits`, `20261030020000_simulated_wallet_settlements`, tài liệu `direct-business-deposit-sepay-20261030.md`, test `direct-deposit-{core,postgres}.test.cjs`.

### Đã làm

| Phần | Nội dung |
| --- | --- |
| Đối soát lô | Bảng `sim_settlements`; tạo (admin, `Idempotency-Key`, kỳ ≤ 366 ngày, tài khoản mặc định đã xác minh) và hoàn tất (một dòng sổ `SETTLEMENT`, lặp lại không ghi thêm); số dư rút đưa đợt chờ vào “đang giữ”, đợt xong vào “đã rút”; khóa dòng doanh nghiệp để rút tiền và đối soát không cùng lấy một khoản; tab “Đối soát lô” |
| Kết nối nhận cọc | `payment_connections`: secret AES-256-GCM gắn với id dòng, hiện một lần; mã xác minh `GS…`; chỉ một kết nối bật mỗi doanh nghiệp; chỉ chủ doanh nghiệp đã duyệt; giới hạn 5 kết nối đang thiết lập; cờ `DIRECT_DEPOSIT_ENABLED` và khóa `SEPAY_CONNECTION_ENCRYPTION_KEY` (64 hex, ngoài repo) |
| Webhook theo kết nối | Ký HMAC bằng secret của chính kết nối, đúng tài khoản; biên nhận lưu một lần theo (kết nối, môi trường, mã giao dịch), 12 lần gửi lại không đổi gì; khóa lịch rồi biên nhận; một biên nhận chỉ trả một khoản cọc |
| Đặt lịch | Báo giá cọc 30% (làm tròn lên) gắn tài khoản nhận; đặt lịch cần đúng quote; lịch `PENDING` cho đến khi nhận cọc; hạn giữ chỗ trùng hạn cọc; tất cả đường tạo (lẻ, giỏ, combo, định kỳ từng kỳ) đi qua `CustomerBookingFinalizer` |
| Biên nhận | Đúng tiền: PAID (AUTO xác nhận ngay, MANUAL gia hạn giữ chỗ); sai tiền, trùng, trễ, lịch không còn khả dụng: khoản hoàn `AMOUNT_MISMATCH/DUPLICATE_PAYMENT/LATE_PAYMENT/BOOKING_UNAVAILABLE`, không khôi phục lịch; không mã/sai mã/nhiều mã/tiền ra: giữ lại cho chủ cơ sở xem |
| Vòng đời | Không xác nhận/check-in/hoàn thành/NO_SHOW khi chưa nhận cọc (409); hoàn thành → APPLIED; hủy trước thanh toán → VOIDED; hết hạn → EXPIRED; đã trả mà từ chối/hủy đúng hạn/hết hạn không duyệt → REFUND_DUE; hủy muộn theo yêu cầu của khách và NO_SHOW → FORFEITED; thu tại cơ sở chỉ phần còn lại và chặn hủy sau khi đã thu |
| Hoàn tiền | Khách khai báo tài khoản nhận → chủ cơ sở báo mã chuyển trả (lễ tân không được) → khách xác nhận; cọc chỉ thành REFUNDED khi mọi khoản hoàn được xác nhận; phiên bản sai là 409 |
| Giao diện | Khối “Cọc chuyển khoản trực tiếp” ở bước xác nhận (không đòi số dư ví); `DirectDepositPanel` ở trang thành công, chi tiết lịch của khách và ngăn chi tiết của chủ cơ sở (thông tin chuyển khoản có nút sao chép, đếm ngược, tự cập nhật khi nhận tiền, các bước hoàn tiền); tab “Nhận cọc trực tiếp” |

### Lỗi thật tìm thấy và sửa trong chặng này

- `AppointmentChangeRequestService` truyền thời điểm yêu cầu hủy vào cọc như giờ địa phương trong khi cột lưu UTC, lệch 7 giờ: yêu cầu hủy 3 giờ trước giờ hẹn (mốc 4 giờ) bị coi là đúng hạn và hoàn cọc ví. Test hồi quy viết trước (đỏ), sau đó sửa; sai sót này có từ chặng cọc ngày 11/11.
- `DirectDepositPanel` gọi lại hàm tải trang mỗi lần render làm trống trang chi tiết lịch của khách; sửa để chỉ báo một lần khi trạng thái đổi.

### Quyết định làm đổi nghiệp vụ (nêu theo yêu cầu)

- Chế độ ví và chế độ trực tiếp loại trừ nhau; khi cả hai cấu hình thì ví thắng (như `walletMode()` của nguồn).
- Báo giá của lịch định kỳ là từng kỳ (như chặng cọc ví), không một quote cho cả chuỗi.
- Cọc trực tiếp không tạo khoản phải trả cho doanh nghiệp: tiền đã nằm ở doanh nghiệp; đối soát lô và rút tiền chỉ áp dụng cho cọc ví.
- Biên nhận cũ (kênh thường và ví Live lab) giữ nguyên khóa duy nhất theo (môi trường, mã giao dịch) qua cột sinh; biên nhận của kết nối duy nhất theo kết nối.

### Migration và hoàn tác

Sửa tại chỗ các migration chưa merge: `2026_11_10_000000_create_sepay_receipts` (bảng `payment_connections`, cột kết nối/khoản cọc, khóa duy nhất mới), `2026_11_10_005000_create_wallet_core` (trạng thái cọc mở rộng, ảnh chụp tài khoản nhận, hoàn tiền theo biên nhận và tài khoản nhận hoàn), `2026_11_10_010000_create_business_payout_tables` (`sim_settlements`, khóa ngoại `settlement_id`). `down()` bỏ đúng những thứ này. Không có migration mới; demo `beautybook-php` không bị chạm. Preview chạy `migrate:fresh` trên `beautybook_preview` của riêng nó, với `PREVIEW_PAYMENT_MODE=` (rỗng) để xem chế độ trực tiếp.

### Kết quả (cây làm việc, chưa commit)

| Bước | Exit | Kết quả |
| --- | --- | --- |
| Cấu hình hiệu lực + PDO thật (tiến trình tách khỏi PHPUnit) | 0 | `mysql_test:3306/beautybook_test`; `DB-CHECK: OK` |
| `WalletSettlementTest` (5), `DirectDepositTest` (20), hồi quy múi giờ, `ZZConcurrentWalletTest` (thêm E, F) | 0 | đạt |
| Toàn bộ PHPUnit (có MinIO) | — | **445 test, 5448 assertion, 0 trượt, 3 bỏ qua có chủ đích** (lượt đầu 445/5436 có 2 test của chính file mới trượt do phụ thuộc đồng hồ và ngày trong tuần, đã sửa trong file test rồi chạy lại toàn bộ) |
| JavaScript | 0 | 163 đạt, 0 trượt (thêm `directDeposit.test.js` và ca cọc trực tiếp của `walletDeposit.test.js`) |
| Build mặc định và build bật cờ cho preview | 0, 0 | `npm run build:beautybook` |
| Trình duyệt (khung Chromium, preview `127.0.0.1:8016`, desktop, chế độ trực tiếp) | — | Chủ cơ sở tạo kết nối Test bằng giao diện (secret hiện một lần, kết nối thứ hai tạo nhầm cho thấy secret không lấy lại được), xác minh bằng biên nhận ký cục bộ, bật “Đang nhận cọc”; khách đặt lịch qua giao diện: khối cọc 54.000₫ + 126.000₫ không cần ví, trang thành công hiện thông tin chuyển khoản; biên nhận thiếu tiền (50.000₫) tạo khoản hoàn, biên nhận đủ 54.000₫ tự chuyển panel sang “Đã nhận cọc”; khách khai báo tài khoản nhận hoàn, chủ cơ sở báo mã chuyển trả trong ngăn chi tiết, khách xác nhận “đã nhận đủ” trên trang chi tiết |

| Trình duyệt (khung Chromium, mobile 375×812, 12/11/2026, chế độ trực tiếp) | — | Khách: chi tiết lịch có khối cọc đã nhận + hoàn tiền, màn xác nhận có thông báo cọc 54.000₫ + 126.000₫, đặt lịch mới ra trang thành công với trạng thái “Chờ chuyển khoản”, 5 dòng sao chép, đếm ngược 30 phút; không tràn ngang (`scrollWidth` = 375). Chủ cơ sở: hai tab “Đối soát lô” (trạng thái trống, vai trò chủ cơ sở chỉ xem) và “Nhận cọc trực tiếp” (form kết nối, danh sách kết nối, biên nhận cần đối soát) hiển thị đúng, không tràn ngang. Phát hiện duy nhất: nút sao chép 32×32px, đã nới lên 40px trên mobile (`DirectDepositPanel.jsx`); thay đổi này đã qua build và test JS nhưng chưa nhìn lại trên mobile sau khi sửa |

| Trình duyệt (khung Chromium, desktop, 12/11/2026, preview chế độ ví, fixture ví dựng lại bằng `bootstrap.sh`) | — | Quản trị: xác minh tài khoản ngân hàng PENDING (doanh nghiệp mới xuất hiện trong ô chọn của tab “Đối soát lô”), tạo đợt kỳ 13/10–13/11/2026 → đợt #1 30.000₫ “Chờ hoàn tất”; tạo lại cùng kỳ → 409 “No eligible unsettled deposits in this period” (toast bằng tiếng Anh, là thông báo gốc của API ví) và vẫn một đợt; “Hoàn tất (mô phỏng)” → “Đã hoàn tất (mô phỏng)”. DB preview: `sim_settlements` 1 dòng `SIMULATED_PAID`, `wallet_transfers` đúng một dòng `SETTLEMENT` (`settled:1`, `business:1:payable` → `demo:settled`, 30.000₫, lý do `SIMULATED_SETTLEMENT_NO_BANK_TRANSFER`). Chủ cơ sở: “Có thể rút 0₫”, “Đã rút (mô phỏng) 30.000₫”, tab “Đối soát lô” hiện đợt #1 đã hoàn tất. Sửa hiển thị kỳ thành ngày (“13/10/2026 – 13/11/2026 (không gồm ngày cuối)”) thay cho “00:00:00 …” |

Giới hạn xác minh: biên nhận là **ký cục bộ**, không phải SePay thật; chế độ Live (QR VietQR) chỉ kiểm bằng test (trang chờ chuyển khoản trên preview ở chế độ Test không có mã QR); chưa kiểm Chrome thật; chưa kiểm tab “Đối soát lô” của quản trị ở mobile 375px; preview đã trả về chế độ ví (mặc định) nên các kết nối và cọc trực tiếp đã kiểm ở trên không còn trong DB preview (cần `PREVIEW_PAYMENT_MODE=` và dựng lại để xem).

## Chặng ghi nhận tiền tại cơ sở và báo giá cọc định kỳ (12/11/2026)

Đối chiếu nguồn (đọc trực tiếp hai ZIP, chỉ đọc): `payments/direct-deposit.service.ts` (nhánh `store-receipt`: chỉ ghi `storeReceivedAt`, `storeReceivedById`, `storeReference`, tăng `version`; trả `{success:true}` khi đã ghi), `payments/deposit-core.ts` và `wallet-core.ts` (chặn hủy/từ chối/hết hạn khi `storeReceivedAt` còn: "Resolve the recorded store payment before cancelling"), `src/components/payments/DepositPanel.jsx` (`canRecordStore`, ô "Tham chiếu thu tiền tại cửa hàng"). Nguồn không có sổ giao dịch thanh toán lịch hẹn cạnh `store-receipt`, nên việc hợp nhất với `PaymentService` (sổ, checkout, hoàn/đảo) là thiết kế của bản PHP theo yêu cầu lượt này, không có trong nguồn.

### Nhận định ban đầu và bằng chứng

| Nhận định | Test tái hiện (viết trước khi sửa) | Kết quả trước khi sửa | Kết luận |
|---|---|---|---|
| `store-receipt` chỉ đặt `store_received_at`, checkout vẫn báo còn thu | `StoreReceiptTest::test_recording_the_remainder_at_the_store_settles_checkout_…` | Đỏ: sau khi ghi nhận, `paidAmount` vẫn 54.000₫ và `remainingAmount` 126.000₫, không có giao dịch, không có bút toán | Đúng |
| Hai đường không biết nhau | `…collecting_from_the_payment_screen_first_closes_the_store_receipt_door`, `…partial_collection_then_the_store_receipt…` | Đỏ: store-receipt sau khi đã thu ở màn thanh toán trả 200 không đổi gì; thu một phần rồi store-receipt không đưa checkout về 0 | Đúng |
| Preview cọc định kỳ dùng một giá cho mọi kỳ, thiếu `start_at` | `RecurringDepositQuoteTest` (8 test) | Đỏ 7/8: với quy tắc giá có hiệu lực từ kỳ 2, preview báo cả ba kỳ 180.000₫ trong khi giá thật từ kỳ 2 là 220.000₫; tạo chuỗi bằng quote đó trả 409 thay vì 402/200 | Đúng. Nguyên nhân: `depositPreview` gọi `CartBookingService::quote` không có `start_at` và lấy giá kỳ đầu nhân cho mọi kỳ |
| (Phát hiện thêm, ngoài hai nhận định) hai thao tác ghi tiền đồng thời | `ZZConcurrentWalletTest` kịch bản G | Đỏ: một tiến trình văng `UniqueConstraintViolationException` trên `payment_policy_snapshots.booking_id` | Lỗi có sẵn trong `PaymentService`: xem "Thứ tự khóa" |

Lần chạy đỏ đầu của `StoreReceiptTest`: 14 test, 5 lỗi và 8 thất bại (13 đỏ; một test của tôi vỡ vì fixture, đã sửa trước khi sửa mã). Lần chạy đỏ đầu của `RecurringDepositQuoteTest`: 8 test, 7 đỏ (một test chỉ vỡ vì tên hàm trùng trait, đã đổi tên).

### Đã làm: ghi nhận tiền tại cơ sở (một nghiệp vụ, hai cửa)

- `PaymentService::storeReceipt` là nghiệp vụ duy nhất cho nút "đã thu phần còn lại tại cơ sở" của màn cọc (`DirectDepositService::action('store-receipt')` chỉ kiểm quyền rồi gọi vào đây). Nó tạo **một giao dịch `VERIFIED`** cho phần còn phải thu (`collectable`), phương thức `CASH` (mặc định) hoặc `BANK_TRANSFER` (tham chiếu lưu ở `settlement_reference`), `evidence` ghi `source`, `reference`, `depositId`; cùng bút toán `PAYMENT_RECEIVED`, cùng tích điểm, cùng phí nền tảng, cùng audit (`STORE_RECEIPT` kèm phương thức, số tiền, tham chiếu; giữ `DEPOSIT_STORE_RECEIPT` của màn cọc) như `collect`. Phần dùng chung của hai cửa nằm ở `afterVerified`, `assertCollectable` và `syncStoreReceipt`.
- Dấu "đã thu tại cơ sở" của cọc bám theo tiền: bật khi còn khoản đã xác minh (trừ đảo, trừ hoàn đã `REFUNDED`), tắt khi hết; `version` của cọc tăng mỗi lần dấu đổi. Hoàn tiền xong (`processRefund`) nay cũng đồng bộ dấu (trước đây không), nên dấu không còn chặn hủy lịch khi tiền đã trả lại khách.
- Số tiền đã thu phản ánh ở checkout (`paidAmount`, `remainingAmount`), sổ giao dịch (`payment_transactions`, `payment_ledger_entries`) và trạng thái cọc (`store_received_at/by/reference`). Màn cọc trả thêm `storeReceipt` (số tiền, phương thức, tham chiếu, thời điểm) và `canRecordStore` nay đúng cùng điều kiện với hành động (còn gì để thu, không có chuyển khoản chờ xác minh).
- Gửi lại / hai cửa / hai phiên: cùng yêu cầu (cùng tham chiếu và phương thức) trả lại kết quả cũ kể cả khi `version` đã cũ và không đổi gì; tham chiếu khác cho khoản đã ghi bị 409; thu ở màn thanh toán trước rồi store-receipt bị 409 (không ghi đôi); store-receipt trước rồi thu ở màn thanh toán bị 422 (số tiền vượt phần cần thu). Thu một phần rồi store-receipt chỉ ghi phần còn lại. Có chuyển khoản chờ xác minh thì store-receipt bị 409. `POST payments/collect` gửi lại không kèm `amount` nay so với số tiền của lần gửi đầu (trước đây so với phần còn lại hiện tại nên bị 409 oan).
- Quyền: store-receipt kiểm `ApiScope` (khách 403, người lạ 404) rồi `PaymentService::access('collect')` (chủ doanh nghiệp, hoặc lễ tân đúng chi nhánh) lần nữa trong transaction.
- Dữ liệu cũ (đã có `store_received_at`, chưa có giao dịch): `balance()` coi phần còn lại là đã thu (hiển thị `due = 0`, chặn thu thêm); store-receipt với đúng tham chiếu cũ biến nó thành giao dịch thật một lần (`evidence.source = LEGACY_STORE_RECEIPT`, khóa `store-receipt:legacy:{id}`); lệnh `php artisan beautybook:reconcile-store-receipts` làm hàng loạt, chạy lại không ghi thêm.

### Thứ tự khóa (lỗi thật, có sẵn trong `PaymentService`)

`lockBooking` đọc booking bằng một lần đọc thường trước khi chờ khóa doanh nghiệp và khóa booking. Dưới REPEATABLE READ của MySQL, lần đọc đó chốt ảnh chụp cũ; mọi lần đọc sau khi có khóa (số dư, danh sách giao dịch, chính sách) có thể không thấy khoản vừa được tiến trình khác commit. Hậu quả quan sát được: hai thao tác ghi tiền đồng thời cùng chèn `payment_policy_snapshots` và một bên văng lỗi trùng khóa; hậu quả có thể xảy ra: hai khoản thu cùng thấy cùng phần còn lại. Sửa: mã doanh nghiệp được đọc **trước** khi mở transaction (`businessOf`), trong transaction khóa doanh nghiệp rồi booking bằng đọc khóa và không đọc gì trước đó (`lockBooking($id, $businessId)`), áp dụng cho `collect`, `verify`, `reverse`, `requestRefund`, `reviewRefund`, `processRefund`, `storeReceipt` và lệnh đối soát. Thứ tự khóa chung cho mọi thao tác ghi tiền: doanh nghiệp → booking → phân quyền → dòng cọc → giao dịch/bút toán. Kịch bản G trong `ZZConcurrentWalletTest` chạy song song ba store-receipt (hai phiên: chủ và lễ tân) cùng hai `collect` trên một lịch đã check-in: đúng một giao dịch 70.000₫, đúng một bút toán, không deadlock, bên thua bị từ chối hoặc phát lại.

### Đã làm: báo giá cọc định kỳ theo từng kỳ

- `RecurringService::preview` nay tính **từng kỳ**: giá từ cùng bộ tính giá mà việc tạo booking dùng (giỏ: `CartBookingService::quote` với `start_at` và nhân viên của kỳ đó, tức áp quy tắc giá theo ngày/giờ/nhân viên; combo: `previewPrice` với nhân viên của kỳ; dịch vụ lẻ như trước), rồi điều khoản và quote theo giá đó (`WalletCore::terms` hoặc `DirectDeposit::terms`). Tổng cọc chuỗi (`walletDepositTotalVnd`) là tổng cọc các kỳ thực sự đặt được; kỳ xung đột có `walletDeposit = null`.
- Quote của kỳ N luôn thuộc kỳ N: mảng `walletDepositQuotes` theo chỉ số kỳ, `null` ở kỳ bị bỏ qua; khi tạo, kỳ bị bỏ qua (`skipConflicts`) không dùng quote của nó, các kỳ sau giữ quote riêng. Tạo chuỗi vẫn là một transaction: thiếu số dư toàn chuỗi → 402 không tạo gì; quote cũ hoặc giá đổi giữa preview và tạo → 409 không tạo gì (lỗi 409/402 là `abort`, không bị `skipConflicts` nuốt như xung đột kỳ); gửi lại cùng khóa trả `replayed`.
- React: `seriesTerms` lấy số liệu từ từng kỳ (`rows`, `depositVnd`, `totalVnd`, `remainingVnd` là tổng các kỳ; `perVnd` chỉ có khi mọi kỳ bằng nhau), `WalletDepositNotice` liệt kê "Lần N · ngày giờ (giá …) — cọc" khi các kỳ khác nhau, và giữ dòng "Mỗi lần hẹn" khi bằng nhau.

### Quyết định làm đổi nghiệp vụ (nêu theo yêu cầu)

1. `store-receipt` nay sinh giao dịch và bút toán (trước chỉ đặt dấu). Khác nguồn (nguồn chỉ có dấu).
2. Điều kiện trạng thái của `store-receipt`: `CHECKED_IN`, `IN_PROGRESS`, `COMPLETED` (nguồn: `CHECKED_IN`, `COMPLETED`), cho cùng quy tắc với `collect`.
3. Store-receipt gửi lại cùng nội dung trả `success` thay vì 409 khi `version` cũ; tham chiếu khác cho khoản đã ghi là 409.
4. Màn cọc nhận thêm `method` (`CASH` mặc định, `BANK_TRANSFER`) và `reference` bắt buộc như cũ.
5. Hoàn tiền xong gỡ dấu "đã thu tại cơ sở" khi không còn tiền thu ròng; hợp đồng gốc "đã hoàn thì còn nợ nhưng không thu lại" (`collectable = 0`) giữ nguyên (`FinanceTest` kiểm).
6. Dòng sổ `PAYMENT_RECEIVED` hiển thị `+` (trước hiển thị `−`: dấu CREDIT/DEBIT bị đảo trong `PaymentCorePanels.jsx`).
7. Quote cọc chuỗi là một quote cho mỗi kỳ theo giá của kỳ đó (đã có từ trước theo từng kỳ ở bước tạo; nay preview khớp).

### Migration và hoàn tác

Không có migration mới và không sửa migration. Lệnh `beautybook:reconcile-store-receipts` là bước dữ liệu tùy chọn cho DB cũ (idempotent, không có đường lùi cần thiết: chỉ thêm giao dịch và bút toán, có thể đảo bằng `reverse`). Hoàn tác mã: bỏ `PaymentService::storeReceipt`, trả `DirectDepositService::action` về nhánh cũ.

### File đã đổi

`app/Services/Finance/PaymentService.php`, `app/Http/Controllers/Beautybook/FinanceController.php`, `app/Services/Payments/DirectDepositService.php`, `app/Services/Scheduling/RecurringService.php`, `routes/console.php`; React: `components/payments/DirectDepositPanel.jsx`, `utils/directDeposit.js` (+ test), `utils/walletDeposit.js` (+ test), `components/customer/WalletDepositNotice.jsx`, `pages/Salon/PaymentCorePanels.jsx`. Test mới: `tests/Feature/StoreReceiptTest.php` (15), `tests/Feature/RecurringDepositQuoteTest.php` (8), kịch bản G trong `ZZConcurrentWalletTest.php`; `tests/Support/DirectDepositFixtures.php` là phần helper tách nguyên văn khỏi `DirectDepositTest` (20 test vẫn đạt, 429 assertion như trước khi tách).

### Kết quả

| Hạng mục | Lệnh | Exit | Kết quả |
|---|---|---|---|
| Xác minh cấu hình hiệu lực, config cache, PDO (tiến trình riêng, trước suite) | `php /scratch/check.php` trong container test | 0 | `APP_ENV=testing`, không cache, `mysql_test:3306/beautybook_test`, `DB-CHECK: OK` |
| `DirectDepositTest` sau khi tách trait | `runtests.sh tests/Feature/DirectDepositTest.php` | 0 | 20 test, 429 assertion |
| Tập trung hai lỗi + hồi quy booking, ví, cọc, thanh toán, đặt lịch giỏ/combo/định kỳ, race | `runtests.sh` với 15 file (StoreReceipt, RecurringDepositQuote, BookingDepositLifecycle, DirectDeposit, WalletDeposit, WalletSettlement, WalletPayout, CartBooking, Scheduling, Finance, AdvancedFinance, BookingCreation, BookingLifecycle, ZZConcurrentWallet, ZZConcurrentBookingRace) | 0 | 142 test, 2035 assertion (chạy trước khi thêm test ví của store-receipt) |
| JavaScript | `node --test` toàn bộ `src/**/*.test.js` + realtime + scripts | 0 | 165 đạt, 0 trượt |
| Build mặc định / có cờ tài chính | `npm run build`; `VITE_FINANCE_WORKSPACE=true npm run build:beautybook` | 0, 0 | |
| Toàn bộ PHPUnit trên cây cuối | `runtests.sh` (không đối số) | 0 | 468 test, 5895 assertion, 0 trượt, 3 bỏ qua có chủ đích như các lượt trước (445 cũ + 15 StoreReceipt + 8 RecurringDepositQuote = 468), thời gian 13 phút 55 giây |
| Trình duyệt (Chromium của ứng dụng, desktop, preview cọc trực tiếp, biên nhận **ký cục bộ**) | | | Chủ cơ sở tạo kết nối, xác minh, bật. Khách đặt chuỗi 4 kỳ (quy tắc giá +40.000₫ từ kỳ 2 chèn thẳng vào DB preview): màn xác nhận liệt kê 54.000 / 66.000 / 66.000 / 66.000 = 252.000₫, còn lại 588.000₫; DB lưu đúng 4 cọc đó (54.000, 66.000 ×3) và tổng giá 180.000 + 220.000 ×3. Biên nhận cọc kỳ 1 ký cục bộ, lịch 1 đưa sang `CHECKED_IN` bằng DB preview. Chủ cơ sở ghi nhận qua ngăn chi tiết: form biến mất, hiện "Đã ghi nhận thu 126.000₫ bằng tiền mặt, tham chiếu …"; DB: 1 giao dịch `CASH VERIFIED` 12.600.000 cent, 1 bút toán, `store_reference` đúng, `version` 2, hai dòng audit. Màn thanh toán: giao dịch CASH 126.000₫ "Đã thanh toán"; ghi nhận thu thêm cho lịch đó bị từ chối ("Số tiền vượt số dư cần thu…") |

### Giới hạn kiểm chứng

- Biên nhận là ký cục bộ, không phải SePay thật; không có chuyển khoản thật; chưa kiểm Chrome thật.
- Test ghi chỉ chạy trên MySQL 8.4 test cô lập; chưa kiểm PostgreSQL của nguồn.
- Kịch bản race chạy theo lần chạy (tiến trình song song thật) chứ không liệt kê mọi thứ tự xen kẽ; lỗi khóa được phát hiện ở lần chạy đầu và đã qua lại sau khi sửa (các lần chạy sau: tập trung và toàn bộ suite).
- Trình duyệt: chưa kiểm mobile 375px cho ô chọn phương thức mới của form ghi nhận và danh sách cọc từng kỳ; hàng quy tắc giá của preview chèn bằng DB (không có giao diện chủ cơ sở cho quy tắc giá trong bản này); lịch check-in của luồng store-receipt đặt bằng DB preview.
- Combo: giá combo cố định nên cọc mọi kỳ bằng nhau; trường hợp quy tắc giá theo nhân viên đổi trọng số phân bổ của combo không làm đổi tổng.
- Chưa có kiểm tra riêng cho store-receipt trên cọc ví bị `APPLIED` (lịch đã hoàn tất): điều kiện trạng thái chung đã gồm `COMPLETED` và test ví kiểm đường `HELD`.
- Cọc `AWAITING_PAYMENT` chưa bị chặn thu tiền ở màn thanh toán khi lịch còn `PENDING` (hành vi gốc, ngoài phạm vi lượt này).
- Cây làm việc vẫn chưa commit; stack demo `beautybook-php` không bị chạm.

## Chặng đóng ba lỗi tiền và các khoảng thiếu (12/11/2026, lượt hai)

Mọi nhận định lỗi dưới đây được dựng lại bằng test trước khi sửa; cột "Phân loại" tách: **thiếu so với ZIP**, **khác biệt chuyển đổi Laravel/MySQL đã chấp nhận**, **bổ sung ngoài nguồn**.

### A. Thu tiền khi cọc trực tiếp còn `AWAITING_PAYMENT`

| Nhận định | Test | Trước khi sửa | Kết luận |
|---|---|---|---|
| `assertCollectable` chỉ nhìn `depositCents > 0`, nên cọc đang chờ (`depositCents = 0`) không chặn; `verify` không kiểm cọc; webhook sau đó áp cọc lên giá đã thu | `DepositPaymentGateTest` (8 test) | Đỏ 4 + 1 lỗi: `collect` toàn bộ/một phần/chuyển khoản/lễ tân đều trả 200 khi cọc còn chờ; chuyển khoản treo vẫn xác minh được; webhook 54.000₫ sau khoản thu 100.000₫ đã áp cọc `PAID` (tổng thu vượt giá) | Đúng |

Sửa: `PaymentService::assertDepositNotAwaiting` đọc dòng cọc bằng đọc khóa (`FOR UPDATE`) trong transaction rồi từ chối (409) `collect` và `verify` khi cọc còn `AWAITING_PAYMENT` (cọc đã `PAID/HELD/APPLIED` vẫn theo quy tắc "khách đã đến"; booking không có cọc thu như cũ; cọc hết hạn/hủy: lịch không còn thu được theo trạng thái lịch). Phía biên nhận, `DirectDepositService::apply` khóa doanh nghiệp trước mọi lần đọc rồi, nếu lịch đã có tiền ghi nhận (giao dịch đã xác minh trừ đảo, hoặc đang chờ xác minh) thì **không áp cọc**: biên nhận thành `REFUND_REQUIRED` với lý do mới `STORE_PAYMENT_RECORDED`, cọc vẫn `AWAITING_PAYMENT`, **không xóa hay đổi giao dịch nào**, có audit và hiện ở danh sách biên nhận của chủ cơ sở và quản trị. Dữ liệu đã có sẵn không nhất quán (tiền ghi nhận khi cọc chờ/hết hạn/hủy) đối soát bằng lệnh chỉ đọc `php artisan beautybook:payment-inconsistencies`. Đồng thời: kịch bản race H trong `ZZConcurrentWalletTest` (hai biên nhận cùng lúc với ba `collect` từ hai phiên: mọi `collect` bị 409, cọc `PAID` một lần, không giao dịch; và hai biên nhận cùng lúc sau khoản thu cũ: một khoản hoàn `STORE_PAYMENT_RECORDED`, giao dịch cũ nguyên vẹn, không deadlock).

### B. Thu một phần làm mất nút thu phần còn lại

| Nhận định | Test | Trước khi sửa | Kết luận |
|---|---|---|---|
| `read()` yêu cầu `! store_received_at`, mà dấu này bật ngay sau lần thu một phần đầu | `StoreReceiptTest::test_a_partial_collection_keeps_the_record_action_offered…` | Đỏ: sau khi thu 30.000₫, `canRecordStore` = false dù còn 96.000₫ thu được và backend vẫn chấp nhận | Đúng |

Sửa: một câu hỏi duy nhất `PaymentService::storeBlocker` cho cả nút (`canRecordStore`) và hành động (`storeReceipt`), nên màn hình và backend không thể lệch; `DirectDepositService::read` trả thêm khối `payment` (chỉ cho chủ/lễ tân): `totalVnd`, `depositVnd`, `storeCollectedVnd`, `pendingVnd`, `dueVnd`, `collectableVnd`, và `initialRemainingVnd` (phần còn lại **theo điều khoản cọc lúc đặt**, tách khỏi số dư hiện tại). Giao diện (`DirectDepositPanel`) hiển thị "Đã thu / Đang chờ xác minh / Còn có thể thu", dòng phần còn lại ban đầu so với số còn phải trả, và giữ form ghi nhận khi vẫn hợp lệ. Cũng gỡ câu "Phần còn lại đã thu tại cơ sở" (sai khi mới thu một phần). Test đọc API: nút đúng ở từng trạng thái (chưa nhận cọc, chưa đến, đã đến, có chuyển khoản chờ).

### C. Tóm tắt nhiều khoản thu sai phương thức / tham chiếu

Trước: `storeSummary` lấy **phương thức của giao dịch cuối** và **tham chiếu đầu tiên của dấu cọc** cho tổng tiền của nhiều giao dịch. Test `…cash_for_a_part_then_a_transfer_for_the_rest…`, `…reversing_and_refunding…`, `…legacy…` (đỏ vì chưa có `payment`).

Sửa: `PaymentService::paymentView` trả `breakdown` theo phương thức (cộng đúng các khoản còn hiệu lực, trừ hoàn) và `entries`: mỗi giao dịch với phương thức, tham chiếu **riêng**, người ghi (id, tên), thời điểm, trạng thái, số đã hoàn, cờ `reversed` kèm người đảo/thời điểm đảo, cờ `legacy`. Khoản đã đảo vẫn nằm trong danh sách (đánh dấu), không còn trong tổng; tham chiếu cũ không gắn sang giao dịch mới. Kiểm: tiền mặt một phần rồi chuyển khoản phần còn lại (qua giao diện: 30.000₫ tiền mặt + 96.000₫ chuyển khoản `FT26111200999`), hoàn một phần tiền mặt (10.000₫), đảo khoản chuyển khoản, ghi lại khoản mới với tham chiếu mới.

### D. Khoảng thiếu đóng thêm

| Hạng mục | Phân loại | Test đỏ (trước khi sửa) | Việc làm |
|---|---|---|---|
| Push: vai trò CUSTOMER còn hiệu lực lúc xếp hàng và lúc gửi | thiếu so với ZIP (`push.worker.ts` kiểm `userRole` CUSTOMER chưa hết hạn) | `PushSessionBindingTest` (+2): đỏ: role hết hạn vẫn được gửi | `PushService::customerAccess` kiểm ở `queue()` và `deliver()` |
| Push: thời hạn phiên theo driver | chuyển đổi Laravel (nguồn có bảng session riêng) | cùng file: đỏ: thiết bị lâu không đăng ký vẫn được xếp hàng với driver không phải database | Driver `database`: kiểm đúng dòng phiên (như trước). Driver khác: thiết bị chỉ tin cậy trong **một thời hạn phiên** tính từ lần đăng ký cuối (app đăng ký lại mỗi lần mở), không vô hạn |
| Firebase Web Push: đăng ký, nhận, gỡ, service worker, lỗi | thiếu so với ZIP phần trình duyệt thật (bản trước chỉ có kênh thử) | `PushNotificationTest` (+1) và `browserPush.test.js` (+5), đỏ | Server: `push.web` (`FCM_WEB_*`) và `push-config` trả `mode: fcm` + cấu hình web công khai chỉ khi đủ bốn biến. Client: `mode: fcm` xin quyền, đăng ký service worker, lấy token FCM bằng SDK `firebase/messaging` (nạp lười, bản dựng mặc định không tải), đăng ký **chính token đó**; gỡ thiết bị xóa cả token FCM; lỗi Firebase đổi thành thông báo tiếng Việt; không còn polling ở chế độ FCM (service worker nhận `push`). **Chưa nghiệm thu được với project Firebase thật** (không có tài khoản): chỉ có test với fake và build |
| Ảnh đánh giá / kết quả / chứng từ: kích thước và hash khi đọc | thiếu so với ZIP (`media-storage.ts get()`) | `PrivatePhotoIntegrityTest` (6): đỏ: đối tượng bị thay cùng kích thước vẫn được phục vụ | Migration `add_sha256_to_media_files`; tải lên ghi SHA-256; đọc bằng `PrivateMediaDisk::readRecorded` (đọc tối đa size+1 byte, kích thước **và** hash phải khớp, lệch = 404). Dòng cũ không có hash vẫn đọc được theo kích thước; quyền hiện hành vẫn kiểm trước và sau khi đọc |
| Chính sách chi nhánh: `rescheduleHours`, `gracePeriodMinutes` (`noShowGraceMinutes` ở nguồn), `earlyCheckInMinutes` | thiếu so với ZIP | `BookingLifecycleTest` (+5), `BookingChangeRequestTest` (+2), `StoreReceiptTest` (+1): đỏ | `BranchBookingRules` đọc 3 giá trị (mặc định không đổi hành vi: check-in từ 30 phút, vắng mặt sau 15 phút, không cutoff đổi lịch); check-in và vắng mặt theo chi nhánh; khách không gửi được yêu cầu đổi lịch sau mốc, và duyệt đổi lịch sau mốc bị từ chối (như nguồn); `BookingPresenter` trả `policy` (mốc ISO) và cổng thời gian của màn chi tiết nay **khớp** backend (trước đây nút check-in chỉ mở từ giờ hẹn dù backend cho từ 30 phút trước); `RescheduleModal` hiển thị mốc và khóa nút. Lỗi thật: ràng buộc DB `chk_booking_violation_source_time` cứng 15 phút cho NO_SHOW, nên grace ngắn hơn bị lỗi 500: nới trong migration `widen_late_cancellation_window` |
| Quản trị tài chính: danh sách hoàn cọc, đối soát, khoản chuyển chưa xác định | **bổ sung ngoài nguồn** (nguồn chỉ có đối soát biên nhận ví) | `DirectDepositAdminTest` (5) | `GET payments/admin/deposits/refunds|receipts` (phân trang, lọc trạng thái/lý do, chỉ `PLATFORM_ADMIN`), `POST …/receipts/{id}/review` (ghi chú vào audit, dấu `reviewed_at` lần đầu); tab "Hoàn cọc & chuyển khoản"; tài khoản hoàn hiển thị che trừ 4 số cuối |
| Cấu hình | — | — | `.env.example` (cọc trực tiếp, khóa mã hóa, Firebase web, SMTP, cờ build), `VAN-HANH.md` |

Khác biệt chấp nhận giữ nguyên: ví mô phỏng/LIVE_LAB, rút tiền `MOCK`, đối soát lô mô phỏng (đúng nguồn); khóa tuần tự bằng khóa hàng MySQL thay cho Serializable + NOWAIT của PostgreSQL; thông báo lỗi API ví bằng tiếng Anh như nguồn.

### Thứ tự khóa và lỗi có sẵn trong PaymentService (đã ghi ở lượt trước, kiểm lại ở đây)

Kết hợp với cổng cọc: mọi thao tác ghi tiền khóa doanh nghiệp → booking → phân quyền → dòng cọc → giao dịch, **không đọc gì trước các khóa** (REPEATABLE READ); webhook cọc trực tiếp nay cũng khóa doanh nghiệp trước.

### Kết quả (cây làm việc cuối)

| Hạng mục | Lệnh | Exit | Kết quả |
|---|---|---|---|
| Xác minh cấu hình hiệu lực, config cache, PDO (tiến trình riêng, trước suite) | `php /scratch/check.php` trong container test | 0 | `APP_ENV=testing`, không cache, `mysql_test:3306/beautybook_test`, `DB-CHECK: OK` |
| Test đỏ trước khi sửa | `runtests.sh <file>` | 1–2 | A: 4 thất bại + 1 lỗi / 8; B: lỗi thiếu khối `payment` và 1 thất bại đúng nguyên nhân; push: 2 / 11; chính sách chi nhánh: 5 thất bại + 1 lỗi; ảnh: 4 lỗi + 2 thất bại / 6; Firebase: 4 / 13 (JS), 1 (PHP) |
| Tập trung và hồi quy theo từng phần (cọc, thanh toán, push, chính sách, ảnh, quản trị) | `runtests.sh` với các file tương ứng | 0 | xanh sau khi sửa (số liệu từng lượt trong lịch sử làm việc) |
| Race (tiến trình PHP song song thật) | `ZZConcurrentWalletTest` (kịch bản G, H) | 0 | 1 test, 66 assertion; G: ghi nhận tại cơ sở hai cửa/hai phiên; H: biên nhận đồng thời với `collect`, biên nhận sau khoản thu cũ |
| JavaScript | `node --test` toàn bộ `src/**/*.test.js` + realtime + scripts | 0 | 173 đạt, 0 trượt, 0 bỏ qua |
| Build mặc định / có cờ tài chính | `npm run build`; `VITE_FINANCE_WORKSPACE=true npm run build:beautybook` | 0, 0 | bản mặc định không tải Firebase |
| **Toàn bộ PHPUnit trên cây cuối** | `runtests.sh` (không đối số), 20 phút 6 giây | 0 | **502 test, 6381 assertion, 0 trượt, 3 bỏ qua** (ba test kế thừa trong bộ R2 kiểm đĩa cục bộ nên cố ý bỏ qua; bộ R2 chạy với MinIO thử, không phải R2) |
| Trình duyệt (Chromium nhúng), preview cọc trực tiếp, biên nhận **ký cục bộ** | — | — | Desktop: thu 30.000₫ ở màn thanh toán cho lịch đã đến (được ghi nhận) và cho lịch chờ cọc (bị chặn bằng thông báo tiếng Việt); ngăn chi tiết: "Đã thu 30.000₫ / chờ xác minh 0 / còn thu 96.000₫", phần còn lại theo điều khoản 126.000₫ so với số còn phải trả 96.000₫, form vẫn mở; ghi nhận phần còn lại bằng chuyển khoản `FT26111200999`: hai khoản riêng (tiền mặt 30.000₫, chuyển khoản 96.000₫) mỗi khoản đúng tham chiếu/người ghi/giờ; tab quản trị "Hoàn cọc & chuyển khoản" hiện khoản hoàn 30.000₫ (lệch số tiền) và hai biên nhận (không khớp, cần hoàn). Mobile 375×812: ngăn chi tiết và tab quản trị không tràn ngang |


### Giới hạn và phụ thuộc ngoài

- Chưa nghiệm thu với dịch vụ thật: Google (token ký cục bộ), SMTP (log), **Firebase** (test với fake; cần project, service account và bốn biến `FCM_WEB_*`), R2 (MinIO), SePay (biên nhận ký cục bộ), rút tiền/đối soát mô phỏng. Các bước nghiệm thu còn phụ thuộc tài khoản nằm ở `VAN-HANH.md`, mục 4.
- Giao diện kiểm bằng Chromium nhúng (không phải Chrome thật). Desktop: màn thanh toán (thu từng phần, bị chặn khi cọc chờ), ngăn chi tiết (thu một phần, chuyển khoản, nhiều khoản), tab quản trị mới. Mobile 375px: ngăn chi tiết (khối thu tại cơ sở) và tab quản trị. Dựng bằng fixture/DB (không qua giao diện): kết nối nhận cọc, lịch đã đến/đang chờ cọc, biên nhận ký cục bộ, quy tắc giá của lượt trước. Chưa kiểm bằng giao diện: hộp thoại đổi lịch với cutoff (chỉ test PHP/JS và build), thông báo đẩy Firebase, chat ảnh/khôi phục tài khoản trong lượt này (kiểm ở các chặng trước).
- Đánh giá sức chứa: kịch bản race chạy bằng tiến trình thật nhưng không liệt kê mọi thứ tự xen kẽ.
