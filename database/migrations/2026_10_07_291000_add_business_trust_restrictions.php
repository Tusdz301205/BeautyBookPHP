<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('booking_restricted_at')->nullable();
            $table->text('booking_restriction_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn(['booking_restricted_at', 'booking_restriction_reason']));
    }
};
