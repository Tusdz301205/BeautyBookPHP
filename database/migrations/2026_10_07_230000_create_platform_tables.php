<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('platform_settings');
    }
};
