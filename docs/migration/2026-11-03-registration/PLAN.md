# Chuyển business/branch registration vào Laravel

Đầu vào: hai ZIP `feature-business-branch-registration` trên Desktop. Baseline `40e1d86`, working tree sạch. Phạm vi phiên triển khai: thông tin pháp lý doanh nghiệp, loại đăng ký chi nhánh, yêu cầu thay đổi địa chỉ/đăng ký chi nhánh có duyệt. Giữ ID số, MySQL, các role (gồm STAFF), catalog và React hiện tại. Không triển khai chat, Google, push hay tài chính.

## Thiết kế

1. Migration bổ sung các cột pháp lý nullable để dữ liệu/API legacy tiếp tục dùng được. Hồ sơ khai báo loại pháp nhân mới phải đủ thông tin và PDF private trước submit. Không tự suy loại pháp nhân cho dữ liệu cũ.
2. Chi nhánh thêm loại đăng ký. Chi nhánh đã duyệt không được đổi địa chỉ trực tiếp qua PATCH/onboarding; dùng workflow mới. Thông tin liên hệ/chính sách vẫn sửa bình thường.
3. Bảng yêu cầu: snapshot trước/sau, evidence PDF private đúng branch, actor, reviewer, trạng thái, expectedVersion, thời điểm xét duyệt chuẩn hóa UTC; unique một pending/branch. Khóa branch rồi business theo thứ tự booking hiện tại. Kiểm lại quyền và điều kiện trong transaction.
4. Owner tạo/rút yêu cầu; admin xem/approve/reject. Pending không đổi dữ liệu công khai. Approve kiểm drift, booking đang hiệu lực và evidence; lưu snapshot địa chỉ cho lịch cũ chưa có trước khi đổi. Snapshot legacy được gắn provenance, không tuyên bố là địa chỉ lịch sử đã kiểm chứng.
5. React thêm trường pháp lý vào form hiện có; panel owner/admin cho yêu cầu thay đổi; có loading/error/empty, nhãn và trạng thái kết quả. Không thay frontend cả thư mục.

## Contract mới

- GET/POST `branches/{id}/change-requests`: owner; POST gồm addressLine, districtId (mã danh mục hiện tại), ward, registrationType, mediaId, reason.
- POST `branches/{id}/change-requests/{requestId}/withdraw`: owner, expectedVersion.
- GET `admin/branch-change-requests`: admin, phân trang/status.
- POST `admin/branch-change-requests/{id}/review`: admin, expectedVersion, decision APPROVE/REJECT, note.
- Conflict: 409; invalid input: 422; ngoài scope: 403/404. Không tự mở rộng quyền hoặc giới hạn HCM của source.

## Kiểm thử/gate

Guard trước RefreshDatabase/migrate:fresh và lease riêng giữ cả lượt chạy. Xác minh cấu hình DB hiệu lực, không reset demo. Test cho tenant/role, PDF scope, duplicate pending, stale version, withdraw/reject/approve, public snapshot, active booking, direct-update bypass, pháp lý mới/legacy. Build frontend và regression suite liên quan nếu runtime có thể chạy. Chưa có kết quả runtime được coi là đạt cho tới khi ghi rõ bằng chứng.

Lưu ý tương thích sau rà soát: form legacy không tự chọn loại pháp nhân/đăng ký khi tải lại hoặc lưu; hạn giữ chỗ tiếp tục theo múi giờ ứng dụng hiện có, còn review/capture mới là UTC. Khi lịch được chuyển chi nhánh qua workflow hiện có, snapshot địa điểm phải theo chi nhánh đích và giữ bản trước chuyển trong hồ sơ xử lý, không làm đổi hash nghiệp vụ của hồ sơ ảnh hưởng cũ.

## Rollback

Rollback code trước; giữ cột/bảng và lịch sử khi đã có dữ liệu. Không chạy down migration trên dữ liệu dùng chung để hoàn tác review. Nếu đã approve địa chỉ, tạo yêu cầu đổi lại với review, không xóa snapshot/audit. Không migrate/seed database demo trong lượt kiểm thử.
