<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->index('business_id');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('price')->default(0); // VND, stored as integer
            $table->unsignedInteger('discount_price')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_bookable')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'slug']);
            $table->index(['branch_id', 'status']);
            $table->index('category_id');
        });

        Schema::create('service_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_images');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
