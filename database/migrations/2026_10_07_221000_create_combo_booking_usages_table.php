<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('combo_booking_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained();
            $table->foreignId('booking_id')->unique()->constrained();
            $table->string('status')->default('RESERVED');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_booking_usages');
    }
};
