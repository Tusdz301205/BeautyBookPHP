# Kết quả nhập GlowMe trực tiếp — 12/11/2026

Đã thay dữ liệu cũ trong **MySQL `beautybook_php` đang dùng**. Không tạo database tạm, không thay đổi dữ liệu nguồn GlowMe. PostgreSQL nguồn được mở lại để đọc; export trong transaction REPEATABLE READ READ ONLY.

## Kết quả

Nguồn hiện tại: `glowme_complete_demo_20261102`, chụp ngày 12/11/2026. Bao gồm **73 bảng / 37.992 dòng**, cả schema `glowme_ml`. Đây là snapshot hiện tại, không phải chỉ dùng backup SQL 05/11.

Mọi dòng nguồn được lưu nguyên JSON gốc trong `glowme_import_rows` tại chính DB PHP; có SHA-256 từng dòng và `glowme_import_ids` giữ ánh xạ ID nguồn. Các module chiếu dữ liệu vào 66 bảng nghiệp vụ PHP. Không xem số dòng chiếu là số dòng nguồn vì schema PHP tách/gộp một số đối tượng.

| Dữ liệu nghiệp vụ | Số lượng nhập |
| --- | ---: |
| Tài khoản | 215 |
| Hồ sơ khách / chủ | 185 / 13 |
| Doanh nghiệp / chi nhánh | 12 / 16 |
| Dịch vụ / biến thể / combo | 139 / 40 / 34 |
| Nhân viên | 60 |
| Booking / dòng dịch vụ | 2.731 / 3.082 |
| Lịch sử trạng thái booking | 10.328 |
| Review | 391 |
| Ví / lượt nạp / bút toán ví | 187 / 546 / 452 |
| Cọc / hoàn tiền / yêu cầu chi trả | 44 / 12 / 40 |
| Chat / tin nhắn / đính kèm | 24 / 77 / 3 |
| File thật | 423 |

379 file lấy từ R2 nguồn; 44 PDF còn thiếu trong R2 được tìm đúng hash/size trong `GlowMe/temp/GlowMe-DB-20261103/media`. Tất cả được sao chép vào private storage của PHP. Đường dẫn opaque được giữ; không tạo ảnh/PDF giả. Quyền công khai chỉ cấp cho media có liên kết nguồn đủ điều kiện; tài liệu, avatar và media phụ thuộc consent dùng luồng riêng tư tương ứng.

Mật khẩu scrypt được nhập nguyên hash, có provider tương thích bằng libsodium, tự rehash theo cấu hình Laravel sau đăng nhập bằng mật khẩu thành công. Không đặt lại mật khẩu hoặc gán mật khẩu demo chung. Chưa có mật khẩu thật của người dùng để xác nhận đăng nhập các tài khoản nguồn qua browser; hai mật khẩu fixture cũ không khớp nguồn. Kiểm tra thuật toán và luồng rehash đã đạt bằng vector Node.js và test đăng nhập Laravel.

GlowMe có 183 phường/xã và không có dòng quận/huyện. API khu vực của PHP đọc dữ liệu nguồn đã lưu; trả `level=WARD` qua hợp đồng picker hiện có. Metadata 16 chi nhánh giữ mã/tên tỉnh, phường nguồn và liên kết picker tương ứng. Canonical catalog PHP được tạo từ 27 danh mục nguồn, không dùng lại danh mục demo.

## Đối soát và kiểm tra

- 73/73 bảng lưu trữ khớp số dòng và SHA-256 từng payload gốc.
- Không có quan hệ khóa ngoại mồ côi trong toàn bộ database đích.
- Kiểm tra từng dòng: tài khoản/hash, mã/trạng thái/tổng tiền booking; số dư và tiền giữ ví; cọc, hoàn, khoản phải trả, yêu cầu chi trả, bút toán ví; nội dung/thứ tự tin nhắn. Không sai lệch ở các trường đã kiểm tra.
- 423/423 file khớp SHA-256 và kích thước; không thiếu file.
- Review cuối phát hiện lookup ID tài liệu chi nhánh cần cộng offset vì hai bảng nguồn gộp vào một bảng đích. Đã sửa bộ nhập và đối chiếu đủ 15 mapping chi nhánh với tài liệu native; dữ liệu và liên kết version vốn đã đúng.
- 16 test authentication/workspace đạt, 4 test memoization đạt, 3 test public API đạt, 1 test địa chỉ ward-only đạt: **24 test / 218 assertions**. Lượt public API đầu bị HTTP 503 do maintenance flag dùng chung; đã chạy lại sau mở site và cả 3 đạt, không sửa/giảm test.
- Chrome kiểm tra trang chủ, explore, chi tiết chi nhánh và dịch vụ: 4 trang HTTP 200, không lỗi JavaScript/API hoặc ảnh hỏng.
- `/ready` HTTP 200. Scheduler và realtime đã được mở lại. Báo cáo đối soát phản ánh thời điểm nhập; scheduler có thể tiếp tục xử lý trạng thái đến hạn theo nghiệp vụ bình thường.

## Phần chỉ lưu trữ hoặc khác cách vận hành

- Schema ML/ABSA, địa chỉ/dữ liệu trường nguồn không có hợp đồng tương đương, lịch sử migration và các metadata không chiếu đầy đủ vẫn được giữ nguyên trong archive. Có dữ liệu không đồng nghĩa PHP đã có tính năng AI/ABSA.
- 55 session nguồn, reset token/code, thiết bị push và hàng đợi gửi không được kích hoạt lại. Người dùng đăng nhập mới bằng tài khoản nguồn. Không phát lại email/push/webhook từ lịch sử.
- Đã bổ sung mã ưu đãi và nâng cấp dữ liệu 10 promotion có mã: giữ đúng nguồn 8 bật / 2 tắt, không sửa thời hạn. Tại thời điểm kiểm tra có 7 mã trong thời hạn hiện tại. Xem [kết quả sửa mã ưu đãi](../2026-11-12-promotion-codes/IMPLEMENTATION.md).
- Khoản rút tiền giữ nguyên snapshot và số tiền; 40 tài khoản ngân hàng lịch sử được dựng từ snapshot thật ở trạng thái DISABLED, không tự bật để chuyển tiền. Nguồn có 0 payment connection và 0 webhook event trong lượt chụp này.
- UI tài chính vẫn theo cờ tính năng hiện có. Nhập database không tự bật những tính năng PHP còn thiếu/đang khóa.

## Mã triển khai và bằng chứng

- `scripts/export-glowme-database.mjs`, `copy-glowme-media.mjs`: chỉ đọc nguồn, kiểm tra byte.
- `scripts/import-glowme.php --replace`: chỉ cho phép môi trường local, MySQL host `mysql`, database `beautybook_php`; xóa dữ liệu cũ và nhập trong transaction, không tắt ràng buộc khi chèn dữ liệu mới.
- `app/Services/GlowMeImport/{CoreImport,BookingImport,FinanceImport,ImportContext}.php`: ánh xạ nghiệp vụ và lưu trữ nguồn.
- `scripts/verify-glowme-import.php`: đối soát dữ liệu thực; `sync-glowme-locations.php`: đồng bộ metadata địa chỉ nguồn.
- `storage/app/private/glowme-import/`: snapshot, manifest, import report, verification, media report, ảnh/browser results. Chứa dữ liệu người dùng, được Git ignore và không phục vụ công khai.
- `storage/logs/glowme-*.log`: log thực hiện local. Không đưa credential, hash tài khoản thật hoặc payload dữ liệu vào tài liệu.
