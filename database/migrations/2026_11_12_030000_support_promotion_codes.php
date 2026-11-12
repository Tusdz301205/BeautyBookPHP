<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('code', 32)->nullable();
            // Existing PHP scopes use AND; GlowMe SELECTED targets use service OR combo.
            $table->string('target_mode', 16)->default('LEGACY');
            $table->string('promotion_code_scope', 32)->storedAs("COALESCE(CAST(business_id AS CHAR), 'platform')");
            $table->unique(['promotion_code_scope', 'code'], 'promotions_scope_code_unique');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        if (! Schema::hasTable('glowme_import_rows') || ! Schema::hasTable('glowme_import_ids')) {
            return;
        }
        $promotionIds = DB::table('glowme_import_ids')->where('source_table', 'public.promotions')->pluck('local_id', 'source_id');
        $businessIds = DB::table('glowme_import_ids')->where('source_table', 'public.businesses')->pluck('local_id', 'source_id');
        DB::transaction(function () use ($promotionIds, $businessIds) {
            foreach (DB::table('glowme_import_rows')->where('source_table', 'public.promotions')->orderBy('row_number')->get() as $raw) {
                $row = json_decode($raw->payload, true, 512, JSON_THROW_ON_ERROR);
                if (! isset($promotionIds[$row['id']], $businessIds[$row['business_id']])) {
                    continue;
                }
                $code = $row['code'] === null ? null : strtoupper(trim($row['code']));
                if ($code !== null && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/D', $code)) {
                    throw new RuntimeException('Invalid imported promotion code.');
                }
                DB::table('promotions')->where('id', $promotionIds[$row['id']])->where('business_id', $businessIds[$row['business_id']])
                    ->whereNull('code')->where('target_mode', 'LEGACY')->update([
                        'code' => $code, 'target_mode' => $row['target_mode'], 'auto_apply' => $row['auto_apply'],
                        'status' => $row['active'] && ! $row['deleted_at'] ? 'ACTIVE' : 'INACTIVE', 'version' => DB::raw('version + 1'),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // A coded program cannot become an automatic discount when rolling code support back.
        DB::table('promotions')->where(fn ($query) => $query->whereNotNull('code')->orWhere('target_mode', 'SELECTED'))->update(['status' => 'INACTIVE']);
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique('promotions_scope_code_unique');
            $table->dropColumn(['promotion_code_scope', 'code', 'target_mode']);
        });
    }
};
