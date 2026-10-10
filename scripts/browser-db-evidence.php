<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = __DIR__.'/../docs/course/evidence/2026-10-07';
$results = json_decode(file_get_contents($directory.'/browser-results.json'), true, 512, JSON_THROW_ON_ERROR);
$created = array_values(array_filter($results['results'], fn ($result) => isset($result['operationalBooking'])));
if (count($created) !== 1) throw new RuntimeException('Expected one real browser-created operational booking.');
$booking = App\Models\Booking::with(['services', 'pricingSnapshot', 'statusHistory', 'contact'])->findOrFail($created[0]['operationalBooking']['booking_id']);
if ($booking->customer_profile_id !== null || $booking->source !== 'PHONE' || $booking->contact?->full_name !== 'Browser acceptance customer') throw new RuntimeException('Operational contact/account separation failed.');
if ($booking->services->count() !== 1 || $booking->pricingSnapshot?->final_amount !== $booking->total_amount || $booking->statusHistory->first()?->to_status !== 'PENDING') throw new RuntimeException('Booking snapshots/history failed.');
$item = $booking->services->first();
if (!$item->service_name_snapshot || !$item->staff_profile_id || $item->revision !== 1) throw new RuntimeException('Service snapshot incomplete.');
$data = ['booking' => $booking->only(['id', 'booking_code', 'customer_profile_id', 'source', 'status', 'total_amount', 'currency']),
    'contact' => $booking->contact->only(['full_name', 'phone', 'email']),
    'service' => $item->only(['id', 'service_name_snapshot', 'variant_name_snapshot', 'price_at_booking', 'duration_minutes', 'staff_profile_id', 'revision', 'item_start_at', 'item_end_at']),
    'pricing_snapshot' => $booking->pricingSnapshot->only(['subtotal_amount', 'discount_amount', 'final_amount', 'source_snapshot']),
    'history' => $booking->statusHistory->map(fn ($row) => $row->only(['from_status', 'to_status', 'reason']))->all()];
file_put_contents($directory.'/operational-booking-db.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo "PASS: operational booking contacts, item/pricing snapshots and status history verified from MySQL\n";
