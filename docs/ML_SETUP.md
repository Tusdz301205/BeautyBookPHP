# Phân tích đánh giá PhoBERT

Laravel quản lý review, phân quyền, hàng đợi và kết quả MySQL. Dịch vụ Python trong `ml/` chỉ chạy inference. Frontend React tại `/beautybook/salon/reviews` dùng giao diện và contract ABSA của GlowMe, với bảng màu hồng hiện tại.

## Phần được chuyển

- Giữ checkpoint PhoBERT Beauty v2 seed 42, tokenizer local, VnCoreNLP, tiền xử lý `prep-v1`, CPU fp32 và taxonomy `beauty-4-v1`.
- SHA-256 checkpoint: `6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc`.
- Bốn khía cạnh theo thứ tự: `SERVICE_QUALITY`, `STAFF_ATTITUDE`, `WAIT_TIME`, `PRICE`; nhãn `NOT_MENTIONED`, `POS`, `NEG`, `NEU`.
- Review lưu/commit trước khi enqueue. Review chỉ có sao không vào hàng đợi. Worker riêng dùng `SKIP LOCKED`, lease, retry/backoff và token cho mỗi claim để chặn worker cũ ghi đè, kể cả sau khi retry thủ công.
- Chỉ Owner hiện tại của đúng doanh nghiệp được đọc. API thống kê không gọi inference. Kết quả chỉ lấy đúng model/checkpoint đang dùng; giữ kết quả model khác riêng biệt.
- Có tổng quan, biểu đồ khía cạnh, nhãn từng review, khoảng ngày Việt Nam, lọc/phân trang, trạng thái thiếu dữ liệu/đang xử lý/lỗi. Giữ danh sách review và phản hồi/báo cáo hiện có.

Đường dẫn chính:

```text
app/Services/Reviews/             contract, HTTP client, store, worker, read service
app/Console/Commands/Absa*.php    chạy worker, kiểm tra, backfill
config/absa.php                   cấu hình từ môi trường
database/migrations/*absa*       bảng MySQL bổ sung
ml/glowme_ml/                    runtime, model và license gốc
scripts/ml/                      cài và khởi động Python
resources/beautybook/src/components/reviews/
```

Không sao chép dataset huấn luyện, kết quả thí nghiệm hoặc virtualenv GlowMe. Trọng số lớn dùng Git LFS; `ml/.venv` và runtime Java là tệp local bị Git bỏ qua.

## Cài và chạy

Cần Node 22+, Python 3.13, JDK có cả `java`/`javac`, Git LFS và Docker của project. Khi clone repository bằng Git, hydrate checkpoint bằng Git LFS trước khi cài.

```sh
git lfs pull --include="ml/glowme_ml/release/phobert-beauty-v2/best_model.pt"
npm run ai:install
```

Installer kiểm tra hash, dependency Python và biên dịch segmenter. Trên Windows, script tìm JDK tại `JAVA_HOME` hoặc `Program Files/Java`; có thể đặt `JAVA_HOME` cho JDK ở nơi khác. Không thay PATH toàn máy. Service chạy một lần inference kiểm tra trước khi mở health endpoint.

Thêm cấu hình riêng trong `.env` (token ngẫu nhiên ít nhất 24 ký tự, không commit):

```dotenv
ABSA_SERVICE_URL=http://host.docker.internal:8766
ABSA_SERVICE_HOST=127.0.0.1
ABSA_SERVICE_PORT=8766
ABSA_SERVICE_TOKEN=<token-rieng>
ABSA_WORKER_ENABLED=true
ABSA_BATCH_SIZE=4
ABSA_REQUEST_TIMEOUT_MS=60000
ABSA_LEASE_MS=180000
```

Port 8766 tách dịch vụ GlowMe cổng 8765. Chỉ Python chạy trên host; Laravel/worker chạy Docker. Cấu hình này đã được kiểm tra trên Windows với Docker Desktop. Khi PHP chạy trực tiếp trên host, đổi URL thành `http://127.0.0.1:8766`. Trên Linux dùng Docker Engine trực tiếp, service Python chỉ bind loopback nên container bridge không truy cập được; chỉ ánh xạ `host.docker.internal` tới host gateway không giải quyết được việc này. Chưa có quy trình AI cho Linux được kiểm chứng trong repository. Có thể chạy phần web độc lập với AI; cần thiết lập phương án kết nối riêng trước khi bật worker trên Linux. Không mở service Python ra Internet.

Chạy service Python trong nền, tự khởi động lại khi process Python lỗi:

```sh
npm run ai:start
npm run ai:status
```

`npm start` khởi động cả Docker và AI. `npm run ai:foreground` chạy Python trong terminal khi cần chẩn đoán; `npm run ai:stop` dừng đúng supervisor của dự án. Log vòng đời nằm tại `storage/app/private/absa-runtime/supervisor.log`. Sau khi khởi động lại Windows, chạy `npm start` để mở lại dự án.

Ngày 13/11/2026 đã backfill đủ 381/381 nhận xét lịch sử có nội dung bằng mô hình thật, không có job lỗi. 10 đánh giá chỉ chấm sao không đưa vào inference. Da Mộc · Tân Định có 42 nhận xét đã phân tích trong tổng 43 đánh giá; đã kiểm tra hiển thị trên tài khoản owner thật.

Áp dụng duy nhất migration bổ sung, rồi chạy app và worker khi được phép cập nhật DB đang dùng:

```sh
docker compose -f docker-compose.laravel.yml exec -T app php artisan migrate --path=database/migrations/2026_11_13_000000_create_review_absa_analyses_table.php --force
docker compose -f docker-compose.laravel.yml --profile ml up -d --no-deps app absa-worker
docker compose -f docker-compose.laravel.yml exec -T app php artisan absa:check
```

Không chạy reset/seed. `absa:check` không ghi DB; worker configured enabled không thay cho bằng chứng worker đang chạy. Xem log service `absa-worker` và trạng thái job. Để thử một batch trong môi trường QA: `php artisan absa:work --once`.

## Đánh giá cũ và retry

Không tự backfill review lịch sử khi mở màn hình hoặc migrate. Xem số lượng trước:

```sh
docker compose -f docker-compose.laravel.yml exec -T app php artisan absa:backfill
docker compose -f docker-compose.laravel.yml exec -T app php artisan absa:backfill --branch=1
```

Sau khi xác nhận số lượng N, dùng `--execute --expect=N`. Để thử lại job đã hết lượt hoặc lỗi terminal, thêm `--retry-failed` cho cả dry run và execute. Không ghi đè kết quả hoàn tất. Bình luận dài hơn giới hạn model 4.000 ký tự được đánh lỗi riêng, không làm lỗi các review khác trong batch.

## Ngày giờ và giới hạn mô hình

Ngày lọc là ngày Việt Nam, ngày kết thúc bao gồm trọn ngày. Timestamp review nhập từ GlowMe được nhận diện qua `glowme_import_ids` và đọc theo UTC; review PHP mới đọc theo múi giờ ứng dụng. Không chỉnh lại timestamp dữ liệu cũ. Timestamp hàng đợi/kết quả mới lưu UTC.

Nhãn là dự đoán AI, xác suất chưa hiệu chỉnh. Model card nguồn ghi đánh giá trên dữ liệu tổng hợp, chưa xác nhận hiệu quả trên dữ liệu người dùng thật. Giữ ghi chú này trên giao diện và model card.
