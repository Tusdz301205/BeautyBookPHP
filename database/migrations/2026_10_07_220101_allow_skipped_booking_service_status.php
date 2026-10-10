<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE booking_services MODIFY status ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED','SKIPPED') NOT NULL DEFAULT 'SCHEDULED'");
    }

    public function down(): void
    {
        DB::table('booking_services')->where('status', 'SKIPPED')->update(['status' => 'CANCELLED']);
        DB::statement("ALTER TABLE booking_services MODIFY status ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED'");
    }
};
