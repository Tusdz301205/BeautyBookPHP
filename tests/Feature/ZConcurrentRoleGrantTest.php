<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Business;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ZConcurrentRoleGrantTest extends TestCase
{
    public function test_concurrent_role_grants_from_multiple_processes_create_exactly_one_assignment(): void
    {
        Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $user = User::create([
            'name' => 'Concurrent Owner',
            'email' => 'concurrent-owner@beautybook.local',
            'password' => 'BeautyBook123!',
            'account_kind' => AccountKind::Operational,
        ]);

        $runDirectory = sys_get_temp_dir().'/beautybook-role-grant-'.bin2hex(random_bytes(8));
        mkdir($runDirectory, 0777, true);
        $worker = $runDirectory.'/worker.php';
        $gate = $runDirectory.'/start';
        $workerCount = 5;
        $readyFiles = [];
        $processes = [];

        file_put_contents($worker, <<<'PHP'
<?php

use App\Enums\RoleName;
use App\Models\Business;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Contracts\Console\Kernel;

$root = $argv[1];
$userId = (int) $argv[2];
$businessId = (int) $argv[3];
$ready = $argv[4];
$gate = $argv[5];

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::findOrFail($userId);
$business = Business::findOrFail($businessId);

file_put_contents($ready, 'ready');

while (! is_file($gate)) {
    usleep(1000);
}

$assignment = $app->make(RoleAssignmentService::class)
    ->grant($user, RoleName::BusinessOwner, $business);

echo $assignment->id;
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
                    (string) $user->id,
                    (string) $business->id,
                    $ready,
                    $gate,
                ], base_path(), $environment, null, 30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;

            do {
                $readyCount = count(array_filter($readyFiles, is_file(...)));

                if ($readyCount === $workerCount) {
                    break;
                }

                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->assertSame(
                $workerCount,
                count(array_filter($readyFiles, is_file(...))),
                'All worker processes must reach the barrier before the grant race begins. '.json_encode(array_map(fn ($process, $index) => ['worker' => $index, 'ready' => is_file($readyFiles[$index]), 'running' => $process->isRunning(), 'exitCode' => $process->getExitCode(), 'stderr' => $process->getErrorOutput(), 'stdout' => $process->getOutput()], $processes, array_keys($processes)), JSON_UNESCAPED_SLASHES),
            );

            touch($gate);

            $assignmentIds = [];

            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $this->assertSame(
                    0,
                    $exitCode,
                    trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                );
                $assignmentIds[] = trim($process->getOutput());
            }

            $this->assertCount(1, array_unique($assignmentIds));
            $this->assertSame(1, RoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('role_name', RoleName::BusinessOwner->value)
                ->where('scope_key', 'business:'.$business->id)
                ->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            RoleAssignment::query()->where('user_id', $user->id)->delete();
            User::query()->whereKey($user->id)->delete();

            foreach ($readyFiles as $ready) {
                @unlink($ready);
            }

            @unlink($gate);
            @unlink($worker);
            @rmdir($runDirectory);
        }
    }
}
