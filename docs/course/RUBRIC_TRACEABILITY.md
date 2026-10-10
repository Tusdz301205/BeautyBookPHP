# Đối chiếu tiêu chí môn học với BeautyBook Laravel

Cập nhật 07/10/2026. Đối chiếu mã Laravel tại thư mục gốc cho đồ án phụ môn Lập trình mã nguồn mở. Các tỷ trọng 40/25/15/10/10 lấy từ tiêu chí người dùng cung cấp; tỷ trọng điểm không phải phần trăm chuyển đổi. BeautyBook nguồn dùng để giữ hành vi nghiệp vụ.

Các mục dưới đây đối chiếu implementation Laravel với tiêu chí môn học. Kết quả nghiệm thu và giới hạn được ghi tại [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md); kết quả bộ kiểm thử cuối nằm trong [FINAL_VALIDATION.md](evidence/2026-10-07/FINAL_VALIDATION.md).

| Tiêu chí | Tỷ trọng | Chức năng và công nghệ hiện có | Kiểm thử / trình diễn |
| --- | ---: | --- | --- |
| Hoàn thiện chức năng chính | 40% | Auth/session; workspace và Business/Branch scope; onboarding; CRUD catalog/offering/variant/staff/capability; search/pagination; upload; quote/availability/booking lifecycle; cancellation/reschedule/no-show; review/favorite; payment/refund | `AuthSecurityTest`, `IdentityScopeTest`, `ServiceCrudTest`, `MarketplaceTest`, `UploadTest`, nhóm `Booking*Test`, `EngagementTest`, `FinanceTest`. Demo khám phá → đặt lịch → phục vụ → thu tiền → review. |
| Áp dụng công nghệ Laravel | 25% | Eloquent và relations; migration MySQL với FK/unique/composite scope; Blade; Form Request; middleware; Gate/Policy; transaction/row locks; scheduler `beautybook:maintain` | `app/Models`, `app/Http/Requests`, `app/Http/Middleware`, `resources/views`, `database/migrations`, `routes/console.php`; foundation, lifecycle, capability, maintenance và concurrency tests. |
| Thiết kế và trải nghiệm | 15% | React BeautyBook nguồn nạp qua Blade; wizard quote/slot, scheduler, Owner/Admin workspace, notification, responsive CSS và ảnh nguồn | `resources/beautybook`, `resources/views/beautybook.blade.php`; 108 test FE, browser 60 trang thực tế và 35 tổ hợp responsive, gồm kiểm tra tải ảnh/font và lỗi API/JavaScript. |
| Báo cáo và thuyết trình | 10% | ERD theo migration; use case và kịch bản năm vai trò; setup; traceability; prompt port và trạng thái thật | [ERD_USE_CASE.md](ERD_USE_CASE.md), [PORT_IMPLEMENTATION_PROMPT.md](PORT_IMPLEMENTATION_PROMPT.md), [README.md](../../README.md), trạng thái và nghiệm thu ở trên; ảnh chụp từ ứng dụng chạy thực tế. |
| Sáng tạo và mở rộng | 10% | AJAX favorite/availability; promotion/voucher/quota; combo/recurring; waitlist/loyalty; package/installment/invoice/settlement; privacy export; ownership; impact; governance/trust/report | `PromotionTest`, `SchedulingTest`, `OperationsTest`, `AdvancedFinanceTest`, `PrivacyTest`, `OwnershipTest`, `ImpactTest`, `GovernanceTest`. Demo một luồng mở rộng và một nhánh từ chối. |

## Mapping theo module thực tế

Migration nằm trong `database/migrations`; bảng ghi suffix tên file. Nhóm nền tảng mang ngày 23/09/2026, nhóm mở rộng mang ngày 07/10/2026.

| Module | Controller / routes | Migration và dữ liệu chính | Test tương ứng |
| --- | --- | --- | --- |
| Identity/auth/workspace | `Auth/*`, `WorkspaceController`, `SecurityController`; `web.php` | `080000_create_identity_scope_foundation`; users, roles, permissions, role_assignments, sessions | `AuthSecurityTest`, `IdentityScopeTest`, `ZConcurrentRoleGrantTest` |
| Business/branch | `OwnerBusinessController`, `OwnerBranchController`, `AdminReviewController` | `120000_create_marketplace_onboarding_foundation`; business profiles, branches, hours, review/state events | `OnboardingTest`, `BranchLifecycleTest` |
| Catalog/staff/media | `OwnerCatalogController`, `OwnerStaffController`, `OwnerMediaController`, `MediaController` | `160000_create_catalog_staff_media_foundation`; canonical services, variants, price rules, dependencies, capabilities, media | `ServiceCrudTest`, `ServiceHierarchyTest`, `StaffCapabilityTest`, `UploadTest`, `VariantPricingTest` |
| Booking/policy | `BookingController`; `web.php` | `180000_create_booking_foundation`, `190000_create_booking_lifecycle_foundation`; items/contact/pricing snapshots, history, change requests, violations, outbox | Nhóm `Booking*Test`, `ZZConcurrentBookingRaceTest`, `ZZConcurrentBookingLifecycleRaceTest` |
| Review/favorite | `EngagementController`; `engagement.php` | `200000_create_engagement_tables`; reviews, reports, appeals, moderation events, saved_services | `EngagementTest` |
| Promotion/voucher | `PromotionController`; `promotions.php` | `210000_create_promotion_tables`; promotions, vouchers, customer entitlements/segments, redemptions | `PromotionTest` |
| Combo/recurring | `SchedulingController`; `scheduling.php` | `220000_create_combo_recurring_tables`, `221000_create_combo_booking_usages_table` | `SchedulingTest` |
| Settings/inbox/audit | `PlatformController`, `NotificationController`; `platform.php`, `web.php`, `console.php` | `230000_create_platform_tables`; settings/audit; inbox/outbox từ lifecycle migration | `PlatformMaintenanceTest`, `BookingNotificationTest` |
| Finance | `PaymentController`, `AdvancedFinanceController`; `finance.php` | `240000_create_finance_tables`, `241000_create_advanced_finance_tables`, `242000_complete_invoice_lifecycle_columns`; payment/refund/ledger, policies, packages/installments/entitlements, fees/statements/invoices | `FinanceTest`, `AdvancedFinanceTest` |
| Waitlist/loyalty | `OperationsController`; `operations.php` | `250000_create_waitlist_loyalty_tables`; offers, rules, accounts, transactions/reversals | `OperationsTest` |
| Privacy | `PrivacyController`; `privacy.php` | `260000_create_privacy_tables`; preferences, requests, encrypted export packages | `PrivacyTest` |
| Ownership | `OwnershipController`; `ownership.php` | `270000_create_ownership_tables`; encrypted identity versions, transfers, history | `OwnershipTest` |
| Impact | `ImpactController`; `impact.php` | `280000_create_impact_tables`; cases/items và replacement references | `ImpactTest` |
| Governance/trust | `GovernanceController`; `governance.php` | `290000_create_governance_tables`, `291000_add_business_trust_restrictions`; user active status, snapshots/actions, business restrictions | `GovernanceTest` |

## Cách ghi nhận nghiệm thu

- Chạy feature/concurrency tests trên MySQL test riêng theo `phpunit.xml`; kết quả SQLite không thay cho kiểm tra khóa/FK MySQL.
- Ghi ngày, lệnh, pass/fail và lỗi còn lại vào báo cáo trạng thái sau lần chạy cuối. Không suy ra phần trăm hoàn thành từ số file hoặc số test.
- Chụp trang và thao tác của từng vai trò; kiểm tra validation, scope bị từ chối, loading/error states và viewport.
- Doanh thu phải dựa trên giao dịch đã xác minh/refund; giá trị booking là chỉ số riêng. Inbox/outbox database không chứng minh đã gửi email/SMS/push.
- Privacy export và tiếp nhận request đã có mã; tiếp nhận yêu cầu xóa/chỉnh sửa không tự chứng minh hoàn tất xử lý dữ liệu. Giữ các giới hạn/khác biệt nguồn trong báo cáo cuối.

## Lịch sử

Bản ngày 23/09/2026 là kế hoạch port: IMPLEMENTATION/TEST/DEMO khi đó đều **PLANNED**. Số 57/57 frontend và 99/99 backend nguồn trong tài liệu cũ chỉ là kiểm tra BeautyBook nguồn, không phải nghiệm thu Laravel. Bảng hiện tại thay mapping dự kiến bằng đường dẫn mã đã có; lịch sử không được dùng để tuyên bố hoàn thành hiện tại.
