<?php

namespace Tests\Feature;

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\BookingViolationEvent;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerBookingPolicy;
use App\Models\IdempotencyRecord;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Booking\BookingPolicyService;
use App\Services\Booking\CreateBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ZZConcurrentBookingLifecycleRaceTest extends TestCase
{
    public function test_booking_lifecycle_policy_and_change_request_races_remain_consistent(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->assertCancelVersusRescheduleRace();
        $this->assertNoShowVersusCheckInAndLateRequestRace();
        $this->assertPolicyViolationVersusSelfBookingRace();
    }

    private function assertCancelVersusRescheduleRace(): void
    {
        $booking = $this->createCustomerBooking('race-cancel-reschedule-base', 2, 10);
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
        $newStart = $oldStart->addHours(4);
        $newEnd = $oldEnd->addHours(4);

        $changeRequest = AppointmentChangeRequest::query()->create([
            'booking_id' => $booking->id,
            'requested_by_user_id' => $customer->id,
            'requested_by_type' => 'CUSTOMER',
            'request_type' => 'RESCHEDULE',
            'proposed_start_at' => $newStart,
            'proposed_end_at' => $newEnd,
            'reason' => 'Concurrent reschedule',
            'status' => 'PENDING',
            'expires_at' => now()->addDays(7),
        ]);

        $worker = <<<'PHP'
<?php

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\AppointmentChangeRequestService;
use App\Services\Booking\BookingLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$root = $argv[1];
$action = $argv[2];
$bookingId = (int) $argv[3];
$changeRequestId = (int) $argv[4];
$ownerId = (int) $argv[5];
$assignmentId = (int) $argv[6];
$ready = $argv[count($argv) - 2];
$gate = $argv[count($argv) - 1];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$owner = User::findOrFail($ownerId);
$request = Request::create('/race', 'POST');
$request->setLaravelSession($app->make('session')->driver());
$request->setUserResolver(fn () => $owner);
$request->session()->put('workspace_assignment_id', $assignmentId);

file_put_contents($ready, 'ready');
while (! is_file($gate)) {
    usleep(1000);
}

try {
    $booking = Booking::findOrFail($bookingId);
    if ($action === 'cancel') {
        $result = $app->make(BookingLifecycleService::class)->transition($request, $booking, 'CANCELLED');
        echo 'OK:CANCEL:'.$result->status;
    } else {
        $changeRequest = AppointmentChangeRequest::findOrFail($changeRequestId);
        $result = $app->make(AppointmentChangeRequestService::class)->approve($request, $booking, $changeRequest, 'Concurrent approval');
        echo 'OK:RESCHEDULE:'.$result->status;
    }
} catch (Throwable $exception) {
    echo 'ERR:'.$action.':'.get_class($exception).':'.$exception->getMessage();
}
PHP;

        $outputs = $this->runProcesses($worker, [
            ['cancel', (string) $booking->id, (string) $changeRequest->id, (string) $owner->id, (string) $assignment->id],
            ['reschedule', (string) $booking->id, (string) $changeRequest->id, (string) $owner->id, (string) $assignment->id],
        ]);

        $freshBooking = $booking->fresh();
        $freshRequest = $changeRequest->fresh();
        $this->assertStringStartsWith('OK:CANCEL:CANCELLED', $outputs[0]);
        $this->assertSame('CANCELLED', $freshBooking->status);
        $this->assertContains($freshRequest->status, ['PENDING', 'APPROVED']);
        $this->assertSame(1, BookingStatusHistory::query()
            ->where('booking_id', $booking->id)
            ->where('to_status', 'CANCELLED')
            ->count());
        $this->assertSame('CANCELLED', BookingService::query()->where('booking_id', $booking->id)->firstOrFail()->status);

        $actualStart = CarbonImmutable::parse($freshBooking->getRawOriginal('appointment_start_at'), 'UTC');
        if ($freshRequest->status === 'APPROVED') {
            $this->assertTrue($actualStart->equalTo($newStart));
            $this->assertStringStartsWith('OK:RESCHEDULE:APPROVED', $outputs[1]);
        } else {
            $this->assertTrue($actualStart->equalTo($oldStart));
            $this->assertStringStartsWith('ERR:reschedule:', $outputs[1]);
        }

        $this->assertNotificationOutboxParity($booking->id);
    }

    private function assertNoShowVersusCheckInAndLateRequestRace(): void
    {
        $booking = $this->createCustomerBooking('race-no-show-checkin-base', 4, 10);
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $receptionistAssignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        $worker = <<<'PHP'
<?php

use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\AppointmentChangeRequestService;
use App\Services\Booking\BookingLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$root = $argv[1];
$action = $argv[2];
$bookingId = (int) $argv[3];
$customerId = (int) $argv[4];
$ownerId = (int) $argv[5];
$ownerAssignmentId = (int) $argv[6];
$receptionistId = (int) $argv[7];
$receptionistAssignmentId = (int) $argv[8];
$ready = $argv[count($argv) - 2];
$gate = $argv[count($argv) - 1];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$start = CarbonImmutable::parse($argv[9], 'UTC');

if ($action === 'late-request') {
    $actor = User::findOrFail($customerId);
    $at = $start->subHours(3)->subMinutes(59);
    $assignmentId = null;
} elseif ($action === 'check-in') {
    $actor = User::findOrFail($receptionistId);
    $at = $start->addMinutes(16);
    $assignmentId = $receptionistAssignmentId;
} else {
    $actor = User::findOrFail($ownerId);
    $at = $start->addMinutes(16);
    $assignmentId = $ownerAssignmentId;
}

CarbonImmutable::setTestNow($at);
$request = Request::create('/race', 'POST');
$request->setLaravelSession($app->make('session')->driver());
$request->setUserResolver(fn () => $actor);
if ($assignmentId !== null) {
    $request->session()->put('workspace_assignment_id', $assignmentId);
}

file_put_contents($ready, 'ready');
while (! is_file($gate)) {
    usleep(1000);
}

try {
    $booking = Booking::findOrFail($bookingId);
    if ($action === 'late-request') {
        $result = $app->make(AppointmentChangeRequestService::class)->create($request, $booking, [
            'request_type' => 'CANCEL',
            'reason' => 'Concurrent late cancellation',
        ], $at);
        echo 'OK:LATE_REQUEST:'.$result->status;
    } elseif ($action === 'check-in') {
        $result = $app->make(BookingLifecycleService::class)->transition($request, $booking, 'CHECKED_IN', null, false, $at);
        echo 'OK:CHECK_IN:'.$result->status;
    } else {
        $result = $app->make(BookingLifecycleService::class)->transition($request, $booking, 'NO_SHOW', null, true, $at);
        echo 'OK:NO_SHOW:'.$result->status;
    }
} catch (Throwable $exception) {
    echo 'ERR:'.$action.':'.get_class($exception).':'.$exception->getMessage();
}
PHP;

        $common = [
            (string) $booking->id,
            (string) $customer->id,
            (string) $owner->id,
            (string) $ownerAssignment->id,
            (string) $receptionist->id,
            (string) $receptionistAssignment->id,
            $start->toIso8601String(),
        ];
        $outputs = $this->runProcesses($worker, [
            ['no-show', ...$common],
            ['check-in', ...$common],
            ['late-request', ...$common],
        ]);

        $freshBooking = $booking->fresh();
        $this->assertContains($freshBooking->status, ['NO_SHOW', 'CHECKED_IN']);
        $transitionWins = count(array_filter([$outputs[0], $outputs[1]], fn (string $output) => str_starts_with($output, 'OK:')));
        $this->assertSame(1, $transitionWins, implode("\n", $outputs));
        $this->assertSame(1, BookingStatusHistory::query()
            ->where('booking_id', $booking->id)
            ->whereIn('to_status', ['NO_SHOW', 'CHECKED_IN'])
            ->count());

        $requests = AppointmentChangeRequest::query()->where('booking_id', $booking->id)->get();
        $events = BookingViolationEvent::query()->where('booking_id', $booking->id)->whereNull('voided_at')->get();
        $this->assertLessThanOrEqual(1, $requests->count());
        $this->assertLessThanOrEqual(1, $events->count());

        if ($freshBooking->status === 'NO_SHOW') {
            $this->assertCount(0, $requests);
            $this->assertCount(1, $events);
            $this->assertSame('NO_SHOW', $events->first()->kind);
            $this->assertStringStartsWith('OK:NO_SHOW:NO_SHOW', $outputs[0]);
        } elseif ($requests->isNotEmpty()) {
            $this->assertCount(1, $events);
            $this->assertSame('LATE_CANCELLATION', $events->first()->kind);
            $this->assertStringStartsWith('OK:LATE_REQUEST:PENDING', $outputs[2]);
        } else {
            $this->assertCount(0, $events);
        }

        $this->assertNotificationOutboxParity($booking->id);
    }

    private function assertPolicyViolationVersusSelfBookingRace(): void
    {
        $policyCustomerEmail = 'foundation@beautybook.local';
        $firstPenalty = $this->createCustomerBooking('race-policy-first-penalty', 6, 10, $policyCustomerEmail);
        $secondPenalty = $this->createCustomerBooking('race-policy-second-penalty', 6, 14, $policyCustomerEmail);
        $customer = User::where('email', $policyCustomerEmail)->firstOrFail();
        $profile = $customer->customerProfile()->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $firstStart = CarbonImmutable::parse($firstPenalty->getRawOriginal('appointment_start_at'), 'UTC');
        $secondStart = CarbonImmutable::parse($secondPenalty->getRawOriginal('appointment_start_at'), 'UTC');
        $firstEventAt = $firstStart->addMinutes(16);
        $raceAt = $secondStart->addMinutes(16);

        DB::transaction(function () use ($firstPenalty, $owner, $firstEventAt) {
            app(BookingPolicyService::class)->recordViolation($firstPenalty, 'NO_SHOW', $owner->id, null, $firstEventAt);
        });

        $before = app(BookingPolicyService::class)->read($profile->id, $branch->business_id, $raceAt->subSecond());
        $this->assertSame(2, $before['score']);
        $this->assertTrue($before['self_booking_allowed']);

        $targetStart = $this->openStart(8, 10);
        $idempotencyKey = 'race-policy-self-book';
        $bookingCountBefore = Booking::count();

        $worker = <<<'PHP'
<?php

use App\Models\Booking;
use App\Models\Branch;
use App\Services\Booking\BookingPolicyService;
use App\Services\Booking\CreateBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = $argv[1];
$action = $argv[2];
$penaltyBookingId = (int) $argv[3];
$branchId = (int) $argv[4];
$offeringId = (int) $argv[5];
$staffId = (int) $argv[6];
$customerUserId = (int) $argv[7];
$customerProfileId = (int) $argv[8];
$ownerUserId = (int) $argv[9];
$targetStart = $argv[11];
$idempotencyKey = $argv[12];
$ready = $argv[count($argv) - 2];
$gate = $argv[count($argv) - 1];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$raceAt = CarbonImmutable::parse($argv[10], 'UTC');
CarbonImmutable::setTestNow($raceAt);

file_put_contents($ready, 'ready');
while (! is_file($gate)) {
    usleep(1000);
}

try {
    if ($action === 'event') {
        $booking = Booking::findOrFail($penaltyBookingId);
        $event = DB::transaction(fn () => $app->make(BookingPolicyService::class)
            ->recordViolation($booking, 'NO_SHOW', $ownerUserId, null, $raceAt));
        echo 'OK:EVENT:'.$event->id;
    } else {
        $branch = Branch::findOrFail($branchId);
        $result = $app->make(CreateBookingService::class)->create($branch, [
            'actor_user_id' => $customerUserId,
            'kind' => 'CUSTOMER',
            'role_name' => 'CUSTOMER',
            'workspace_key' => 'customer:'.$customerProfileId,
            'workspace_assignment_id' => null,
            'business_id' => null,
            'branch_id' => null,
            'customer_profile_id' => $customerProfileId,
        ], [
            'branch_service_offering_id' => $offeringId,
            'service_variant_id' => null,
            'staff_profile_id' => $staffId,
            'start_at' => $targetStart,
            'idempotency_key' => $idempotencyKey,
            'violation_acknowledged' => false,
        ]);
        echo 'OK:BOOKING:'.$result['booking']->id;
    }
} catch (Throwable $exception) {
    echo 'ERR:'.$action.':'.get_class($exception).':'.$exception->getMessage();
}
PHP;

        $common = [
            (string) $secondPenalty->id,
            (string) $branch->id,
            (string) $offering->id,
            (string) $staff->id,
            (string) $customer->id,
            (string) $profile->id,
            (string) $owner->id,
            $raceAt->toIso8601String(),
            $targetStart,
            $idempotencyKey,
        ];
        $outputs = $this->runProcesses($worker, [
            ['event', ...$common],
            ['booking', ...$common],
        ]);

        $this->assertStringStartsWith('OK:EVENT:', $outputs[0]);
        $after = app(BookingPolicyService::class)->read($profile->id, $branch->business_id, $raceAt->addSecond());
        $this->assertSame(4, $after['score']);
        $this->assertFalse($after['self_booking_allowed']);
        $this->assertTrue($after['restriction']['active']);
        $this->assertSame(2, BookingViolationEvent::query()
            ->where('customer_profile_id', $profile->id)
            ->where('business_id', $branch->business_id)
            ->whereNull('voided_at')
            ->count());

        $bookingDelta = Booking::count() - $bookingCountBefore;
        $this->assertContains($bookingDelta, [0, 1]);
        $idempotency = IdempotencyRecord::query()
            ->where('actor_user_id', $customer->id)
            ->where('workspace_key', 'customer:'.$profile->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (str_starts_with($outputs[1], 'OK:BOOKING:')) {
            $this->assertSame(1, $bookingDelta);
            $this->assertNotNull($idempotency);
            $this->assertSame('COMPLETED', $idempotency->status);
        } else {
            $this->assertSame(0, $bookingDelta);
            $this->assertNull($idempotency);
            $this->assertStringStartsWith('ERR:booking:', $outputs[1]);
        }

        $policyRow = CustomerBookingPolicy::query()
            ->where('customer_profile_id', $profile->id)
            ->where('business_id', $branch->business_id)
            ->firstOrFail();
        $this->assertNotNull($policyRow->triggered_by_violation_event_id);
    }

    private function createCustomerBooking(
        string $idempotencyKey,
        int $dayOffset,
        int $hour,
        string $customerEmail = 'customer@beautybook.local',
    ): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $customer = User::where('email', $customerEmail)->firstOrFail();
        $profile = $customer->customerProfile()->firstOrFail();

        $result = app(CreateBookingService::class)->create($branch, [
            'actor_user_id' => $customer->id,
            'kind' => 'CUSTOMER',
            'role_name' => 'CUSTOMER',
            'workspace_key' => 'customer:'.$profile->id,
            'workspace_assignment_id' => null,
            'business_id' => null,
            'branch_id' => null,
            'customer_profile_id' => $profile->id,
        ], [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $this->openStart($dayOffset, $hour),
            'idempotency_key' => $idempotencyKey,
        ]);

        return $result['booking'];
    }

    private function runProcesses(string $workerSource, array $argumentSets): array
    {
        $runDirectory = sys_get_temp_dir().'/beautybook-lifecycle-race-'.bin2hex(random_bytes(8));
        mkdir($runDirectory, 0777, true);
        $worker = $runDirectory.'/worker.php';
        $gate = $runDirectory.'/start';
        $readyFiles = [];
        $processes = [];
        file_put_contents($worker, $workerSource);

        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        try {
            foreach ($argumentSets as $index => $arguments) {
                $ready = $runDirectory.'/ready-'.$index;
                $readyFiles[] = $ready;
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    base_path(),
                    ...$arguments,
                    $ready,
                    $gate,
                ], base_path(), $environment, null, 60);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            do {
                if (count(array_filter($readyFiles, is_file(...))) === count($argumentSets)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->assertSame(count($argumentSets), count(array_filter($readyFiles, is_file(...))));
            touch($gate);
            $outputs = [];

            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $this->assertSame(0, $exitCode, trim($process->getErrorOutput()) ?: trim($process->getOutput()));
                $outputs[] = trim($process->getOutput());
            }

            return $outputs;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($readyFiles as $ready) {
                @unlink($ready);
            }
            @unlink($gate);
            @unlink($worker);
            @rmdir($runDirectory);
        }
    }

    private function assertNotificationOutboxParity(int $bookingId): void
    {
        $notifications = DB::table('notifications')->where('related_booking_id', $bookingId)->count();
        $outbox = DB::table('notification_outbox')->where('related_booking_id', $bookingId)->count();
        $this->assertSame($notifications, $outbox);
    }

    private function openStart(int $dayOffset, int $hour): string
    {
        $time = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays($dayOffset)->startOfDay()->setTime($hour, 0);
        while ($time->dayOfWeek === 0) {
            $time = $time->addDay();
        }

        return $time->format('Y-m-d\TH:i');
    }
}
