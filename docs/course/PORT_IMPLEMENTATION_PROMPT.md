# Prompt triển khai BeautyBookPHP từ prompts.chat

Ngày tiếp nhận và áp dụng: 07/10/2026.

## Catalog đã dùng

Đã đọc skill `prompt-lookup` của prompts.chat và tìm trong `prompts.csv` cục bộ người dùng bổ sung. Session không có MCP `search_prompts/get_prompt/improve_prompt`, nên dùng catalog CSV thay thế. Mẫu tham khảo: **Fullstack Software Developer**, **Senior Frontend Developer**, **Frontend Developer Skill**, **Code Review Agent**. Ví dụ framework/demo trong mẫu không phải yêu cầu người dùng; không dùng `PHP Interpreter` để giả kết quả chạy.

Skill/catalog là ghi nhận nguồn prompt, không phải dependency chạy đồ án. Cây BeautyBook tại `../beauty-booking-api-main` giữ chỉ đọc; không sửa source, build, seed, tạo cache hoặc migration vào database nguồn.

## Prompt phát triển đã điều chỉnh

Đóng vai lập trình viên fullstack triển khai BeautyBookPHP cho môn Lập trình mã nguồn mở. Giữ Laravel/PHP, Eloquent/MySQL, migration, middleware và Blade entry tại root. Tái sử dụng màn hình, component, CSS, state/tương tác đang hoạt động của nguồn; sao chép React vào `resources/beautybook`, chỉ đổi điểm tích hợp cần thiết. Không thay trải nghiệm BeautyBook bằng checklist CRUD.

Inventory từ route/API thực sự được gọi. Adapter Laravel trả DTO camelCase, ID chuỗi, lỗi phù hợp, dùng session/CSRF và assignment hiện hành trên server. Header role/doanh nghiệp/chi nhánh không cấp quyền. Tái sử dụng service transaction cho booking, tài chính, ưu đãi, combo/recurring, loyalty, governance/ownership; không proxy NestJS, mock response hoặc báo thành công chưa lưu dữ liệu.

Giữ invariant tiền/quota/version, khóa/idempotency/snapshot, quyền tenant và lịch sử. Hoàn thiện luồng công khai, owner registration, onboarding/readiness, staff/service workspaces, integrated booking/contact-consent/recovery, scheduler move/resize/actual-time/history, realtime, notification/upload, ngày đóng/ngày đặc biệt/policy/overbooking, rule giá theo điều kiện. AUTO/buffer theo nguồn. Validation booking nguồn không kiểm tra ca làm riêng; không tự thêm điều kiện đó chỉ vì tồn tại model ca làm.

Import tham chiếu giữ dữ liệu hiện có, ghi provenance, phân biệt template với database gốc. Không bịa jitter giá, booking/rating/thanh toán hoặc danh tính nhân viên chưa cung cấp. Ảnh minh họa giữ nhãn minh họa. Không đưa lại role retire, HR/health, cổng thanh toán online hoặc phí hủy đã loại bỏ.

## Prompt review và nghiệm thu

Đóng vai reviewer đối chiếu source đang hoạt động với bản port. Ưu tiên cross-tenant/workspace, dữ liệu khách, tiền/quota, transaction/replay, snapshot/history/background jobs. Báo file, điều kiện tái hiện, tác động; không giảm assertion hoặc đổi cấu hình để làm test xanh. Phần độc lập có ownership rõ ràng; không ghi đè thay đổi người khác.

Kiểm tra riêng sự giống file/asset, sự hiện diện API và hành vi tích hợp. Chạy test FE, Laravel/MySQL tách biệt và browser theo guest/customer/owner/receptionist/staff/admin ở 320/375/414/768/1440px. Chỉ ghi kết quả đã chạy; giữ pending khi full suite/browser còn chạy. Số test pass/file giống/route có mặt không quy đổi thành parity 100%. Review không sửa nguồn hoặc phát hành công khai chưa được yêu cầu.

## Tiêu chí tối thiểu từ ảnh người dùng

| Nhóm | Trọng số | Chứng cứ cần có |
| --- | --- | --- |
| Chức năng chính | 40% | CRUD, auth, validation, pagination, search với dữ liệu thật |
| Laravel | 25% | Eloquent ORM, migration, Blade, middleware |
| Thiết kế/trải nghiệm | 15% | Giao diện đẹp, responsive, thân thiện |
| Báo cáo/thuyết trình | 10% | ERD, use case, mô tả chức năng, ảnh chụp màn hình |
| Sáng tạo/mở rộng | 10% | AJAX, API, chức năng nâng cao |

Trọng số dùng chấm môn học, không phải phần trăm chuyển đổi/mức độ giống BeautyBook. Trạng thái thực tế ở [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md).

