# Chuyển giờ làm và lịch nghỉ nhân viên

Baseline Laravel là commit `5166ef2`. Đã mở trực tiếp hai ZIP từ Desktop ở chế độ chỉ đọc.

| Nguồn | Entry đã đối chiếu |
| --- | --- |
| ZIP API | `docs/beautybook-mvp48/API_SCOPE.md` (P02-T020/T021), `src/staff/staff.controller.ts`, `staff-hours.service.ts`, `staff-time-off.service.ts`, hai DTO lịch, `staff.service.ts`, `src/common/mutation-authority.ts`, `prisma/schema.prisma`, migration `202610160002_identity_catalog_guards`, các test `staff-hours.test.cjs`, `staff-time-off.test.cjs`, `staff-availability.test.cjs` và `web-staff-availability.test.cjs` |
| ZIP Web | `src/pages/Salon/StaffDetail.jsx`, `src/components/staff/StaffHoursPanel.jsx`, `StaffTimeOffPanel.jsx`, `StaffWarnings.jsx`, `src/api/apiClient.js`, `src/hooks/useStaffResource.js`, `useOwnerResource.js`, `tests/staff-panel-ui.test.cjs` |

Chủ cơ sở mở chi tiết nhân viên, chọn Giờ làm hoặc Lịch nghỉ, rồi gửi lệnh qua API `staff/{id}/working-hours` hoặc `staff/{id}/time-off`. Chủ cơ sở đúng doanh nghiệp được ghi. Lễ tân có quyền tại chi nhánh của nhân viên được đọc qua `/manage`. Các GET công khai dùng tiêu chí nhân viên công khai và chi nhánh sẵn sàng của Laravel. Phản hồi công khai của lịch nghỉ chỉ có ID và hai mốc thời gian. Cảnh báo khi ghi gồm số lượt dịch vụ bị ảnh hưởng và tối đa 100 dòng, với ID booking và thời gian.

Laravel đã có `ApiScope`, `ManagementStaffController`, `StaffProfile`, giờ chi nhánh và `AvailabilityService`. Màn `SalonStaffManagement` đã dẫn đến `StaffDetail`. Bổ sung lịch theo `staff_profile_id` số và dùng phạm vi công khai hiện có, vì điều kiện chỉ nhận booking ở HCM trong ZIP thuộc nghiệp vụ của ứng dụng nguồn.

## Chặng 1

`StaffCalendarController` và `StaffCalendarService` hỗ trợ thay cả lịch tuần, đọc theo quyền, tạo, sửa, xóa khoảng nghỉ. Endpoint được khai báo trong `routes/beautybook-management.php` và `routes/beautybook-public.php`. Lịch gồm đúng 7 ngày, tối đa 24 ca mỗi ngày, gộp ca liền nhau và chấp nhận kết thúc lúc 24:00. Khoảng nghỉ dùng ISO có offset, lưu UTC tới mili giây và kiểm tra giao nhau theo khoảng nửa mở. Lý do nghỉ chỉ được trả qua API quản lý. Xóa lặp lại an toàn và ràng buộc ID lịch nghỉ theo nhân viên.

Migration `2026_11_06_000000_create_staff_calendar_tables.php` tạo hai bảng `staff_working_hours` và `staff_time_offs`. CHECK MySQL kiểm hình dạng bản ghi. Thao tác ghi tuần và kiểm tra chồng lấn lịch nghỉ chạy trong transaction với thứ tự khóa chi nhánh, doanh nghiệp, nhân viên, rồi xác minh lại grant chủ cơ sở. Cảnh báo sử dụng cả thời gian đệm và trạng thái chiếm chỗ hiện hành của Laravel, gồm `IN_PROGRESS`. Booking và các dòng dịch vụ được giữ nguyên. Audit ghi hành động và số lượt bị ảnh hưởng, không lưu lý do nghỉ trong metadata.

Kiểm tra cấu hình hiệu lực trả `testing mysql_test/beautybook_test`. `StaffCalendarTest|TestDatabaseSafetyTest` đạt **11 test, 61 assertions** sau khi bổ sung fixture nhân viên khác cùng doanh nghiệp cho test kiểm ràng buộc ID lịch nghỉ.

## Chặng 2

Đối chiếu trực tiếp `src/bookings/available-slots.service.ts`, `staff-availability.ts`, `bookings.service.ts`, `change-requests.service.ts`, `change-request-review.service.ts` và `reschedule-slots.service.ts` trong ZIP API. Code tạo và đổi lịch gọi lại bộ cấp slot của nguồn. Hai panel trong ZIP Web hướng dẫn người dùng rằng lịch nghỉ chặn slot mới và cảnh báo booking hiện có.

`AvailabilityService` kiểm tra giờ chi nhánh, ca nhân viên và khoảng nghỉ trên toàn bộ thời gian chiếm chỗ. Nhân viên chưa khai tuần kế thừa giờ chi nhánh theo lựa chọn của người dùng. Lịch nghỉ áp dụng cho cả nhân viên đang kế thừa. Ngày nghỉ tường minh trong tuần đã khai chặn slot. Booking đơn, giỏ, combo, định kỳ, waitlist và chuyển chi nhánh sử dụng kiểm tra chung. `AppointmentChangeRequestService` gọi lại kiểm tra khi duyệt đổi giờ hoặc đổi nhân viên. `BookingCommands` lấy khóa chi nhánh và doanh nghiệp trước khi lấy booking cho thao tác sửa slot.

Lượt hồi quy rộng có **82 test đạt và một test mới không đạt**. Test đã tái hiện API lưu giờ đề xuất `+07:00` thành giờ tường trong khi luồng duyệt đọc như UTC. Hai mốc đề xuất được chuẩn hóa trước khi lưu. Giá trị đã lưu từ trước được giữ để đối chiếu vì cột không ghi lại offset gốc. Lượt chạy lại `StaffCalendarTest|BookingChangeRequestTest|TestDatabaseSafetyTest` đạt **23 test, 167 assertions**. Kiểm tra slot sau thay đổi ca, giữ lịch cũ, combo, định kỳ, duyệt đổi giờ và chuyển chi nhánh đều có bằng chứng MySQL trong các lượt trên.

## Chặng 3

`resources/beautybook/src/pages/Salon/StaffDetail.jsx` thêm Giờ làm và Lịch nghỉ vào các tab hiện có. `StaffCalendarPanels.jsx` xử lý lịch theo tuần, lọc danh sách nghỉ, thêm/sửa/gỡ, lỗi tại trường và cảnh báo booking. `apiClient.js` gọi endpoint thật kèm phạm vi doanh nghiệp và chi nhánh. Chủ cơ sở có thao tác ghi. Lễ tân đúng chi nhánh xem lịch quản lý ở chế độ chỉ đọc. Bàn phím điều hướng tab và trên mobile các tab xuống dòng.

`scripts/browser-staff-calendar.mjs` chạy Chrome trên preview localhost dùng MySQL test. Script cần `STAFF_CALENDAR_TEST_DATABASE=beautybook_test`, `STAFF_CALENDAR_TEST_PASSWORD` của fixture, `EVIDENCE_DIR` và module Playwright. Bộ 128 test JavaScript và build React đạt. PHP lint đạt trên các file PHP thay đổi.

Lượt Chrome cuối xác nhận tạo booking thật, chủ cơ sở lưu ca tách đoạn, hiện cảnh báo, thêm/sửa/gỡ lịch nghỉ, và lễ tân đúng chi nhánh chỉ xem. Kiểm tra lỗi trường, điều hướng tab bằng phím, viewport 1440 px và 375 px, tab đang mở nằm trong viewport. Booking vẫn có giờ, trạng thái và ID lượt dịch vụ cũ. Không có lỗi JavaScript trong lượt chạy.

Log và ảnh nằm trong `C:/Users/User/.codex/visualizations/2026/11/03/01a1a1bb-6cf9-7191-9582-27f5103ac228/`. Xem `staff-calendar-stage1-tests.log`, `staff-calendar-stage2-tests.log`, `staff-calendar-stage2-final-tests.log`, `staff-calendar-js-tests.log`, `staff-calendar-build.log` và `staff-calendar-browser/browser-results.json`.

## Dữ liệu và hoàn tác

Migration chỉ thêm bảng. Hồ sơ cũ chưa có lịch riêng trả `configured: false`. Người dùng chọn tạm kế thừa giờ chi nhánh ở chặng 2. Lịch nghỉ vẫn áp dụng khi chưa có ca riêng. Một tuần đã cấu hình dùng các ca và ngày nghỉ được khai báo.

Khi cần hoàn tác sau khi áp dụng, đưa code về trước chặng. Hai bảng lịch có thể giữ lại để bảo toàn dữ liệu. Phương thức `down()` xóa hai bảng này, vì vậy sao lưu dữ liệu lịch trước khi chạy nếu bảng đã có bản ghi.
