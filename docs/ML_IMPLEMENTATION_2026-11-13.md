# Chuyển ML GlowMe sang BeautyBookPHP — 13/11/2026

## Kết quả

Đã chuyển runtime PhoBERT Beauty v2, model/tokenizer/VnCoreNLP và giao diện phân tích review Owner từ checkout GlowMe hiện tại. Backend NestJS/PostgreSQL được viết tương đương bằng Laravel/MySQL, giữ contract model và API. Giao diện Owner dùng bảng so sánh bốn khía cạnh trong hệ màu xanh hiện có theo quyền tự thiết kế của người dùng.

PHP lưu review trước, enqueue sau commit; worker độc lập lưu kết quả theo review + model + checkpoint. Thống kê/lọc/phân trang chạy SQL; kết quả model khác không trộn. Thêm kiểm tra output chặt, retry/backoff, lease/claim token, giới hạn text từng item và đọc timestamp đúng cho cả dữ liệu nhập UTC lẫn review PHP mới.

Model và code inference giữ nguyên so với nguồn. Chỉ thêm warmup lúc khởi động HTTP service để health không báo sẵn sàng trước khi JDK/segmentation/model hoạt động. Script cài kiểm tra JDK và biên dịch segmenter.

## Kiểm chứng

- So sánh predictor gốc GlowMe với HTTP service bản sao PHP trên 3 bình luận: 12/12 nhãn khớp, maximum probability difference = 0. Checkpoint SHA-256 khớp `6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc`.
- Kết quả mới nhất theo từng file: 7 test worker/contract và 9 test API đọc đều đạt. Bao gồm idempotency, star-only, outage/retry, terminal error, stale claim/manual retry, batch có bình luận quá dài, model sai, config sai, backfill dry run, quyền Owner/cross-tenant, vocab/date validation, thiếu bảng AI, native/imported timestamps và phân trang thời gian hỗn hợp.
- Hồi quy review/ảnh review: 12 test bổ sung đạt; 5 test kế thừa nhánh R2 được runner đánh dấu skip theo cấu hình/fixture hiện có. Không tính skip thành pass. Log `absa-regression-tests.log` còn giữ kết quả trước các sửa cuối; log `absa-final-tests.log` và `absa-date-final-tests.log` là kết quả thay thế của các file ML sau sửa. Đây không phải một full-suite run toàn bộ project.
- 176 test frontend đạt. Build frontend đạt sau thay đổi giao diện và xóa CSS dư.
- E2E trên database test được kiểm soát: đăng nhập UI Customer → HTTP tạo review cho lịch hoàn tất → after-commit enqueue → worker PHP gọi model thật → Owner đăng nhập UI → đọc summary/list/biểu đồ → lọc cảm nhận → danh sách phản hồi cũ. Ngày Việt Nam, timestamp và hiển thị 1440/768/375 được kiểm tra; không lỗi JS hoặc API bất thường.
- Quy trình `npm run ai:install` chạy lại thành công; dependency check và JDK/segmenter compilation đạt. Service riêng cổng loopback 8766 warmup và health thành công.

Log và ảnh QA nằm trong các thư mục private/log bị Git bỏ qua. QA dùng `mysql_test/beautybook_test`, không seed/reset database đang dùng.

## Dọn tệp

Đã xóa `resources/beautybook/src/styles/public-marketplace.css` và `workspace-layout.css` vì không còn import hoặc consumer trong entrypoint/layout hiện tại (32.337 byte). Giữ Blade compatibility, migration lịch sử, test, tài liệu và service dùng chung vì vẫn có consumer hoặc yêu cầu lưu vết. Thư viện `skills/` được giữ local nhưng loại khỏi Git khi chuẩn bị xuất bản ngày 16/11/2026; không phải dependency ứng dụng. Chỉ mang runtime ML triển khai; không mang dataset, kết quả training hoặc virtualenv từ GlowMe. Dependency/cache/runtime Python được bỏ qua bởi Git; model dùng Git LFS. Docker build context loại `ml/` và `skills/`, không đưa hàng GB tài nguyên không dùng vào build PHP.

## Kích hoạt database ứng dụng

Sau xác nhận rõ ràng của người dùng ngày 13/11/2026, đã áp dụng duy nhất migration bổ sung bảng `review_absa_analyses` và bật service `absa-worker` trên database PHP hiện tại. App được khởi động lại để nhận cấu hình; nginx đã kiểm tra cấu hình và reload để nhận địa chỉ upstream mới.

Kiểm tra sau kích hoạt: store installed, worker configured enabled, tiến trình `php artisan absa:work` thực sự đang chạy với restartCount 0, Python service ready và model/checkpoint khớp. `/beautybook` và `/ready` trả HTTP 200. Đối chiếu trước/sau: users 217, businesses 13, branches 16, bookings 2.731, reviews 391; số lượng giữ nguyên. Bảng ML có 0 bản ghi ban đầu vì chưa có review mới; không chèn review thử vào DB ứng dụng và không chạy backfill lịch sử.

Hướng dẫn chạy: [ML_SETUP](ML_SETUP.md). Review lịch sử chỉ được backfill bằng lệnh dry run rồi `--execute --expect=N`; không tự ghi đè kết quả cũ.

## Skill

Đã đọc `prompts.chat` prompt-lookup và dùng mẫu Software Quality Assurance Tester trong catalog local làm cơ sở kiểm tra chức năng, lỗi, bằng chứng và báo cáo. MCP prompts.chat chưa kết nối, không có lời gọi tra cứu từ xa. Hallmark áp dụng cho khu vực so sánh khía cạnh theo design system hiện có; không đổi nhãn/model để phục vụ giao diện.
