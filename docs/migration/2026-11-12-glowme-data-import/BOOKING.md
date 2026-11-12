# Booking and engagement native mapping

`App\Services\GlowMeImport\BookingImport::run(ImportContext $c): void` uses only
`source`, `id`, `insert`, and `note`. No common contract changes are required.
Run after Core has inserted users, customer profiles, businesses, branches,
services/offerings, variants, staff, categories, combos, and media files. Finance
can run afterward. The importer performs no direct DB calls or delivery actions.
The parent owns the constrained database run and transaction boundary.

All UUID references use the source `@@map` table key. In particular, `services`
IDs identify both native business services and branch service offerings;
`staff_profiles` IDs identify native staff. Recurring series IDs use
`recurring_booking_plans`; result photo IDs use `service_images`. Derived
one-to-one pricing rows and recurring occurrence rows reuse the source booking
ID within their separate target tables. Saved services have a source composite
key and use the target auto-increment surrogate.

| Source | Native projection | Snapshot rows |
| --- | --- | ---: |
| bookings | bookings | 2,731 |
| booking_services | booking_services | 3,082 |
| bookings + booking_services | booking_pricing_snapshots | 2,731 |
| booking_contacts | booking_contacts | 2,731 |
| booking_status_histories | booking_status_histories | 10,328 |
| appointment_change_requests | appointment_change_requests | 59 |
| booking_violation_events | booking_violation_events | 67 |
| customer_booking_policies | customer_booking_policies | 1 |
| recurring_booking_plans | recurring_booking_series | 6 |
| bookings with recurring_plan_id | recurring_booking_occurrences | 43 |
| reviews + review_service_ratings | reviews with service_ratings JSON | 391 |
| review_photos | review_photos | 26 |
| service_images, PROMOTIONAL | service_images | 139 |
| service_images, RESULT | result_photos | 8 |
| promotions + promotion_services + promotion_combos | promotions | 27 |
| customer_saved_services | saved_services | 304 |
| notifications | notifications | 1,230 |

## Prices and lifecycle

Booking totals and quote subtotal/discount/final amounts are copied exactly.
Native service `price_at_booking` is the original gross amount, matching native
invoice allocation behavior. Quote items retain each final allocation, discount,
selection/unit order, names, category, combo, promotion, and historical duration
with remapped native IDs. Original per-line allocation is retained even where
native invoice generation later apportions a header discount differently.

Source has no booking channel or per-service lifecycle. The native channel uses
the schema default `ONLINE_WEB`; completed parents yield completed services,
cancelled/rejected/expired/no-show parents yield cancelled services, and active
parents yield scheduled services. Actual timing remains unknown; no actual
timestamps are invented. Source has no buffers, so occupied windows equal item
windows. Creator is the command actor or the explicitly linked customer user.
Location snapshots map historical `address` to `addressLine`, and district/ward
UUIDs to their deterministic source-table IDs; missing snapshots stay null.

History `occurred_at` becomes native `created_at`. Reasons beyond the native
255-character limit are truncated with an aggregate note. Change request actor
types derive from customer ownership or source operational roles.

Violation evidence must satisfy current native scope, source request, timing,
voiding, and one-active-event constraints. Incompatible events are archived only
and counted in notes; no requests or evidence are synthesized. The native
required policy version is explicitly `glowme-import-v1`, since no source policy
version snapshot exists. Restrictions import only with an imported valid event,
matching scope, and the native 30-day end date.

Recurring ordinals are already zero based. Only observed bookings become
occurrences; missing occurrences are not invented. Series retain frequency,
staff mode, status, local preferred time, anchor date, timezone, staff/combo IDs,
and policy version. A namespaced import idempotency key avoids treating a source
command receipt as a replayable native request.

## Visibility and unsupported semantics

Private reviews become `HIDDEN`; null comments become empty native text.
Pending review photos have null native sort order, as required by the check.
Result photos retain provenance and consent evidence. Private/revoked results
have no native publication timestamp. Deleted images, private/revoked promotional
images, and result images lacking native evidence are archived only with notes.

Promotion `PERCENT` maps to `PERCENTAGE`; `FIXED` maps to `FIXED_AMOUNT`.
Selected targeting is preserved through native offering/combo IDs. The promotion-code
migration adds explicit ALL/SELECTED target modes, retaining source service OR combo
eligibility; existing legacy programs keep their original AND scopes. Codes and
source activation flags are now imported directly. The supplied snapshot contains
ten coded promotions: eight source-active and two source-inactive. Dates remain
unchanged; a source-active program outside its validity window still cannot apply.
The native per-customer quota is set to its unsigned maximum to avoid the native
default one-use limit where the source has no such limit. Historical promotion
IDs and amounts remain in booking quote items; no redemption evidence is invented.

Notification read state and native booking/change-request targets are retained.
No notification outbox, session, token, push device, or push delivery lifecycle is
replayed. Raw records, contact addresses, original timestamps, points, source
command receipts and other source-only fields remain in the parent's
lossless archive. Notes contain only mapping descriptions and counts, no PII.

## Validation performed without a database

- PHP syntax check passed.
- Executed the importer against the complete read-only source JSON using an
  in-memory `ImportContext` test double with lexical deterministic IDs: 23,904
  projected rows, no missing ID mappings.
- Checked pricing arithmetic, gross and final service sums, booking/service time
  windows, booking/service/review scope, contact channels, review photo ordering,
  representative native unique keys, and bounded string lengths: zero failures.
- The importer itself preflights violation/policy evidence and result-photo
  provenance/publication constraints.
- Loading the full JSON exceeded PHP's default 128 MB limit in the test harness;
  the successful in-memory run used `php -d memory_limit=512M`.

No database execution was performed. The in-memory checks do not replace the
parent's actual constrained import against the target migrations.
