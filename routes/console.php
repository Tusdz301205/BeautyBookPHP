<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('beautybook:maintain', function (\App\Services\Booking\BookingMaintenanceService $maintenance) {
    $this->info('Expired holds: '.$maintenance->expire());
    $this->info('Delivered inbox records: '.$maintenance->drainOutbox());
    $this->info('Cleaned privacy packages: '.app(\App\Services\Privacy\PrivacyService::class)->cleanup());
    $this->info('Expired waitlist offers: '.app(\App\Services\Operations\WaitlistService::class)->expireOffers());
    $this->info('Executed ownership transfers: '.app(\App\Services\Ownership\OwnershipService::class)->executeDue());
    $this->info('Booking reminders queued: '.\App\Services\Push\PushService::queueReminders());
    $push = app(\App\Services\Push\PushService::class)->runBatch();
    $this->info('Push: sent '.$push['sent'].', retrying '.$push['retrying'].', failed '.$push['failed'].', suppressed '.$push['suppressed']);
    $this->info('Expired Live-lab top-ups: '.\App\Services\Wallet\LiveWalletInbox::expireDue());
    $this->info('SePay receipts still failing: '.app(\App\Services\Finance\SepayWebhookService::class)->recover());
    $this->info('Resumed approved withdrawals: '.app(\App\Services\Payout\WithdrawalService::class)->recover());
    $this->info('Escalated impact cases: '.app(\App\Services\Operations\ImpactService::class)->escalateOverdue());
})->purpose('Expire booking holds/change requests and complete database inbox outbox delivery');

Artisan::command('beautybook:reconcile-store-receipts', function () {
    $this->info('Store receipts turned into verified payments: '.app(\App\Services\Finance\PaymentService::class)->reconcileLegacyStoreReceipts());
})->purpose('Turn store receipts stamped before the unified payment rule into the verified payments they stood for (safe to repeat)');

Artisan::command('beautybook:payment-inconsistencies', function () {
    $found = app(\App\Services\Finance\PaymentService::class)->inconsistencies();
    foreach ($found as $row) {
        $this->line('booking '.$row['booking_id'].' ('.$row['booking_code'].'): deposit '.$row['deposit_status'].', recorded '.number_format($row['recorded_cents'] / 100, 0, ',', '.').' VND, pending '.number_format($row['pending_cents'] / 100, 0, ',', '.').' VND');
    }
    $this->info('Bookings whose recorded money and deposit disagree: '.count($found));
})->purpose('List bookings with money recorded while the deposit awaits, expired or was voided (read only; nothing is changed)');

Artisan::command('beautybook:push-retry {notification?}', function (?string $notification = null) {
    $this->info('Requeued failed push deliveries: '.app(\App\Services\Push\PushService::class)->requeueFailed($notification ? (int) $notification : null));
})->purpose('Send failed device pushes again');

Schedule::command('beautybook:maintain')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
