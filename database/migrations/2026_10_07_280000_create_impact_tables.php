<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operational_impact_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('trigger_type', 40);
            $table->string('status', 40)->default('OPEN');
            $table->text('reason');
            $table->timestamp('deadline_at');
            $table->json('snapshot');
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
        Schema::create('operational_impact_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('operational_impact_cases', indexName: 'impact_item_case_fk')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('PENDING');
            $table->json('booking_snapshot');
            $table->string('snapshot_hash', 64);
            $table->string('resolved_hash', 64)->nullable();
            $table->string('resolution', 40)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('replacement_staff_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('replacement_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->timestamp('proposed_start_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['case_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_impact_items');
        Schema::dropIfExists('operational_impact_cases');
    }
};
