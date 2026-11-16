<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_absa_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->string('model_id', 64);
            $table->char('checkpoint_sha256', 64);
            $table->string('taxonomy_version', 64);
            $table->string('preprocessing_version', 64);
            $table->string('source', 32)->default('REVIEW_CREATED');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at', 3)->useCurrent();
            $table->timestamp('locked_until', 3)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('completed_at', 3)->nullable();
            foreach (['service_quality','staff_attitude','wait_time','price'] as $column) $table->string($column, 16)->nullable();
            $table->json('probabilities')->nullable();
            $table->unsignedInteger('n_tokens')->nullable();
            $table->boolean('truncated')->nullable();
            $table->char('text_sha256', 64)->nullable();
            $table->string('error_code', 48)->nullable();
            $table->string('error_detail', 300)->nullable();
            $table->boolean('error_retryable')->default(true);
            $table->timestamps(3);
            $table->unique(['review_id','model_id','checkpoint_sha256'], 'absa_review_model_unique');
            $table->index(['model_id','checkpoint_sha256','status','available_at'], 'absa_due_index');
        });
    }
    public function down(): void { Schema::dropIfExists('review_absa_analyses'); }
};
