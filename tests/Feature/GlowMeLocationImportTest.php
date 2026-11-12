<?php

namespace Tests\Feature;

use App\Services\Beautybook\SourceDistrictCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlowMeLocationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_wards_are_available_when_source_has_no_districts(): void
    {
        DB::table('glowme_import_runs')->insert(['source_database' => 'fixture', 'source_sha256' => str_repeat('a', 64), 'source_exported_at' => now(), 'status' => 'COMPLETE', 'manifest' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['provinces' => ['id' => 'province-id', 'name' => 'Tỉnh nguồn', 'code' => 'TEST'], 'wards' => ['id' => 'ward-id', 'name' => 'Phường nguồn', 'code' => '001', 'province_id' => 'province-id']] as $table => $row) {
            $payload = json_encode($row, JSON_THROW_ON_ERROR);
            DB::table('glowme_import_rows')->insert(['source_table' => 'public.'.$table, 'row_number' => 1, 'payload' => $payload, 'sha256' => hash('sha256', $payload)]);
            DB::table('glowme_import_ids')->insert(['source_table' => 'public.'.$table, 'source_id' => $row['id'], 'local_id' => 1]);
        }
        $rows = app(SourceDistrictCatalog::class)->all();
        $this->assertCount(1, $rows);
        $this->assertSame('glowme-ward-1', $rows[0]['id']);
        $this->assertSame('WARD', $rows[0]['level']);
        $this->assertSame('Phường nguồn', $rows[0]['name']);
        $this->assertSame('Tỉnh nguồn', $rows[0]['province']['name']);
    }
}
