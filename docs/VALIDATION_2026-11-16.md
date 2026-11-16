# Kiểm tra trước khi xuất bản GitHub — 16/11/2026

## Kết quả

| Kiểm tra | Kết quả |
| --- | --- |
| `npm run build` | Đạt cả pipeline Laravel và React |
| Frontend, realtime và chính sách liên kết thông báo | 193 test đạt, 0 lỗi |
| `php scripts/lint-port.php` trong container | 435 tệp PHP đạt |
| Docker Compose | Cấu hình hợp lệ |
| Liên kết local trong README và tài liệu tài khoản | Các đích liên kết tồn tại |
| `git diff --check` | Không có lỗi khoảng trắng |

Bộ PHP đầy đủ chạy trên `mysql_test/beautybook_test`: **563 đạt, 2 lỗi, 14 bỏ qua**, 6.952 assertions, khoảng 740 giây. Hai lỗi do fixture/kỳ vọng cũ và đã được cập nhật:

- `PromotionTest`: kiểm tra nhãn hiện tại “Khuyến mãi và phiếu ưu đãi”.
- `DepositPaymentGateTest`: tình huống không có cọc phải tắt chế độ cọc trực tiếp. Khi chế độ cọc đang bật, thiếu tài khoản nhận cọc vẫn phải chặn đặt lịch trực tuyến có phí; các test của `DirectDepositRecipeTest` giữ kiểm tra này.

Sau cập nhật, chạy lại toàn bộ các nhóm `DepositPaymentGateTest`, `DirectDepositProductionTest`, `DirectDepositTest`, `DirectDepositRecipeTest` và `PromotionTest`: **56 test đạt, 774 assertions**, khoảng 84 giây. Không chạy lại toàn bộ bộ PHP sau hai thay đổi chỉ nằm trong test; kết quả 56 test là hồi quy có chọn lọc, không phải một lần chạy đầy đủ mới.

## Dọn repository

- Bỏ theo dõi inventory sinh tự động `resources/beautybook/api-call-inventory.json`; giữ bản local và cho phép công cụ kiểm chứng tạo lại.
- Bỏ qua thư viện tham chiếu `/skills/` cùng ảnh biến thể không có consumer. Giữ các asset mà công cụ đối chiếu nguồn vẫn đọc.
- Giữ mã nguồn, lockfile, migration, test, font/ảnh có consumer, giấy phép và bằng chứng kiểm thử được tài liệu tham chiếu.
- `.env`, dependency, build output, cache, log, virtualenv, database dump và media người dùng không nằm trong danh sách Git theo dõi.
- Checkpoint PhoBERT 537.745.752 byte dùng Git LFS; SHA-256 `6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc`.

README phân biệt cài mới bằng seeder với database local đã nhập dữ liệu, và nêu giới hạn cấu hình AI trên Linux Docker Engine. Các bước dọn Git giữ nguyên bản local và không sửa dữ liệu ứng dụng.
