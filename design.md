# BeautyBookPHP design system

Locked on 07/10/2026 against the read-only BeautyBook frontend at `C:/Users/Admin/Downloads/beauty-booking-api-main/beauty-booking-web-main/beauty-booking-web-main`.

Public pages use the original warm beauty marketplace: utility strip, concise header, editorial two-column home hero, category rail and real salon results. Auth uses a focused form with editorial imagery. Customer and operational workspaces use the original workbench: 272px dark sidebar, 64px topbar, contextual page headings and compact panels. Mobile uses an accessible dismissible navigation drawer.

Tokens: berry `oklch(56% .190 356)`, berry strong `oklch(42% .145 356)`, warm canvas `oklch(96.5% .008 28)`, near-white surface `oklch(99% .004 28)`, ink `oklch(16% .012 350)`, muted `oklch(43% .016 350)`, border `oklch(88% .012 28)`. Body Be Vietnam Pro; public display Fraunces; data IBM Plex Mono. Display type remains roman. Body fallback system-ui. A 4px spacing scale, 10px controls, 14px cards, subtle borders/shadows. All new colour/font declarations consume named tokens in app.css.

Navigation reflects the selected workspace, never a union of unrelated role grants. Customer primary tasks are appointments/profile/benefits/privacy; owner manages one workspace; reception manages its branch; staff manages assigned appointments; admin manages platform tasks. Benefit tools are grouped into secondary navigation. Product copy avoids framework, scope, SQL, snapshot and server implementation jargon.

Booking follows five dependent steps: ordered service cart and variants, a common provider or AUTO, available day/time, contact, review/confirmation. Upstream changes invalidate downstream selections and stale requests; submission remains authoritative and idempotent. Existing policy, quota, money and tenant constraints remain in domain services. Calendar offers day/week views over scoped real appointments; assigned staff events use their own service windows.

All pages share form controls, action hierarchy, status labels, validation summary, empty/loading/error/success states. Tables scroll within their panel; page overflow must not be hidden by clipping. Verify 320/375/414/768 plus desktop. Focus indicators, escape/backdrop dismissal and reduced motion are required. No fabricated reviews, metrics, availability or salon photographs. Reference editorial assets can be copied locally and labelled as illustrations where appropriate.

Implementation files: shared layout/components, app.css, shell.js, home/marketplace/auth/app views and root dashboard/navigation classes; booking worker owns booking views/app.js/controller; module worker owns newer module views/modules.js. Original source is read-only.
