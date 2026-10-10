<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may have applied the management migration before this field was added.
        if (!Schema::hasColumn('operational_impact_cases', 'subject_staff_profile_id')) {
            Schema::table('operational_impact_cases', fn (Blueprint $table) => $table->foreignId('subject_staff_profile_id')->nullable()->constrained('staff_profiles'));
        }
    }

    public function down(): void
    {
        // The original management migration owns this column and its rollback.
    }
};
