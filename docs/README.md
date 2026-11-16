# Tài liệu đồ án BeautyBookPHP

Tài liệu hiện hành cho môn Lập trình mã nguồn mở, dùng bản Laravel tại thư mục gốc.

| Tài liệu | Dùng để |
| --- | --- |
| [Phạm vi đồ án](course/COURSE_SCOPE.md) | Giới thiệu đề tài, công nghệ, vai trò và chức năng |
| [ERD, use case và kịch bản demo](course/ERD_USE_CASE.md) | Trình bày dữ liệu và minh họa luồng nghiệp vụ |
| [Đối chiếu tiêu chí chấm](course/RUBRIC_TRACEABILITY.md) | Đối chiếu các tỷ trọng 40/25/15/10/10 với mã và kiểm thử |
| [Trạng thái và giới hạn](course/IMPLEMENTATION_STATUS.md) | Nêu những gì đã triển khai và giới hạn thực tế |
| [Đối chiếu giao diện với nguồn](course/UI_SOURCE_PARITY.md) | Những phần đã chỉnh theo BeautyBook và các khác biệt còn lại |
| [Prompt đã sử dụng](course/PORT_IMPLEMENTATION_PROMPT.md) | Ghi lại cách áp dụng prompts.chat vào đồ án |
| [Nghiệm thu](course/evidence/2026-10-07/FINAL_VALIDATION.md) | Kết quả kiểm thử, build và trình duyệt |
| [Hướng dẫn chạy](../README.md) | Cài đặt Docker, tài khoản demo và lệnh kiểm thử |
| [Tài khoản database demo local](DEMO_ACCOUNTS.md) | Phân biệt tài khoản dữ liệu đã nhập với tài khoản seed khi cài mới |
| [Luồng đặt lịch](BOOKING_FLOW.md) | Các bước đặt lịch, công thức giá và điều kiện xác nhận |
| [Ví và thanh toán](PAYMENT_SETUP.md) | Cọc từ ví, cấu hình SePay và giới hạn chi trả |
| [Phân tích đánh giá bằng AI](ML_SETUP.md) | Git LFS, cài Python/JDK, cấu hình và chạy worker |
| [Kiểm thử trình duyệt](../tests/Browser/README.md) | Fixture, môi trường QA và các kịch bản Playwright |
| [Kết quả kiểm thử 14/11/2026](FULL_TEST_RESULTS_2026-11-14.md) | Kết quả nghiệm thu và giới hạn tại thời điểm ghi nhận |
| [Đợt chuyển tính năng đăng ký 03/11/2026](migration/2026-11-03-registration/IMPLEMENTATION.md) | Pháp nhân, đăng ký chi nhánh, yêu cầu đổi địa chỉ có duyệt và kiểm thử |
| [Kiểm thử và đối chiếu GlowMe 12/11/2026](audit/REPORT_2026-11-12.md) | Kết quả test và khác biệt chức năng |
| [Dọn cấu trúc thư mục](audit/CLEANUP_2026-11-12.md) | Các mục đã chuyển, xóa và lý do giữ legacy |
| [Phương án nhập toàn bộ dữ liệu GlowMe](migration/2026-11-12-glowme-data-import/PLAN.md) | Phạm vi, ánh xạ, tài khoản, media và đối soát |
| [Kết quả nhập trực tiếp GlowMe](migration/2026-11-12-glowme-data-import/IMPLEMENTATION.md) | Dữ liệu đã thay trên DB PHP và kết quả đối soát |
| [Ưu đãi bằng mã](migration/2026-11-12-promotion-codes/IMPLEMENTATION.md) | Luồng áp dụng mã, khôi phục 10 chương trình và kiểm tra |

Thư mục `course/evidence/2026-10-07` chứa ảnh giao diện, kết quả kiểm thử và bằng chứng booking thật phục vụ báo cáo. Các tài liệu audit/planning của bản NestJS/React, hồ sơ thực tập/CV và báo cáo cũ đã được loại bỏ.
