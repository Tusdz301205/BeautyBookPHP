# Phương án chuyển toàn bộ dữ liệu GlowMe sang BeautyBookPHP

Ngày khảo sát: 12/11/2026. **Đã thực hiện thay dữ liệu trực tiếp trên `beautybook_php` theo yêu cầu người dùng; không tạo database tạm.** Xem [kết quả thực hiện](IMPLEMENTATION.md). Các bước dưới đây đã được cập nhật theo quyết định này.

## Những gì đã xác nhận

- Nguồn: `../GlowMe/api/prisma/schema.prisma`, PostgreSQL, 71 model Prisma. Ngoài Prisma còn có schema `glowme_ml`; không được coi 71 model là toàn bộ database.
- Đích: Laravel/MySQL, schema riêng trong `database/migrations`. Không thể restore trực tiếp SQL PostgreSQL vào database PHP.
- Có backup local ngày 05/11/2026 tại `../GlowMe/temp/GlowMe-DB-Transfer-2026-11-05T14-26-34-826Z/`: custom dump, SQL, checksum, verification và manifest media. Verification ghi 2.731 booking. Đây là số trong bản backup cũ, chưa kiểm đếm database hiện tại.
- Manifest chỉ chứa metadata; ảnh/PDF/file đính kèm thực nằm ở R2 hoặc storage nguồn.
- GlowMe dùng UUID; nhiều bảng PHP dùng số nguyên. Bảng `beautybook_source_entities` đã có cơ chế ánh xạ UUID nhưng cần kiểm tra giới hạn entity_type và mở rộng ánh xạ nếu cần.
- Password GlowMe dùng scrypt; luồng xác thực Laravel hiện chưa có bộ kiểm tra tương thích. Không thể chuyển hash scrypt thành bcrypt mà không biết mật khẩu gốc.

## Phạm vi “tất cả”

| Nhóm | Phải chuyển và đối soát |
| --- | --- |
| Danh tính | User, hồ sơ khách/chủ, vai trò, phạm vi, trạng thái, xác minh, liên kết Google |
| Cơ sở | Doanh nghiệp, pháp nhân, chi nhánh, địa chỉ, hồ sơ/yêu cầu thay đổi, tài liệu |
| Catalog | Danh mục, dịch vụ, biến thể, combo, thành phần, giá, hình ảnh |
| Nhân viên | Hồ sơ, năng lực, giờ làm, nghỉ phép, phân công |
| Đặt lịch | Booking, dòng dịch vụ, contact/consent, snapshot giá, lịch sử, đổi/hủy, recurring, vi phạm |
| Khách hàng | Review/ảnh, kết quả/consent, yêu thích, ưu đãi và lượt sử dụng |
| Tài chính | Cọc, ví, top-up, ledger, hoàn tiền, chi trả, settlement, webhook/inbox và các snapshot |
| Tương tác | Chat, thành viên, tin nhắn, tệp đính kèm, thông báo, lịch sử gửi |
| Quản trị | Audit, thiết lập, request, toàn bộ bảng phụ và schema ML/ABSA |
| Media | Byte file thật, metadata, hash, quyền riêng tư, liên kết đối tượng |

Mỗi bảng/cột nguồn phải có kết quả: ánh xạ vào nghiệp vụ PHP hoặc lưu nguyên trạng trong kho lưu trữ nhập liệu. Phải báo rõ phần chỉ lưu trữ, chưa sử dụng được trên ứng dụng. Chuyển dữ liệu ABSA không tự bổ sung tính năng AI còn thiếu.

## Quy trình triển khai đề xuất

1. Chốt nguồn: bản chụp mới, nhất quán của database hiện tại hoặc backup 05/11. Chỉ đọc nguồn; lưu manifest/checksum và danh sách đầy đủ schema, bảng, cột, số dòng. Không dùng seeder để thay thế dữ liệu thật.
2. Dùng database PHP hiện tại theo yêu cầu người dùng. Tạm dừng website/tác vụ nền, xóa dữ liệu cũ và nhập dữ liệu mới trong cùng transaction; lỗi ràng buộc sẽ rollback cả lượt thay dữ liệu. Không tạo database import thử.
3. Lập ánh xạ từng trường và trạng thái; giữ UUID nguồn, quan hệ khóa ngoại, múi giờ, số tiền chính xác, snapshot và lịch sử. Các chức năng khác nhau phải có quy tắc chuyển rõ ràng.
4. Viết importer chạy lại được, có checkpoint và transaction theo lô. Nhập dữ liệu gốc trước dữ liệu phụ thuộc; không gọi luồng nghiệp vụ có thể tạo lại thông báo, thu tiền hoặc tính lại số dư.
5. Xử lý mật khẩu: thêm bộ xác thực scrypt tương thích và rehash sau đăng nhập, hoặc yêu cầu đặt lại mật khẩu. Không đặt chung một mật khẩu demo cho mọi tài khoản.
6. Sao chép file thật sang vùng storage đích, xác minh hash và quyền truy cập. Secret mã hóa cần được giải mã/mã hóa lại bằng khóa phù hợp hoặc cấu hình lại kết nối; không chép khóa vào báo cáo.
7. Session, OTP/token cũ, push device và hàng đợi đang chờ: giữ lịch sử trong kho nhập nhưng không kích hoạt lại ở PHP. Dừng scheduler/realtime trong khi thay dữ liệu; chỉ mở lại sau đối soát.
8. Đối soát số dòng theo bảng/nhóm, khóa ngoại, số tiền từng ví/ledger/cọc/hoàn/chi trả, lịch sử booking và file thiếu. Chạy test cùng kiểm tra UI trên dữ liệu nhập. Không ép ràng buộc bằng cách bỏ qua dòng lỗi.
9. Mở lại ứng dụng trên chính database đã thay dữ liệu sau đối soát. Không chuyển cấu hình sang database mới.

## Điều kiện hoàn tất

Không mất dòng nguồn; dữ liệu được chuyển hoặc lưu trữ có truy vết đầy đủ. Không có chênh lệch tiền, quan hệ mồ côi hoặc file thiếu không được báo cáo. Tài khoản đăng nhập được theo phương án đã chọn; quyền truy cập và lịch sử đúng. Báo cáo tách rõ dữ liệu sử dụng được và dữ liệu chỉ lưu trữ.
