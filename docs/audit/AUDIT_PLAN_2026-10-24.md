# Audit BeautyBookPHP và kế hoạch cho 2 thành viên — 24/10/2026

Tài liệu này chỉ gồm audit và kế hoạch. Chưa sửa code, test, migration, seed, cấu hình hay dependency. Chưa commit.

## 0. Baseline và giới hạn

| Mục | Giá trị |
| --- | --- |
| Branch / commit | `main` @ `7228dea` (fix: align PHP source surface and speed up local runtime) |
| `git status` lúc bắt đầu | sạch, không có thay đổi sẵn có |
| Runtime khai báo | PHP 8.4 (Dockerfile `php:8.4-cli-bookworm`), MySQL 8.4, Laravel `^13.17` theo lock, Node 22 |
| Runtime trên máy audit | Docker daemon **không chạy**; PHP cục bộ 8.3.33 (lock cần ≥ 8.4.1); không có `vendor/`, `node_modules/` |
| Đã chạy (2026-10-24T10:30Z) | `php -l` 330 file PHP được Git quản lý trong app/routes/database/config/bootstrap/tests: **0 lỗi**. `node --check` cho `realtime/*.mjs`, `scripts/*.mjs`: **đạt** |
| BLOCKED | PHPUnit (MySQL test), `npm run build`, `node --test realtime/server.test.mjs`, browser, mọi truy vấn DB demo/test. Lý do: không có Docker/MySQL, PHP 8.3 không cài được lock, thiếu dependency. Không cài dependency vào checkout đang làm việc. |
| Kết quả cũ | 291 test/3.041 assertion trong `PARITY_PERFORMANCE_AUDIT_2026-10-11.md` là lịch sử, **không** dùng làm baseline mới |
| Cây nguồn bên ngoài | `../beauty-booking-api-main` không được dùng; không đối chiếu parity trong lượt này |
| Prompt | Bản prompt dán vào bị cắt sau mục 2.A (Danh tính và phân quyền). Lượt audit vẫn theo luồng end-to-end cho booking, realtime, media, finance theo bối cảnh dự án |

Phạm vi đã đọc kỹ (theo luồng gọi): `bootstrap/app.php`, middleware, `routes/web.php`, `routes/beautybook*.php`, `AuthController`, `LoginRequest`, `LoginContext`, `ApiScope`, `SessionContext`, `IdentityPresenter::session`, `Beautybook\BookingController`, `BookingAccessService`, `BookingLifecycleAccessService`, `BookingLifecycleService`, `CartBookingService`, `AvailabilityService`, `AppointmentChangeRequestService`, `BookingCommands`, `BookingMaintenanceService`, `RealtimeController`, `realtime/server.mjs`, nginx, Dockerfile/entrypoint, media (`MediaController`, `ManagementMediaController`, `MediaManagementService`), `UsersController`, `GovernanceService` (grant/revoke), `RoleAssignmentService`, phần finance/voucher/engagement/catalog/staff có lookup theo ID, `apiClient.js`, `phpunit.xml`, CI, `UtcDateTime` cast.

Chưa audit sâu (cần lượt sau): toàn bộ trang React, Blade legacy, toán khuyến mãi/loyalty/gói/installment, privacy, chuyển chủ sở hữu, impact, onboarding, logic tự đình chỉ của governance, tác dụng phụ của từng `scripts/*.mjs`.

## 1. Bản đồ kiến trúc (đã kiểm chứng)

```
Browser /beautybook (Blade shell + React bundle)
  → apiClient.js: cookie session + X-CSRF-TOKEN + Bearer (token 15 phút, hash lưu trong session)
     + X-Business-Id / X-Branch-Id + Idempotency-Key tự sinh cho mọi POST
  → nginx :8000 → php artisan serve (4 worker) — routes/web.php (nhóm "web": session, CSRF, EnsureActiveAccount)
     → BeautybookSession (user + bearer khớp hash + chưa hết hạn) → ApiScope::current (assignment, header scope)
     → Controller Beautybook\* → Service domain (BookingAccess/LifecycleAccess, Cart/Combo/Recurring, Availability…)
     → Eloquent/MySQL (thời điểm lịch hẹn lưu UTC qua UtcDateTime; nhiều cột khác dùng cast 'datetime' theo APP_TIMEZONE)
  → Presenter → JSON
MySQL trigger → beautybook_realtime_events ← realtime/server.mjs poll 2 s/socket qua /api/v1/realtime/session → Socket.IO
scheduler: schedule:work → beautybook:maintain mỗi phút (hết hạn giữ chỗ/yêu cầu, outbox, privacy, waitlist, ownership, impact)
```

Hai chế độ: `BEAUTYBOOK_LEGACY_UI=false` (mặc định) chỉ đăng ký `beautybook.php` + redirect; `true` (phpunit.xml ép) đăng ký thêm `legacy.php` và 2 POST privacy. Toàn bộ test API chạy ở chế độ `true`; chế độ mặc định chỉ có `SourceSurfaceTest` (2 test).

Thời gian: `APP_TIMEZONE=Asia/Ho_Chi_Minh` trong Compose/.env.example, nhưng **phpunit.xml không đặt** → test chạy UTC (`config/app.php` mặc định `UTC`).

## 2. Phát hiện đã xác nhận bằng code

Mức: Cao / Trung bình / Thấp. "Tái hiện" = BLOCKED do không có runtime; bằng chứng là vết gọi.

### C-01 (Cao) — Đổi lịch từ React lưu sai giờ, duyệt xong lịch bị dời +7 giờ

- `BookingController::change` chuyển nguyên `proposedStartTime` từ client vào service (`app/Http/Controllers/Beautybook/BookingController.php:373`).
- React gửi `slot.start` (`resources/beautybook/src/components/customer/RescheduleModal.jsx:13`), lấy từ API slots có offset chi nhánh: `CarbonImmutable::parse(..., $timezone)->toIso8601String()` → `…T10:00:00+07:00` (`BookingController.php:284`).
- `AppointmentChangeRequest` cast `proposed_start_at` là `'datetime'` (`app/Models/AppointmentChangeRequest.php:20`); Eloquent parse chuỗi có offset rồi `format('Y-m-d H:i:s')` → lưu giờ treo tường `10:00` (giờ địa phương).
- Khi duyệt, `applyReschedule` đọc raw và **coi là UTC**: `CarbonImmutable::parse($request->getRawOriginal('proposed_start_at'), 'UTC')` (`app/Services/Booking/AppointmentChangeRequestService.php:255`) → 10:00Z = 17:00 giờ VN.
- Luồng Blade cũ chuẩn hoá về UTC trước khi gọi service (`app/Http/Controllers/BookingController.php:429-433`), và test hiện có chỉ dùng chuỗi `+00:00` qua route legacy (`tests/Feature/BookingChangeRequestTest.php:128`) nên không bắt được.
- Hệ quả: khách chọn 10:00, chủ tiệm duyệt, lịch thành 17:00; kiểm tra trùng lịch cũng chạy trên khung giờ sai.

### C-02 (Cao) — Chủ doanh nghiệp cấp/gỡ vai trò cho bất kỳ tài khoản vận hành nào và đăng xuất họ

- `POST /api/v1/users/{id}/roles` và `DELETE /api/v1/users/{id}/roles/{role}` (`routes/beautybook-users.php`) → `GovernanceService::grantRole/revokeRole` chấp nhận actor `BUSINESS_OWNER` (`app/Services/Platform/GovernanceService.php:110,133`).
- Kiểm tra chỉ là: vai trò STAFF/RECEPTIONIST và business = business của actor (`:116`). Không kiểm tra người nhận có quan hệ/đồng ý (lời mời) với doanh nghiệp. `User::findOrFail($id)` nhận mọi user (`UsersController.php:124`).
- Mỗi lần grant/revoke đều xoá toàn bộ session của người nhận (`GovernanceService.php:123,147`, `RoleAssignmentService::revoke`).
- Hệ quả: một chủ tiệm có thể gán mình làm "chủ" của một vai trò STAFF trên tài khoản chủ tiệm khác hoặc quản trị viên nền tảng (tài khoản OPERATIONAL), lặp grant/revoke để đăng xuất họ liên tục; người nhận bị thêm workspace không mong muốn (`LoginContext` sẽ bắt chọn doanh nghiệp). Đồng thời là kênh dò ID người dùng (404 vs 409/422). Không có test cho đường owner (chỉ có `GovernanceRoleExpiryTest` cho admin).

### C-03 (Trung bình) — Duyệt đổi lịch không kiểm tra lại giờ mở cửa, ngày nghỉ, lead time, horizon, trạng thái chi nhánh

- `assertCreatePayload` chỉ kiểm tra `start < end`, `start > now`, giữ nguyên thời lượng (`AppointmentChangeRequestService.php:182-200`).
- `applyReschedule` chỉ kiểm tra trùng khách và trùng nhân viên rồi dịch chuyển (`:253-300`); không gọi `AvailabilityService::check` (giờ mở cửa, `beautybook_branch_calendar`, `leadTimeMinutes`, `bookingHorizonDays`) và không kiểm tra lại "còn ở tương lai" lúc duyệt (yêu cầu sống 24 giờ).
- Hệ quả: có thể duyệt lịch vào ngày đóng cửa, ngoài giờ, hoặc đã qua.

### C-04 (Trung bình) — Yêu cầu thay đổi hết hạn sớm 7 giờ ở runtime; một truy vấn "lịch tương lai" lệch 7 giờ

- `expires_at` được tạo bằng `CarbonImmutable::now('UTC')->addHours(24)` (`AppointmentChangeRequestService.php:29,68`) → lưu giờ UTC.
- Job bảo trì so với `now()` theo `APP_TIMEZONE` = Asia/Ho_Chi_Minh: `where('expires_at', '<=', now())` (`app/Services/Booking/BookingMaintenanceService.php:36`) → hết hạn sau ~17 giờ thay vì 24 giờ.
- `UsersController.php:101` so `appointment_start_at` (UTC) với `now()` (giờ VN) → danh sách "lịch tương lai" của nhân viên trong trang admin bỏ sót 7 giờ.
- Các nơi khác đã dùng `now()->utc()`/`now('UTC')` đúng (ví dụ `ImpactService.php:99`, `ManagementStaffController.php:57`).

### C-05 (Trung bình) — Môi trường test khác timezone runtime

- `phpunit.xml` không đặt `APP_TIMEZONE` → test chạy UTC; Compose chạy Asia/Ho_Chi_Minh. C-01 phần lưu chuỗi và C-04 không thể lộ ra trong test. Đây là nguyên nhân gốc khiến lớp lỗi timezone lọt qua.

### C-06 (Trung bình) — Realtime có thể bỏ sót sự kiện vĩnh viễn

- `RealtimeController::session` lấy `$max = max(id)` rồi đọc `id > after AND id <= max` và trả `cursor = max` (`app/Http/Controllers/Beautybook/RealtimeController.php:25-27`).
- ID auto-increment được cấp lúc INSERT (trigger), không theo thứ tự COMMIT. Giao dịch A nhận id 100 nhưng commit sau giao dịch B id 101: lần poll giữa hai commit trả cursor 101 → sự kiện 100 không bao giờ được gửi. Client chỉ tự resync khi reconnect.

### C-07 (Trung bình) — Lộ session ID cho JavaScript, kết hợp APP_KEY mặc định công khai

- `GET /api/v1/auth/sessions` trả `id` là session ID thật (`AuthController.php:125`); admin `GET /api/v1/users/{id}` trả session ID của người khác (`UsersController.php` hàm `show`, mục `sessions`).
- Compose dùng APP_KEY mặc định `base64:AAAA…` nếu không đặt `LARAVEL_APP_KEY` (`docker-compose.laravel.yml`, service `app`). Với key công khai, ai có session ID có thể tự mã hoá cookie session hợp lệ; signed URL (verify email) cũng giả được. XSS bất kỳ trở thành chiếm phiên dù cookie HttpOnly.
- Ghi chú: README nói cấu hình chỉ dành cho local; vẫn cần chặn đường lộ ID và cảnh báo khi key mặc định.

### C-08 (Thấp) — Thông báo booking gửi cả assignment đã hết hạn

- `BookingLifecycleService::recordTransitionNotifications` chọn owner/receptionist theo `role_assignments` mà không lọc `expires_at` (`app/Services/Booking/BookingLifecycleService.php`, khối `$recipientIds`). Nhân viên đã hết quyền vẫn nhận mã lịch hẹn mới.

### C-09 (Thấp, hạ tầng test) — Không có cơ chế khoá/bảo vệ database test như README mô tả

- `tests/TestCase.php` rỗng; không có lease hay kiểm tra tên DB/host trước khi `RefreshDatabase` chạy (53 file test dùng `RefreshDatabase`). `realtime/README.md` nói "shared test lease" nhưng code không có.
- An toàn hiện chỉ dựa vào `force="true"` trong phpunit.xml. Nếu `bootstrap/cache/config.php` tồn tại (ai đó chạy `config:cache`), Laravel dùng config cache và test có thể xoá DB demo. Hai người chạy suite cùng lúc trên cùng `mysql_test` sẽ phá nhau.

### C-10 (Thấp) — Độ phủ kiểm thử theo chế độ và CI

- API test chỉ chạy với `BEAUTYBOOK_LEGACY_UI=true`; chế độ mặc định (React) chỉ có 2 test bề mặt route.
- CI (`.github/workflows/laravel.yml`) không chạy `node --test realtime/server.test.mjs` hay `scripts/frontend-parity-cleanup.test.mjs`.

### C-11 (Thấp) — Ngữ nghĩa "trùng lịch" không thống nhất

- `AvailabilityService::check` chặn theo trạng thái **booking** (`PENDING…IN_PROGRESS`) và không xét trạng thái item (item SKIPPED/CANCELLED trong booking còn hiệu lực vẫn chiếm chỗ).
- `applyReschedule`/`applyStaffChange` chặn theo trạng thái **item** (`SCHEDULED, IN_PROGRESS`) và không xét trạng thái booking. Hai đường có thể cho kết quả khác nhau cho cùng khung giờ.

### C-12 (Thấp) — Vận hành/hiệu năng

- `beautybook:maintain` chạy 6 bước tuần tự trong một closure; exception ở một bước bỏ qua các bước sau (`routes/console.php`).
- Mỗi socket poll Laravel 2 giây/lần, mỗi lần ghi session DB, trên `php artisan serve` 4 worker và route có `block(10,10)`.
- Một session chỉ giữ một access-token hash (`IdentityPresenter::session`); nhiều tab làm mới token sẽ vô hiệu token của nhau và ngắt socket của nhau.
- Một số danh sách không phân trang và gọi presenter từng dòng: `bookings/my-appointments`, `bookings/salon-queue` (`BookingController::appointments`), `change-requests/pending`, `trust-actions`.
- Repo chứa log kết quả chạy (`realtime/*-results.txt`, `realtime/*.txt`) lẫn với source.

## 3. Rủi ro chưa tái hiện (cần test MySQL để kết luận)

- **R-01** `ApiScope::assignments` fallback: nếu assignment đang chọn đã hết hạn, vẫn lấy nó làm "anchor" (không lọc `expires_at`) rồi cấp các grant còn sống trong cùng business (`app/Services/Beautybook/ApiScope.php:21-24`). Cần xác nhận đây có phải hành vi mong muốn.
- **R-02** Kiểm tra theo request không xét chi nhánh/doanh nghiệp đã soft-delete, trong khi `LoginContext::usable` có xét. Nhân viên của chi nhánh đã xoá có thể dùng tiếp API tới khi session hết.
- **R-03** Race giữa các đường ghi lịch: tạo lịch khoá hàng `branches` trước; `move` khoá booking rồi mới khoá branch; duyệt đổi lịch không khoá branch, dựa vào `SELECT … FOR UPDATE` trên `booking_services`. Chưa kiểm chứng index phù hợp cho gap lock (`staff_profile_id, occupied_start_at`). Cần test song song trên MySQL thật.
- **R-04** `BookingController::query` (`:46`) và `FinanceController.php:279` dùng `where('customer_profile_id', <value('id')>)`; nếu khách chưa có profile, Laravel biến thành `IS NULL` và trả mọi booking/gói không gắn khách (walk-in). Các đường đăng ký hiện luôn tạo profile, nên chưa khai thác được; nên chặn từ gốc.
- **R-05** Journal realtime dựa vào trigger MySQL cần `log_bin_trust_function_creators=1`; môi trường MySQL quản lý có thể từ chối migration.

## 4. Câu hỏi nghiệp vụ

Đã quyết định ngày 24/10/2026:

- **Q-01 — Chỉ theo assignment đang chọn.** Không gộp quyền của các assignment khác, kể cả trong cùng doanh nghiệp. Assignment đang chọn hết hạn hoặc bị thu hồi thì request bị từ chối, không chuyển sang grant khác (đóng luôn R-01).
- **Q-02 — Chủ tiệm chỉ thêm nhân viên qua lời mời có chấp nhận; admin nền tảng vẫn cấp trực tiếp.** Luồng lời mời đã có (`ManagementStaffController::invite/acceptInvitation`, gán vai trò khi người nhận chấp nhận đúng email). Không trang React nào gọi `usersApi.assignRole/revokeRole`, nên chặn owner ở endpoint cấp vai trò không làm hỏng giao diện. Quyền gỡ STAFF/RECEPTIONIST trong doanh nghiệp của chính owner được giữ, vì quyết định chỉ nói về việc thêm.

Hệ quả cần chốt thêm:

- **D-03 — Đã chốt 24/10/2026: làm theo đề xuất.** Thêm `POST /api/v1/auth/context` và bộ chọn trong `AppShell`, bộ chọn chỉ hiện khi có trên một assignment còn hiệu lực. Chi tiết ở task A6b. Bối cảnh khi đặt câu hỏi: người có nhiều assignment trong cùng doanh nghiệp chọn assignment bằng cách nào? Hiện `LoginContext::resolve` lọc theo doanh nghiệp rồi lấy assignment ưu tiên cao nhất (owner > receptionist > staff) và **bỏ qua `branchId`** khi chọn. Màn hình đăng nhập React cố ý không có bộ chọn workspace (`LoginScreen.jsx`: "Workspace selection is intentionally disabled"), và không có endpoint đổi ngữ cảnh. Nếu chỉ áp Q-01, người vừa là lễ tân chi nhánh A vừa là nhân viên chi nhánh B sẽ mất hẳn quyền nhân viên ở B trên React. Đề xuất: endpoint `POST /api/v1/auth/context` cho người đã đăng nhập (chạy lại `LoginContext`, regenerate session và access token) và một bộ chọn trong `AppShell` chỉ hiện khi có trên một assignment còn hiệu lực.

Còn mở:
- **Q-03** Huỷ cả chuỗi lịch định kỳ: hiện một kỳ còn dưới 4 giờ làm thất bại toàn bộ thao tác (`RecurringService::cancel` gọi `transition` cho từng kỳ trong một transaction). Muốn huỷ các kỳ hợp lệ và báo kỳ bị chặn?
- **Q-04** Availability không xét ca làm/ngày nghỉ nhân viên; tài liệu ghi là có chủ đích theo nguồn. Giữ nguyên?

## 5. Kế hoạch khắc phục cho 2 thành viên

Nguyên tắc: mỗi file chỉ một người sửa trong một giai đoạn; mỗi task một PR nhỏ có test tái hiện lỗi viết **trước** bản sửa; không chạy suite song song trên cùng `mysql_test` (mỗi người dùng máy/Compose riêng, hoặc xếp lượt).

### Giai đoạn 0 — Nền tảng kiểm thử (Thành viên A làm, B review; chặn các giai đoạn sau)

| ID | Việc | File | Hoàn thành khi |
| --- | --- | --- | --- |
| A0.1 | Guard trong `tests/TestCase.php`: dừng nếu config đang cache, hoặc DB hiệu lực không phải `beautybook_test`@`mysql_test`; thêm khoá `GET_LOCK` theo lượt chạy | `tests/TestCase.php` | Chạy test với config cache hoặc DB demo bị từ chối trước khi migrate |
| A0.2 | Đặt `APP_TIMEZONE=Asia/Ho_Chi_Minh` trong `phpunit.xml`, chạy full suite, ghi lại test đỏ mới (không sửa test để xanh) | `phpunit.xml` | Danh sách test đỏ do timezone được ghi vào báo cáo; baseline mới có ngày giờ |
| A0.3 | Ghi baseline: full suite MySQL, `npm run build`, `node --test realtime/server.test.mjs` | — (chỉ chạy) | Kết quả lưu ở `docs/audit/` |

### Giai đoạn 1 — Sửa lỗi xác nhận (song song, không trùng file)

**Thành viên A — danh tính, phân quyền, realtime, hạ tầng**

| ID | Việc | File chính | Phụ thuộc | Test chấp nhận |
| --- | --- | --- | --- | --- |
| A1 | C-02 theo Q-02: `grantRole` chỉ nhận actor `PLATFORM_ADMIN`. Owner nhận 403 trước khi tra cứu người nhận, nên không xoá session của ai và không dò được ID qua 404/409. `revokeRole` của owner giữ nguyên (STAFF/RECEPTIONIST trong doanh nghiệp mình). Thêm nhân viên chỉ qua `staff/invitations` | `GovernanceService.php` (`grantRole`), `UsersController.php` (`grant`) | — | Owner gọi `POST users/{id}/roles` với mọi ID (có/không tồn tại, owner khác, admin) đều nhận cùng một 403; session người nhận còn nguyên. Admin vẫn cấp được. Lời mời → chấp nhận vẫn tạo assignment |
| A2 | C-07: bỏ session ID thô khỏi `auth/sessions` và admin `users/{id}` (dùng ID tham chiếu băm/HMAC để thu hồi); entrypoint cảnh báo/từ chối APP_KEY mặc định khi `APP_ENV` khác local | `AuthController.php`, `UsersController.php` (show), Compose/entrypoint | A1 (cùng `UsersController`) | Response không còn chuỗi trùng cookie session; thu hồi thiết bị vẫn hoạt động |
| A3 | C-04 phần admin: `UsersController.php:101` dùng `now()->utc()` | `UsersController.php` | A2 | Test với APP_TIMEZONE VN thấy lịch trong 7 giờ tới |
| A4 | C-06: cursor theo "an toàn commit" (ví dụ chỉ phát tới id nhỏ nhất còn đang mở, hoặc lưu `committed_at`/sequence riêng) và test bằng hai giao dịch commit đảo thứ tự | `RealtimeController.php`, migration journal mới nếu cần | A0 | Test MySQL: sự kiện id thấp commit muộn vẫn được giao |
| A5 | C-08: lọc assignment hết hạn khi chọn người nhận thông báo | `BookingLifecycleService.php` (chỉ `recordTransitionNotifications`) | — | Receptionist hết hạn không nhận thông báo |
| A6 | Q-01 + R-01 + R-02 (một PR): `ApiScope::assignments()` chỉ trả assignment đang chọn còn hiệu lực, bỏ fallback dùng anchor hết hạn; `forRoles`/`requestForRoles` chỉ khẳng định assignment đang chọn thuộc vai trò/phạm vi yêu cầu, không đổi sang grant khác; `current()` so `X-Branch-Id` với assignment đang chọn; kiểm tra theo request loại chi nhánh/doanh nghiệp đã soft-delete như `LoginContext::usable`. Sửa mọi nơi đang đọc hợp quyền: `IdentityPresenter` (roles/permissions/scopes), `BookingPresenter:38`, `ManagementStaffController:24`, `FinanceController:59,252,282`, `BookingController::query` (khối vận hành), `RealtimeController:15-23`. Gộp luôn R-04 (`BookingController::query` khối khách, `FinanceController:279`) vì cùng hàm | `ApiScope.php`, `SessionContext.php`, các file vừa nêu | A0, D-03 cho phần chọn assignment | Lễ tân A + nhân viên B đang chọn lễ tân A: không thấy lịch của B, không hoàn thành được dịch vụ ở B, realtime không nhận sự kiện của B. Assignment đang chọn hết hạn → 403 ở request kế tiếp. Staff chi nhánh đã xoá → 403. Khách không có profile → 403 |
| A6b | D-03 (đã chốt). **Backend:** (1) `LoginContext` chọn assignment khớp `branchId` (và vai trò nếu có), không lấy theo độ ưu tiên khi đã có lựa chọn. (2) Payload người dùng (`IdentityPresenter::user`) thêm `availableContexts`: danh sách assignment còn dùng được theo đúng quy tắc `LoginContext::usable` (id, vai trò, tên doanh nghiệp, tên chi nhánh, hạn), kể cả PLATFORM nếu tài khoản có; `roles/permissions/scopes` vẫn chỉ theo assignment đang chọn (A6). (3) `POST auth/context` nhận `{assignmentId}`, nằm trong nhóm `BeautybookSession` (cần cookie, CSRF, bearer), throttle riêng. Kiểm tra lại assignment thuộc user, còn hiệu lực, chi nhánh/doanh nghiệp chưa xoá; khoá hàng user khi chuyển; regenerate session ID; xoá `beautybook_context_branch`; cấp access token mới; ghi audit `CONTEXT_SWITCHED`; trả response giống `sessionResponse` (có `X-CSRF-TOKEN` mới). Socket realtime cũ tự ngắt vì token đổi. **Frontend:** `authApi.switchContext`; trong `authStore` thêm `switchContext` gọi `useBookingStore.getState().reset()` rồi `setSession(result)`, giống `logout` để không mang lựa chọn đặt lịch sang ngữ cảnh mới; `AppShell` hiện bộ chọn khi `availableContexts.length > 1`, sau khi chuyển điều hướng về `homeFor(sessionType)` | `LoginContext.php`, `IdentityPresenter.php`, `AuthController.php`, `routes/beautybook.php`, `apiClient.js`, `store/authStore.js`, `components/layout/AppShell.jsx` | A6 | Lễ tân A + nhân viên B: đổi sang nhân viên B không cần đăng xuất, thấy đúng lịch được giao ở B và không còn thấy hàng chờ của A. Session ID cũ không dùng được nữa; token cũ nhận 401. Gửi `assignmentId` của người khác, đã hết hạn, hoặc thuộc chi nhánh đã xoá → 403, ngữ cảnh hiện tại giữ nguyên. Thiếu CSRF → 419. Tài khoản chỉ có một assignment không thấy bộ chọn. Bản nháp đặt lịch bị xoá sau khi chuyển |

Test phải viết lại theo Q-01, đây là thay đổi yêu cầu chứ không phải sửa test cho xanh: `BeautybookMultiRoleTest::test_identity_unions_live_same_context_permissions…`, `…test_expired_primary_assignment_can_refresh_with_another_live_grant…`, `…test_receptionist_staff_executes_only_assigned_items_without_changing_session_selection`, và `ManagementMultiRoleTest::test_catalog_retains_explicit_authorized_branch_and_uses_owner_grant…`. Các test khẳng định *không* mở rộng quyền (`…does_not_expand_staff_authority…`, `…does_not_unlock_staff_private_detail…`) phải giữ nguyên và vẫn xanh.

**Thành viên B — nghiệp vụ đặt lịch và thời gian**

| ID | Việc | File chính | Phụ thuộc | Test chấp nhận |
| --- | --- | --- | --- | --- |
| B1a | C-01 phía service: chuẩn hoá `proposed_*` về UTC trong `AppointmentChangeRequestService::create` trước khi lưu; đổi cast `proposed_start_at`, `proposed_end_at`, `expires_at` sang `UtcDateTime` | `AppointmentChangeRequestService.php`, `AppointmentChangeRequest.php` | A0.2 | Test service với `+07:00`, `Z`, `+00:00` đều lưu đúng giờ UTC |
| B1b | C-01 phía controller React: test API end-to-end (slot `+07:00` → tạo yêu cầu → duyệt → `appointment_start_at` đúng giờ khách chọn) | `Beautybook/BookingController.php` (`change`) | B1a, **A6 đã merge** (cùng file) | Test API React xanh với APP_TIMEZONE VN |
| B2 | C-03: khi duyệt RESCHEDULE gọi lại kiểm tra giờ mở cửa/lịch ngoại lệ/lead time/horizon/trạng thái chi nhánh và "còn tương lai" (tái sử dụng `AvailabilityService::check` với `ignoreBookingId`) | `AppointmentChangeRequestService.php` | B1 | Duyệt vào ngày đóng cửa/ngoài giờ/đã qua → 409 |
| B3 | C-04: so `expires_at` bằng `now('UTC')` trong bảo trì; rà các cột UTC còn lại so với `now()` | `BookingMaintenanceService.php` | A0.2 | Với APP_TIMEZONE VN, yêu cầu hết hạn đúng sau 24 giờ |
| B4 | C-11: thống nhất định nghĩa "chiếm chỗ" (booking hiệu lực **và** item chưa huỷ/bỏ qua) cho Availability, đổi lịch, đổi nhân viên, move | `AvailabilityService.php`, `AppointmentChangeRequestService.php` | B2 | Bộ test bảng cho từng tổ hợp trạng thái |
| ~~B5~~ | Chuyển vào A6 (cùng hàm `query` và cùng khối scope của `FinanceController`) | — | — | — |
| B6 | R-03: test song song MySQL tạo lịch vs duyệt đổi lịch vs move trên cùng nhân viên; nếu đỏ thì thống nhất thứ tự khoá (branch → booking → items) | `AppointmentChangeRequestService.php`, `BookingCommands.php` | B2, B4, A6 (`BookingCommands` gọi `requestForRoles`) | Không có hai booking chồng giờ cùng nhân viên sau N lượt song song |

### Giai đoạn 2 — Độ phủ và vận hành (sau khi giai đoạn 1 merge)

| ID | Người | Việc |
| --- | --- | --- |
| A7 | A | CI: thêm job chạy nhóm test API với `BEAUTYBOOK_LEGACY_UI=false`; chạy `node --test` cho realtime và frontend policy (C-10) |
| A8 | A | Tách log kết quả khỏi `realtime/` (C-12); tách từng bước `beautybook:maintain` để lỗi một bước không chặn bước khác |
| B7 | B | Phân trang/eager-load cho `appointments`, `pending`, `trust-actions` (C-12); xử lý Q-03 cho huỷ chuỗi định kỳ |
| B8 | B | Lượt audit tiếp: khuyến mãi/voucher/loyalty/gói và refund, theo cùng phương pháp |

### Thứ tự và kiểm soát xung đột

1. A0 (A) → merge.
2. Giai đoạn 1a, song song:
   - A: A1 → A2 → A3 (chung `UsersController`); A6 (lớn nhất, ưu tiên sớm); A4; A5.
   - B: B1a → B2 → B4; B3. Các việc này chỉ chạm `AppointmentChangeRequestService`, model, `AvailabilityService`, `BookingMaintenanceService`, không đụng file của A6.
3. Giai đoạn 1b, sau khi A6 merge: B1b, B6, A6b. Nên merge A6 và A6b sát nhau, vì giữa hai lần merge người nhiều assignment chỉ dùng được assignment ưu tiên cao nhất.
4. File theo người: `ApiScope`, `SessionContext`, `IdentityPresenter`, `BookingPresenter`, `RealtimeController`, `UsersController`, `GovernanceService`, `BookingLifecycleService` (một hàm), `FinanceController`, `ManagementStaffController` → A. `AppointmentChangeRequestService`, `AppointmentChangeRequest`, `AvailabilityService`, `BookingMaintenanceService`, `BookingCommands` → B. `Beautybook/BookingController.php`: A sửa `query` trong 1a; B sửa `change` trong 1b, sau khi A6 merge.
5. Mỗi PR: test tái hiện đỏ trước, xanh sau; chạy full suite trên `mysql_test` theo lượt; không sửa test cũ để xanh, trừ các test hợp quyền đã liệt kê ở A6 (yêu cầu đã đổi theo Q-01).
6. Q-01, Q-02, D-03 đã chốt. Còn mở Q-03 (huỷ chuỗi định kỳ, ảnh hưởng B7) và Q-04 (ca làm nhân viên, hiện giữ nguyên theo tài liệu). Cả hai không chặn giai đoạn 0 và 1.
