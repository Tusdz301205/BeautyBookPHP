<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ZZConcurrentBookingRaceTest extends TestCase
{
    public function test_two_five_and_ten_parallel_commands_have_exactly_one_winner_per_slot(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $customerProfile = $customer->customerProfile()->firstOrFail();

        foreach ([2, 5, 10] as $offset => $workerCount) {
            $start = $this->openStart(10 + ($offset * 2));
            $winners = $this->runRace(
                $workerCount,
                $branch->id,
                $offering->id,
                $staff->id,
                $customer->id,
                $customerProfile->id,
                $start,
            );

            $this->assertSame(1, $winners, $workerCount.' parallel commands must produce exactly one winner.');
        }

        $this->assertSame(3, Booking::count());
    }

    private function runRace(
        int $workerCount,
        int $branchId,
        int $offeringId,
        int $staffId,
        int $userId,
        int $customerProfileId,
        string $start,
    ): int {
        $runDirectory = sys_get_temp_dir().'/beautybook-booking-race-'.bin2hex(random_bytes(8));
        mkdir($runDirectory, 0777, true);
        $worker = $runDirectory.'/worker.php';
        $gate = $runDirectory.'/start';
        $readyFiles = [];
        $processes = [];

        file_put_contents($worker, <<<'PHP'
<?php

use App\Models\Branch;
use App\Services\Booking\CreateBookingService;
use Illuminate\Contracts\Console\Kernel;

$root = $argv[1];
$branchId = (int) $argv[2];
$offeringId = (int) $argv[3];
$staffId = (int) $argv[4];
$userId = (int) $argv[5];
$customerProfileId = (int) $argv[6];
$start = $argv[7];
$idempotencyKey = $argv[8];
$ready = $argv[9];
$gate = $argv[10];

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$branch = Branch::findOrFail($branchId);

file_put_contents($ready, 'ready');
while (! is_file($gate)) {
    usleep(1000);
}

try {
    $result = $app->make(CreateBookingService::class)->create($branch, [
        'actor_user_id' => $userId,
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
        'start_at' => $start,
        'idempotency_key' => $idempotencyKey,
    ]);

    echo 'WIN:'.$result['booking']->id;
} catch (Throwable $exception) {
    echo 'LOSE:'.get_class($exception).':'.$exception->getMessage();
}
PHP);

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
            for ($index = 0; $index < $workerCount; $index++) {
                $ready = $runDirectory.'/ready-'.$index;
                $readyFiles[] = $ready;
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    base_path(),
                    (string) $branchId,
                    (string) $offeringId,
                    (string) $staffId,
                    (string) $userId,
                    (string) $customerProfileId,
                    $start,
                    'race-'.$workerCount.'-'.$index.'-'.bin2hex(random_bytes(4)),
                    $ready,
                    $gate,
                ], base_path(), $environment, null, 45);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            do {
                $readyCount = count(array_filter($readyFiles, is_file(...)));
                if ($readyCount === $workerCount) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->assertSame($workerCount, count(array_filter($readyFiles, is_file(...))));
            touch($gate);
            $winners = 0;

            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $this->assertSame(0, $exitCode, trim($process->getErrorOutput()) ?: trim($process->getOutput()));
                if (str_starts_with(trim($process->getOutput()), 'WIN:')) {
                    $winners++;
                }
            }

            return $winners;
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

    private function openStart(int $hour): string
    {
        $time = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime($hour, 0);
        while ($time->dayOfWeek === 0) {
            $time = $time->addDay();
        }

        return $time->format('Y-m-d\TH:i');
    }
}
