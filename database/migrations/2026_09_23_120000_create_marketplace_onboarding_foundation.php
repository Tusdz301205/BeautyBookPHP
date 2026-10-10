<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_owner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('tax_code', 32)->nullable()->unique();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('owner_profile_id')->nullable()->after('id')->constrained('business_owner_profiles')->restrictOnDelete();
            $table->text('description')->nullable()->after('slug');
            $table->string('contact_email')->nullable()->after('description');
            $table->string('contact_phone', 32)->nullable()->after('contact_email');
            $table->string('address_line')->nullable()->after('contact_phone');
            $table->text('review_note')->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
        });

        DB::statement("ALTER TABLE businesses MODIFY status ENUM('DRAFT','PENDING_REVIEW','NEED_MORE_INFO','APPROVED','ACTIVE','SUSPENDED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");
        DB::table('businesses')->where('status', 'ARCHIVED')->update(['status' => 'SUSPENDED']);
        DB::statement("ALTER TABLE businesses MODIFY status ENUM('DRAFT','PENDING_REVIEW','NEED_MORE_INFO','APPROVED','ACTIVE','SUSPENDED','REJECTED') NOT NULL DEFAULT 'DRAFT'");

        Schema::table('branches', function (Blueprint $table) {
            $table->string('public_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('public_name');
            $table->string('address_line')->nullable()->after('description');
            $table->string('phone', 32)->nullable()->after('address_line');
            $table->string('email')->nullable()->after('phone');
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh')->after('email');
            $table->enum('review_status', ['DRAFT', 'PENDING_REVIEW', 'NEED_MORE_INFO', 'APPROVED', 'REJECTED'])->default('DRAFT')->after('status')->index();
            $table->enum('operational_status', ['INACTIVE', 'READY_TO_PUBLISH', 'ACTIVE', 'PAUSED', 'SUSPENDED', 'CLOSED', 'ARCHIVED'])->default('INACTIVE')->after('review_status')->index();
            $table->text('review_note')->nullable()->after('operational_status');
            $table->timestamp('submitted_at')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->timestamp('published_at')->nullable()->after('reviewed_at');
        });

        DB::statement("ALTER TABLE branches MODIFY status ENUM('DRAFT','ACTIVE','SUSPENDED','ARCHIVED','PENDING','INACTIVE') NOT NULL DEFAULT 'PENDING'");
        DB::table('branches')->where('status', 'DRAFT')->update(['status' => 'PENDING']);
        DB::table('branches')->whereIn('status', ['SUSPENDED', 'ARCHIVED'])->update(['status' => 'INACTIVE']);
        DB::statement("ALTER TABLE branches MODIFY status ENUM('PENDING','ACTIVE','INACTIVE') NOT NULL DEFAULT 'PENDING'");

        Schema::create('branch_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unique(['branch_id', 'day_of_week']);
        });

        DB::statement('ALTER TABLE branch_working_hours ADD CONSTRAINT chk_branch_working_hours_day CHECK (day_of_week BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE branch_working_hours ADD CONSTRAINT chk_branch_working_hours_time CHECK ((is_closed = 1 AND open_time IS NULL AND close_time IS NULL) OR (is_closed = 0 AND open_time IS NOT NULL AND close_time IS NOT NULL AND open_time < close_time))');

        Schema::create('business_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('branch_state_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('from_review_status', 32);
            $table->string('to_review_status', 32);
            $table->string('from_operational_status', 32);
            $table->string('to_operational_status', 32);
            $table->text('reason')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['branch_id', 'version']);
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('business_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
            $table->unique(['id', 'business_id']);
        });

        Schema::create('branch_service_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('business_service_id');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['business_service_id', 'business_id'])->references(['id', 'business_id'])->on('business_services')->restrictOnDelete();
            $table->unique(['branch_id', 'business_service_id']);
            $table->index(['business_id', 'is_active']);
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_offerings');
        Schema::dropIfExists('business_services');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('branch_state_transitions');
        Schema::dropIfExists('business_review_events');
        Schema::dropIfExists('branch_working_hours');

        DB::statement("ALTER TABLE branches MODIFY status ENUM('DRAFT','ACTIVE','SUSPENDED','ARCHIVED','PENDING','INACTIVE') NOT NULL DEFAULT 'DRAFT'");
        DB::table('branches')->whereIn('status', ['PENDING', 'INACTIVE'])->update(['status' => 'DRAFT']);
        DB::statement("ALTER TABLE branches MODIFY status ENUM('DRAFT','ACTIVE','SUSPENDED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['public_name', 'description', 'address_line', 'phone', 'email', 'timezone', 'review_status', 'operational_status', 'review_note', 'submitted_at', 'reviewed_at', 'published_at']);
        });

        DB::table('businesses')->whereNotIn('status', ['DRAFT', 'ACTIVE', 'SUSPENDED'])->update(['status' => 'DRAFT']);
        DB::statement("ALTER TABLE businesses MODIFY status ENUM('DRAFT','ACTIVE','SUSPENDED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['owner_profile_id']);
            $table->dropColumn(['owner_profile_id', 'description', 'contact_email', 'contact_phone', 'address_line', 'review_note', 'submitted_at', 'reviewed_at']);
        });

        Schema::dropIfExists('business_owner_profiles');
    }
};
