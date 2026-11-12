<?php

use App\Services\GlowMeImport\ImportContext;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'beautybook_php' || ! $app->environment('local')) throw new RuntimeException('Expected local PHP database.');
$context = new ImportContext(json_decode(file_get_contents(storage_path('app/private/glowme-import/source.json')), true, 512, JSON_THROW_ON_ERROR));
$changed = 0;
$documents = 0;
DB::transaction(function () use ($context, &$changed, &$documents) {
    foreach ($context->source('branches') as $row) {
        if ($row['district_id'] || ! $row['ward_id']) continue;
        $id = $context->id('branches', $row['id']);
        $metadata = json_decode(DB::table('beautybook_branch_settings')->where('branch_id', $id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $metadata['districtId'] = 'glowme-ward-'.$context->id('wards', $row['ward_id']);
        $metadata['addressLevel'] = 'WARD';
        DB::table('beautybook_branch_settings')->where('branch_id', $id)->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $changed++;
    }
    foreach ($context->source('branch_documents') as $row) {
        $localId = count($context->source('business_documents')) + $context->id('branch_documents', $row['id']);
        $target = DB::table('beautybook_documents')->where('id', $localId)->first();
        if (! $target || (int) $target->branch_id !== $context->id('branches', $row['branch_id'])) throw new RuntimeException('Document ID mapping does not match native branch document.');
        DB::table('glowme_import_ids')->where('source_table', 'public.branch_documents')->where('source_id', $row['id'])->update(['local_id' => $localId]);
        $documents++;
    }
});
echo json_encode(['branches_using_source_ward_location' => $changed, 'branch_document_mappings_verified' => $documents]).PHP_EOL;
