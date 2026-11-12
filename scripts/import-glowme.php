<?php

use App\Services\GlowMeImport\BookingImport;
use App\Services\GlowMeImport\CoreImport;
use App\Services\GlowMeImport\FinanceImport;
use App\Services\GlowMeImport\ImportContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array('--replace', $argv, true) || ! $app->environment('local') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'beautybook_php' || config('database.connections.mysql.host') !== 'mysql') {
    throw new RuntimeException('Replacement requires --replace and the local mysql/beautybook_php database.');
}
foreach ([CoreImport::class, BookingImport::class, FinanceImport::class] as $class) {
    if (! class_exists($class)) {
        throw new RuntimeException('Importer module not ready: '.$class);
    }
}
$path = storage_path('app/private/glowme-import/source.json');
$manifest = json_decode(file_get_contents(dirname($path).'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (! hash_equals($manifest['sha256'], hash_file('sha256', $path))) {
    throw new RuntimeException('Source snapshot checksum mismatch.');
}
$snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$context = new ImportContext($snapshot);
DB::disableQueryLog();
DB::beginTransaction();
try {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach (DB::select('SHOW TABLES') as $table) {
        $name = array_values((array) $table)[0];
        if ($name !== 'migrations') {
            DB::table($name)->delete();
        }
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    $context->archive();
    (new CoreImport)->run($context);
    (new BookingImport)->run($context);
    (new FinanceImport)->run($context);
    // Imported history is not a stream of new user events.
    DB::table('beautybook_realtime_events')->delete();
    $archived = DB::table('glowme_import_rows')->count();
    $expected = array_sum($manifest['tables']);
    if ($archived !== $expected) {
        throw new RuntimeException('Archived row count mismatch.');
    }
    $report = ['source_tables' => count($snapshot['tables']), 'source_rows' => $expected, 'archived_rows' => $archived, 'native_rows' => $context->counts, 'notes' => array_values($context->notes)];
    DB::table('glowme_import_runs')->insert(['source_database' => $manifest['database'], 'source_sha256' => $manifest['sha256'], 'source_exported_at' => (new DateTimeImmutable($manifest['exportedAt']))->format('Y-m-d H:i:s'),
        'status' => 'COMPLETE', 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    DB::commit();
    file_put_contents(dirname($path).'/import-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    DB::rollBack();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    $message = $error instanceof QueryException
        ? 'database constraint/statement error (SQLSTATE '.($error->errorInfo[0] ?? 'unknown').', code '.($error->errorInfo[1] ?? 'unknown').')'
        : $error->getMessage();
    fwrite(STDERR, 'Import rolled back: '.$message.PHP_EOL);
    exit(1);
}
