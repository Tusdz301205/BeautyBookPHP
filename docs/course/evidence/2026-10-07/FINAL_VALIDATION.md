# Final Laravel validation — 2026-10-07

## Nghiệm thu React nguồn và Laravel API hiện hành

- Bản frontend đang chạy tại `/beautybook`: 169 file nguồn, 164 file giống byte, 5 điểm tích hợp đã khai báo; 432/432 asset giống byte. Source gốc bên ngoài workspace: 445 checksum kiểm tra, 0 thay đổi.
- 108 kiểm thử FE đạt, gồm component actual-time/history, scheduler, store và utility. Log cuối: [source-utility-results.txt](../../../../realtime/source-utility-results.txt). Build hai pipeline Vite đạt: [full-frontend-build-results.txt](../../../../realtime/full-frontend-build-results.txt).
- [Browser 60 trang](source-parity/browser-pages.json): 54 màn hình sáu vai trò và 6 chi tiết thực tế, không lỗi JavaScript/API/ảnh/redirect; 61 ảnh đã kiểm tra kích thước tải thật.
- [Chi tiết nội bộ](source-parity/browser-internal-details.json): 7 trang đạt, hai doanh nghiệp hiển thị đúng chủ sở hữu và summary/members/catalog từ database.
- [Tương tác khám phá](source-parity/explore-interactions.json): phân trang 12→24 dịch vụ, không trùng ID; query/canonical/location lọc đúng kết quả API thật.
- [Wizard đặt lịch](source-parity/booking-wizard.json): đủ năm bước, quote/slots thật, contact/consent lưu đúng, idempotency cùng ID, reload giữ ID; lịch mới số 6 hủy qua API thường, HTTP200.
- [Responsive](source-parity/responsive/results.json): 35 tổ hợp sáu vai trò tại 320/375/414/768/1440px, font tải và không tràn ngang/lỗi JavaScript.
- Socket.IO chạy qua nginx, đăng nhập Laravel thật, resync, lease quyền và token rotation disconnect đã đạt: [laravel-smoke-results.txt](../../../../realtime/laravel-smoke-results.txt). Scheduler container thực thi `beautybook:maintain` mỗi phút.
- PHP lint: 308 file đạt; Blade compile và 126 view đã biên dịch đạt lint. Database demo và test vẫn tách biệt.
- Nhóm hồi quy cuối owner/context, privacy, báo cáo theo ngày, review và DTO quản trị: [22 đạt / 220 assertions](source-parity/owner-context-closure.txt). Barrier cấp quyền giữ đủ 5 worker/1 assignment, [đạt 8 assertions](source-parity/role-barrier-diagnostic.txt).
- Full suite MySQL: **277 đạt / 2.901 assertions**, 426,39 giây; [log](source-parity/full-suite.txt) và [JUnit](source-parity/full-suite.xml). Hai lần trước có một lỗi startup barrier và một fixture thiếu tham số from/to; không giảm assertion hoặc số worker. Sau suite này chỉ chỉnh nhãn giá hiển thị để giữ phần thập phân như `toLocaleString('vi-VN')` nguồn; [nhóm trình bày công khai](source-parity/public-presentation-final.txt) **4 đạt / 28 assertions**.

Các bằng chứng trên không phải tỷ lệ giống sản phẩm. Readiness card liệt kê scalar trên màn hình admin cũng có trong code nguồn; không đổi component nguồn để che khác biệt. Fixture tham chiếu có provenance và giá template, không phải database/lịch sử nguồn. Xem [trạng thái hiện hành](../../IMPLEMENTATION_STATUS.md).

## Baseline Blade trước khi thay frontend bằng React nguồn

- Full isolated MySQL suite: **197 passed / 1453 assertions**, 359.97 seconds. See [ui-parity/full-tests.txt](ui-parity/full-tests.txt). No test assertions or concurrency barriers were weakened.
- Production browser acceptance: **205 checks** across guest/customer/owner/receptionist/staff/admin, at 320/375/414/768/1440px; real navigation, day/week calendar, five booking steps through authoritative quote/slot confirmation, drawer and content bounds. No JavaScript runtime errors. See [ui-parity/results.json](ui-parity/results.json) and screenshots in that directory. This browser run did not submit bookings.
- Cart creation/replay/rollback/provider fallback and calendar tenant/item timing are covered by real MySQL tests. AUTO uses one common provider as in the original generic booking flow.
- PHP application/test syntax: **252 files passed**, [ui-parity/php-syntax.txt](ui-parity/php-syntax.txt). JavaScript syntax and latest Vite production build passed. Final CSS and keyboard focus refinements were made after the full backend suite and checked through the browser runs.
- Final Blade `view:cache` passed; **125 compiled views passed syntax validation**, [ui-parity/blade-syntax.txt](ui-parity/blade-syntax.txt).
- Cart browser regression passed with actual demo quote/slots followed by intercepted out-of-order pricing responses, including keyboard focus after reordering, pending-price gating, refreshed confirmation and rejected voucher: [ui-parity/cart-browser-regression.txt](ui-parity/cart-browser-regression.txt). No booking was written by this script.
- Final local `/ready`: `status=ready`, `database=true`, `seeded=true`. The demo and test MySQL databases remain separate.
- Original reference source remains read-only. SHA-256 comparison: **445 selected source files checked / 0 changed**, [ui-parity/source-integrity.json](ui-parity/source-integrity.json).
- Current scope and remaining original-source differences: [UI_SOURCE_PARITY.md](../../UI_SOURCE_PARITY.md). These checks do not substantiate a 100% similarity claim.

## Earlier baseline before source UI review

- Full isolated MySQL suite: **180 passed, 1342 assertions**, 328.68 seconds. See `full-final-tests.txt`.
- Main browser acceptance: **63 checks**, guest and five authenticated roles. See `browser-results.json`.
- Supplemental browser acceptance: **10 checks**, profile persistence, three profile viewports and six customer module mobile layouts. See `profile-browser-results.json`.
- Operational booking evidence: actual MySQL contact, service/staff snapshot, pricing, revision and initial history. See `operational-booking-db.json`.
- PHP application/test syntax: **227 files passed**. Compiled Blade syntax after final finance layout changes: **120 files passed**.
- Final Vite production build passed. Final Blade `view:cache` passed. Scoped `git diff --check` passed.
- Local `/ready`: `status=ready`, `database=true`, `seeded=true`.
- Composer lock/platform dry run passed in PHP 8.4 Docker runtime. Fresh dependency download and remote GitHub CI execution are not claimed by this check.

The final change after that earlier full suite was finance page layout/CSS only. Business-wide automatic restrictions/suspension follow the user's explicit approval and require fresh impact coverage before applying status. The latest suite above includes the subsequent cart/calendar and shared UI work.

These results verify the implemented coursework port; they do not prove identical NestJS/React routes, database schema or every source behavior. Current boundaries are recorded in [IMPLEMENTATION_STATUS.md](../../IMPLEMENTATION_STATUS.md).

## Coursework-only cleanup

The user requested removing both reference applications and retaining only coursework files. NestJS/React source, legacy Compose/CI, old audit/planning documents, downloaded tooling and temporary artifacts were removed. Old database backup files were removed after separate explicit user confirmation; live databases were not changed by cleanup.

Post-cleanup checks passed: Vite production build, Blade compilation, syntax of 120 compiled views, retained Markdown links, scoped whitespace validation, local readiness and the 10 supplemental browser checks. The latest 197-test suite also ran on the cleaned coursework workspace. Newly supplied reference source sits outside this workspace and was only read.
