<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('beautybook_user_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('phone', 32)->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->timestamps();
        });
        Schema::create('beautybook_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('platform', 32);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('beautybook_device_tokens'); Schema::dropIfExists('beautybook_user_profiles'); }
};
