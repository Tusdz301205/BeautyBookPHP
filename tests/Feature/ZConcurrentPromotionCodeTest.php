<?php

namespace Tests\Feature;

use App\Models\Business;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ZConcurrentPromotionCodeTest extends TestCase
{
    public static function overlappingScopes(): array
    {
        return ['two platform types' => [false], 'platform and tenant' => [true]];
    }

    #[DataProvider('overlappingScopes')]
    public function test_overlapping_promotion_and_voucher_creates_cannot_both_claim_one_code(bool $tenantVoucher): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $code = 'RACE-'.strtoupper(bin2hex(random_bytes(6)));
        $business = $tenantVoucher ? Business::create(['name' => 'Concurrency fixture', 'slug' => strtolower($code), 'status' => 'DRAFT']) : null;
        $directory = sys_get_temp_dir().'/bb-code-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = $directory.'/worker.php';
        $gate = $directory.'/start';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$business = $argv[4] === '0' ? null : App\Models\Business::findOrFail((int) $argv[4]);
file_put_contents($argv[6], 'ready');
while (!is_file($argv[5])) usleep(1000);
try {
    app(App\Services\Promotions\PromotionManagementService::class)->save($business, $argv[2], ['name'=>'Concurrent code', 'code'=>$argv[3], 'discount_type'=>'PERCENTAGE', 'discount_value'=>'10.00', 'start_date'=>now()->subDay()->toISOString(), 'end_date'=>now()->addDay()->toISOString()]);
    echo 'CREATED';
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    echo $error->getStatusCode() === 409 ? 'CONFLICT' : 'ERROR';
} catch (Throwable $error) { echo 'ERROR'; }
PHP);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_USERNAME' => (string) config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'), 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $processes = [];
        try {
            foreach (['promotion', 'voucher'] as $index => $type) {
                $process = new Process([PHP_BINARY, $worker, base_path(), $type, $code, (string) ($type === 'voucher' ? ($business?->id ?? 0) : 0), $gate, $directory.'/ready-'.$index], base_path(), $environment, null, 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            file_put_contents($gate, 'go');
            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful());
                $outputs[] = trim($process->getOutput());
            }
            sort($outputs);
            $this->assertSame(['CONFLICT', 'CREATED'], $outputs);
            $this->assertSame(1, DB::table('promotions')->where('code', $code)->count() + DB::table('vouchers')->where('code', $code)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::table('promotions')->where('code', $code)->delete();
            DB::table('vouchers')->where('code', $code)->delete();
            if ($business) {
                DB::table('businesses')->where('id', $business->id)->delete();
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
