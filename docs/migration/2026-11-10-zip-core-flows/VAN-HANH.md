# Vận hành: cài mới, nâng cấp, dịch vụ ngoài, quay lui

Tài liệu cho nhánh `feature/zip-core-flows-2026-11-09`. Nội dung nghiệp vụ và bằng chứng kiểm thử nằm ở `IMPLEMENTATION.md` và `BANG-DOI-CHIEU.md`. Không có secret thật trong repo: mọi giá trị dưới đây là tên biến hoặc ví dụ rỗng.

## 1. Cài mới

```bash
docker compose -f docker-compose.laravel.yml up -d --wait mysql mysql_test
docker compose -f docker-compose.laravel.yml build app
npm ci
npm run build
docker compose -f docker-compose.laravel.yml run --rm --no-deps app php artisan migrate --seed
docker compose -f docker-compose.laravel.yml up -d --wait app realtime web scheduler
```

- `npm run build` là bản mặc định: giao diện tài chính (thanh toán, ví, cọc, rút tiền, đối soát) **tắt**. Để bàn giao hoặc demo phần tài chính, build bằng `VITE_FINANCE_WORKSPACE=true npm run build:beautybook` (cùng giá trị cho mọi lần build ra `public/beautybook-build`).
- Sao `.env.example` thành `.env`, điền `APP_KEY` (`php artisan key:generate`) và các biến ở mục 4. Tài khoản seed cục bộ: xem README (mật khẩu fixture chỉ dành cho máy local/testing, seeder không chạy ở production).
- `SESSION_DRIVER=database` là bắt buộc khi bật push (thiết bị gắn với phiên đăng nhập, chỉ driver này kiểm tra được phiên còn sống).

## 2. Nâng cấp một cơ sở dữ liệu đã có

Thứ tự migration của nhánh (chạy `php artisan migrate`, không cần cờ đặc biệt):

| Migration | Việc làm | Quay lui (`down`) |
|---|---|---|
| `2026_11_10_000000_create_sepay_receipts` | biên nhận SePay, kết nối nhận cọc | xóa bảng |
| `2026_11_10_005000_create_wallet_core` | ví mô phỏng, cọc, hoàn cọc | xóa bảng |
| `2026_11_10_010000_create_business_payout_tables` | khoản phải trả, rút tiền, đợt đối soát lô | xóa bảng |
| `2026_11_10_020000_create_chat_and_push_tables` | chat, thiết bị push, hàng đợi gửi | xóa bảng |
| `2026_11_10_030000_create_external_identities_and_reset_codes` | đăng nhập Google, mã khôi phục | xóa bảng |
| `2026_11_11_000000_widen_late_cancellation_window` | ràng buộc bằng chứng vi phạm: cửa sổ hủy muộn 0–168 giờ, vắng mặt sau thời gian ân hạn của chi nhánh (0–1440 phút) | khôi phục 4 giờ / 15 phút, **từ chối chạy** nếu đã có bản ghi ngoài cửa sổ cũ |
| `2026_11_12_000000_add_sha256_to_media_files` | cột `sha256` cho tệp riêng tư (nullable) | bỏ cột |

- Các migration trên chưa được merge vào nhánh chính; một số đã được sửa tại chỗ trong quá trình làm việc. **Cơ sở dữ liệu thử đã chạy phiên bản cũ của chúng** (test, preview) phải dựng lại bằng `php artisan migrate:fresh --seed` (chỉ trên DB thử), cơ sở dữ liệu chưa từng chạy chúng chỉ cần `migrate`.
- Ảnh đánh giá/kết quả/chứng từ tải lên **trước** migration cuối không có hash: vẫn đọc được, chỉ kiểm theo kích thước; tệp mới được kiểm cả kích thước và SHA-256.
- Dữ liệu cũ về tiền thu tại cơ sở (cọc có `store_received_at` nhưng chưa có giao dịch): `php artisan beautybook:reconcile-store-receipts` (chạy lại không ghi thêm) biến chúng thành giao dịch thật.
- Kiểm tra tiền đã ghi trong khi cọc còn chờ, hết hạn hoặc bị hủy (chỉ đọc, không đổi gì): `php artisan beautybook:payment-inconsistencies`. Mỗi dòng là một lịch cần chủ cơ sở xử lý (hoàn cho khách hoặc đảo giao dịch); hệ thống không tự xóa giao dịch.

## 3. Tiến trình nền

- Container `scheduler` chạy `php artisan schedule:work`; mỗi phút gọi `beautybook:maintain` (giữ chỗ hết hạn, hộp thư, đợt push, nhắc lịch, biên nhận SePay lỗi, rút tiền dở dang, hạn nạp ví thử). Không có worker hàng đợi riêng: push và nhắc lịch chạy trong lệnh này.
- Gửi lại push thất bại: `php artisan beautybook:push-retry`.
- Realtime (chat, thông báo) chạy ở container `realtime`; nginx (`web`) phục vụ cùng một origin.

## 4. Cấu hình dịch vụ ngoài (mọi giá trị là secret hoặc định danh của bạn, giữ ngoài Git)

| Dịch vụ | Biến | Ghi chú nghiệm thu |
|---|---|---|
| Google đăng nhập | `GOOGLE_CLIENT_ID` | Tạo OAuth client (Web) trên Google Cloud, thêm origin của site vào "Authorized JavaScript origins". Nghiệm thu thật cần tài khoản Google; test trong repo ký token cục bộ (mô phỏng). |
| SMTP | `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Gửi thử mã khôi phục/xác minh email đến hộp thư thật. Mặc định `MAIL_MAILER=log` chỉ ghi log. |
| Firebase (push) | `PUSH_ENABLED=true`, `FCM_PROJECT_ID`, `FCM_SERVICE_ACCOUNT_FILE` (đường dẫn trên máy chủ), và cho trình duyệt `FCM_WEB_API_KEY`, `FCM_WEB_APP_ID`, `FCM_WEB_SENDER_ID`, `FCM_WEB_VAPID_KEY` | Tệp service account nằm ngoài repo. Bốn biến web là định danh công khai của project; thiếu một biến thì trình duyệt không được cấp kênh push thật. Site phải chạy HTTPS (hoặc localhost). Nghiệm thu thật cần project Firebase; `PUSH_TRIAL_SINK` chỉ là bản thay thế để thử. |
| R2 (media riêng tư) | `MEDIA_PRIVATE_DISK=r2`, `R2_ENDPOINT`, `R2_BUCKET_NAME`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_KEY_PREFIX` | Bucket **riêng tư**. Test dùng MinIO (không phải R2). Nghiệm thu thật cần bucket R2. |
| SePay – cọc ví thử (Live lab) | `BEAUTYBOOK_PAYMENT_MODE=INTERNAL_WALLET`, `WALLET_LIVE_LAB_*`, `SEPAY_WEBHOOK_*` | Ví chỉ dành cho danh sách người dùng/doanh nghiệp thử; bị từ chối ở production. Đây là **mô phỏng** theo nguồn. |
| SePay – cọc trực tiếp | `BEAUTYBOOK_PAYMENT_MODE=` (rỗng), `DIRECT_DEPOSIT_ENABLED=true`, `SEPAY_CONNECTION_ENCRYPTION_KEY` (64 ký tự hex) | Chủ cơ sở tạo kết nối trong tab "Nhận cọc trực tiếp", dán URL webhook và secret hiển thị một lần vào SePay, chuyển khoản mã xác minh `GS…` để xác minh. Không đổi khóa mã hóa khi đã có kết nối. Nghiệm thu thật cần tài khoản SePay (Test/Live). |

Rút tiền và đối soát lô là **mô phỏng** đúng theo nguồn (nhà cung cấp `MOCK`, không chuyển tiền thật). Hoàn cọc trực tiếp là khai báo tài khoản – chủ cơ sở báo đã chuyển – khách xác nhận, không có chuyển khoản tự động.

## 5. Kiểm tra sau khi cài

```bash
docker compose -f docker-compose.laravel.yml exec -T app php artisan beautybook:maintain
docker compose -f docker-compose.laravel.yml exec -T app php artisan beautybook:payment-inconsistencies
curl -s http://localhost:8000/ready
```

Test PHP chỉ được chạy trên MySQL test cô lập `mysql_test:3306/beautybook_test` (bộ test tự từ chối cấu hình khác và giữ khóa độc quyền). Không chạy test ghi dữ liệu trên DB demo.

## 6. Quay lui

1. Tắt tính năng trước: `DIRECT_DEPOSIT_ENABLED=false` (cọc trực tiếp mới không được tạo; các cọc đang có vẫn xử lý được), `BEAUTYBOOK_PAYMENT_MODE=` (ví thử), `PUSH_ENABLED=false`, và build lại không có `VITE_FINANCE_WORKSPACE`.
2. Quay mã: `git checkout <commit trước nhánh>`; dữ liệu mới (giao dịch, cọc, hoàn cọc) vẫn nằm trong DB.
3. Quay migration (chỉ khi chắc chắn, mất dữ liệu của các bảng đó): `php artisan migrate:rollback --step=7` đảo đúng bảy migration ở mục 2 theo thứ tự ngược. Migration `widen_late_cancellation_window` từ chối đảo nếu đã có bản ghi vi phạm ngoài cửa sổ cũ: xử lý hoặc xóa các bản ghi đó trước, có chủ đích.
4. Không có bước dữ liệu nào tự xóa giao dịch; mọi khoản tiền ghi nhận sai được đảo bằng thao tác "đảo giao dịch" của màn thanh toán (có dấu vết người thao tác và thời điểm).

## 7. Đưa cọc trực tiếp lên tiền thật (SePay Live)

Cọc trực tiếp chuyển thẳng vào tài khoản ngân hàng của từng doanh nghiệp; nền tảng không giữ tiền. Mỗi doanh nghiệp tự nối tài khoản của mình với SePay.

**Chữ ký webhook đã đúng chuẩn SePay** (đối chiếu tài liệu [developer.sepay.vn](https://developer.sepay.vn/en/sepay-webhooks/xac-thuc)): header `X-SePay-Signature: sha256=<hex>` và `X-SePay-Timestamp`, ký chuỗi `{timestamp}.{raw body}` bằng HMAC-SHA256 với Secret Key; lệch quá 5 phút bị từ chối; phản hồi `200 {"success": true}`; trùng `id` chỉ xử lý một lần.

Việc phải làm trên máy chủ production (một lần):

1. Bật cấu hình: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<tên miền thật>` (SePay chỉ gửi webhook đến địa chỉ https hợp lệ, không chấp nhận chứng chỉ tự ký), `DIRECT_DEPOSIT_ENABLED=true`, `SEPAY_CONNECTION_ENCRYPTION_KEY` (64 hex, giữ ngoài Git, không đổi khi đã có kết nối), `BEAUTYBOOK_PAYMENT_MODE` để trống (ví thử tắt).
2. Sau reverse proxy: đặt `TRUSTED_PROXIES` để lấy đúng IP khách và https.
3. (Khuyến nghị) giới hạn nguồn: `SEPAY_ALLOWED_IPS` = danh sách địa chỉ SePay công bố tại [dia-chi-ip](https://developer.sepay.vn/en/dia-chi-ip) (bảy IPv4 và hai IPv6 tại thời điểm viết; SePay nói danh sách có thể đổi, phải cho phép **tất cả** và kiểm tra định kỳ).
4. Chạy `php artisan beautybook:payments-check`: mọi dòng `FAIL` phải hết (lệnh trả mã lỗi khác 0 khi còn `FAIL`; không in secret).

Việc của từng doanh nghiệp (trong tab "Nhận cọc trực tiếp", chọn môi trường **Live**):

1. Tạo kết nối: lưu secret (chỉ hiện một lần) và sao chép **URL webhook** đầy đủ (do máy chủ tạo từ `APP_URL`).
2. Trong SePay: thêm webhook (đúng tài khoản ngân hàng đã khai), URL đó, xác thực **HMAC-SHA256** với secret vừa lưu, kiểu dữ liệu **JSON**, sự kiện **tiền vào**.
3. Chuyển một khoản nhỏ vào tài khoản với nội dung chứa mã xác minh `GS…`; khi webhook hợp lệ về, kết nối thành "Đã xác minh". Sau đó mới bật "Đang nhận cọc".
4. Thử một lịch thật với khoản cọc nhỏ trước khi mở cho khách: đặt lịch, chuyển khoản đúng số tiền và nội dung `GM…` (mã nằm lẫn trong lời của ngân hàng vẫn được nhận ra), lịch chuyển sang "Đã nhận cọc".

SePay có **Test mode** riêng (giao dịch mô phỏng, không qua ngân hàng): kết nối môi trường Test chỉ dùng ngoài production (hoặc khi đặt rõ `DIRECT_DEPOSIT_ALLOW_TEST=true`).

Hoàn cọc là thủ công theo thiết kế (khách khai báo tài khoản, chủ cơ sở chuyển và báo mã, khách xác nhận); hệ thống không chuyển tiền thay chủ cơ sở.

**Chưa nghiệm thu bằng tiền thật**: cần tài khoản SePay và tài khoản ngân hàng thật của bạn. Phần mã, chữ ký, QR VietQR (`vietqr.app/img`), allowlist IP, đọc mã cọc và kiểm tra go-live đã có test; bước chuyển một khoản thật và xem webhook về là bước nghiệm thu cuối do bạn thực hiện.
