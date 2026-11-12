<?php

use App\Services\GlowMeImport\ImportContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$snapshot = json_decode(file_get_contents(storage_path('app/private/glowme-import/source.json')), true, 512, JSON_THROW_ON_ERROR);
$context = new ImportContext($snapshot);
$report = ['archive_tables' => [], 'native_checks' => [], 'foreign_key_errors' => [], 'media' => ['verified' => 0, 'missing_or_corrupt' => 0]];
foreach ($snapshot['tables'] as $table => $data) {
    $rows = DB::table('glowme_import_rows')->where('source_table', $table)->orderBy('row_number')->get();
    $valid = $rows->count() === count($data['rawRows']);
    foreach ($rows as $index => $row) {
        $valid = $valid && hash_equals(hash('sha256', $data['rawRows'][$index]), $row->sha256) && hash_equals($row->sha256, hash('sha256', $row->payload));
    }
    $report['archive_tables'][$table] = ['rows' => $rows->count(), 'exact' => $valid];
}
$checks = ['users' => ['users', ['password_hash' => 'password', 'email' => 'email']], 'bookings' => ['bookings', ['total_estimated_amount' => 'total_amount', 'booking_code' => 'booking_code', 'status' => 'status']],
    'wallets' => ['wallets', ['available_vnd' => 'available_vnd', 'held_vnd' => 'held_vnd']], 'booking_deposits' => ['booking_deposits', ['amount_vnd' => 'amount_vnd', 'remaining_vnd' => 'remaining_vnd', 'status' => 'status']],
    'deposit_refunds' => ['deposit_refunds', ['amount_vnd' => 'amount_vnd', 'status' => 'status']], 'business_payables' => ['business_payables', ['amount_vnd' => 'amount_vnd']],
    'business_withdrawals' => ['business_withdrawals', ['amount_vnd' => 'amount_vnd', 'status' => 'status']], 'wallet_transfers' => ['wallet_transfers', ['amount_vnd' => 'amount_vnd', 'kind' => 'kind']],
    'chat_messages' => ['chat_messages', ['body' => 'body', 'sequence' => 'sequence']]];
foreach ($checks as $source => [$target, $fields]) {
    $errors = 0;
    foreach ($context->source($source) as $row) {
        $actual = (array) DB::table($target)->where('id', $context->id($source, $row['id']))->first();
        if (! $actual) {
            $errors++;

            continue;
        }
        foreach ($fields as $from => $to) {
            // DECIMAL strings may differ in scale only; never compare money through floats.
            $expectedValue = (string) ($row[$from] ?? '');
            $actualValue = (string) ($actual[$to] ?? '');
            if (preg_match('/^-?\d+(?:\.\d+)?$/D', $expectedValue) && preg_match('/^-?\d+(?:\.\d+)?$/D', $actualValue)) {
                if (bccomp($expectedValue, $actualValue, 8) !== 0) {
                    $errors++;
                }
            } elseif ($expectedValue !== $actualValue) {
                $errors++;
            }
        }
    }
    $report['native_checks'][$target] = ['expected' => count($context->source($source)), 'actual' => DB::table($target)->count(), 'field_errors' => $errors];
}
$constraints = [];
foreach (DB::select('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION') as $key) {
    $constraints[$key->TABLE_NAME.'.'.$key->CONSTRAINT_NAME][] = $key;
}
foreach ($constraints as $name => $keys) {
    $first = $keys[0];
    $on = [];
    $nonnull = [];
    foreach ($keys as $key) {
        $on[] = 'c.`'.$key->COLUMN_NAME.'` = p.`'.$key->REFERENCED_COLUMN_NAME.'`';
        $nonnull[] = 'c.`'.$key->COLUMN_NAME.'` IS NOT NULL';
    }
    $query = 'SELECT COUNT(*) AS n FROM `'.$first->TABLE_NAME.'` c LEFT JOIN `'.$first->REFERENCED_TABLE_NAME.'` p ON '.implode(' AND ', $on).' WHERE '.implode(' AND ', $nonnull).' AND p.`'.$first->REFERENCED_COLUMN_NAME.'` IS NULL';
    $count = (int) DB::selectOne($query)->n;
    if ($count) {
        $report['foreign_key_errors'][$name] = $count;
    }
}
foreach (DB::table('media_files')->where('path', 'like', 'glowme/%')->cursor() as $media) {
    $filename = storage_path('app/private/'.$media->path);
    $valid = is_file($filename) && filesize($filename) === (int) $media->file_size && hash_equals((string) $media->sha256, hash_file('sha256', $filename));
    $report['media'][$valid ? 'verified' : 'missing_or_corrupt']++;
}
$report['passed'] = ! array_filter($report['archive_tables'], fn ($row) => ! $row['exact']) && ! array_filter($report['native_checks'], fn ($row) => $row['expected'] !== $row['actual'] || $row['field_errors']) && ! $report['foreign_key_errors'] && ! $report['media']['missing_or_corrupt'];
file_put_contents(storage_path('app/private/glowme-import/verification.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['passed' => $report['passed'], 'archive_tables' => count($report['archive_tables']), 'native_checks' => $report['native_checks'], 'foreign_key_errors' => $report['foreign_key_errors'], 'media' => $report['media']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['passed'] ? 0 : 1);
