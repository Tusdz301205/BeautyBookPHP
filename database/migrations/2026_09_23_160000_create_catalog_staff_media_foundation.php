<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('slug', 140)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('canonical_services')->restrictOnDelete();
            $table->foreignId('replacement_canonical_id')->nullable()->constrained('canonical_services')->restrictOnDelete();
            $table->json('synonyms')->nullable();
            $table->enum('status', ['ACTIVE', 'DEPRECATED', 'MERGED'])->default('ACTIVE')->index();
            $table->timestamps();
        });

        Schema::table('business_services', function (Blueprint $table) {
            $table->foreignId('canonical_service_id')->nullable()->after('service_category_id')->constrained('canonical_services')->restrictOnDelete();
            $table->text('description')->nullable()->after('slug');
            $table->json('keywords')->nullable()->after('description');
            $table->enum('mapping_status', ['MAPPED', 'UNMAPPED', 'SUGGESTED'])->default('UNMAPPED')->after('keywords');
            $table->decimal('base_price', 12, 2)->default(0)->after('mapping_status');
            $table->unsignedSmallInteger('base_duration_minutes')->default(60)->after('base_price');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->after('base_duration_minutes');
            $table->softDeletes();
            $table->index(['business_id', 'status', 'deleted_at']);
        });

        Schema::table('branch_service_offerings', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->default(60)->after('price');
            $table->boolean('bookable')->default(true)->after('duration_minutes');
            $table->softDeletes();
            $table->unique(['id', 'business_id', 'branch_id'], 'offerings_id_business_branch_unique');
        });

        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->enum('price_type', ['FIXED', 'FROM', 'RANGE', 'QUOTE'])->default('FIXED');
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('max_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('max_duration_minutes')->nullable();
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->boolean('consultation_required')->default(false);
            $table->json('eligibility_rules')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_service_offering_id', 'code']);
            $table->unique(['id', 'branch_service_offering_id'], 'service_variants_id_offering_unique');
            $table->index(['branch_service_offering_id', 'status', 'deleted_at'], 'service_variants_active_index');
        });

        Schema::create('service_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('service_variant_id')->nullable();
            $table->string('name', 160);
            $table->unsignedInteger('priority')->default(100);
            $table->enum('adjustment_type', ['PERCENTAGE', 'FIXED_AMOUNT']);
            $table->decimal('adjustment_value', 12, 2);
            $table->json('conditions');
            $table->boolean('active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['service_variant_id', 'branch_service_offering_id'], 'price_rules_variant_offering_fk')
                ->references(['id', 'branch_service_offering_id'])->on('service_variants')->restrictOnDelete();
            $table->index(['branch_service_offering_id', 'active', 'priority'], 'price_rules_active_index');
        });

        Schema::create('service_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('branch_service_offering_id');
            $table->unsignedBigInteger('required_offering_id');
            $table->enum('dependency_type', ['REQUIRED', 'ADD_ON', 'INCOMPATIBLE']);
            $table->timestamps();
            $table->foreign(['branch_service_offering_id', 'business_id', 'branch_id'], 'dependencies_offering_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('branch_service_offerings')->restrictOnDelete();
            $table->foreign(['required_offering_id', 'business_id', 'branch_id'], 'dependencies_required_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('branch_service_offerings')->restrictOnDelete();
            $table->unique(['branch_service_offering_id', 'required_offering_id', 'dependency_type'], 'service_dependencies_unique');
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('full_name', 160);
            $table->string('position', 120)->nullable();
            $table->text('bio')->nullable();
            $table->string('employee_code', 80)->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->boolean('public_visible')->default(false);
            $table->boolean('is_bookable')->default(false);
            $table->enum('status', ['PROFILE_ONLY', 'INVITED', 'ACTIVE', 'LOCKED', 'INACTIVE'])->default('PROFILE_ONLY');
            $table->date('hired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->unique(['branch_id', 'employee_code']);
            $table->unique(['id', 'business_id'], 'staff_profiles_id_business_unique');
            $table->unique(['id', 'business_id', 'branch_id'], 'staff_profiles_id_business_branch_unique');
            $table->index(['branch_id', 'status', 'is_bookable', 'public_visible'], 'staff_profiles_public_index');
        });

        Schema::create('staff_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_profile_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'ENDED'])->default('ACTIVE');
            $table->string('job_title', 120)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_bookable')->default(false);
            $table->timestamps();
            $table->foreign(['staff_profile_id', 'business_id'], 'staff_assignments_staff_business_fk')
                ->references(['id', 'business_id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['branch_id', 'business_id'], 'staff_assignments_branch_business_fk')
                ->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->unique(['staff_profile_id', 'branch_id', 'start_date'], 'staff_assignments_unique');
            $table->index(['branch_id', 'status', 'start_date'], 'staff_assignments_branch_index');
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->enum('role_code', ['RECEPTIONIST', 'STAFF']);
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED'])->default('PENDING');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->index(['email', 'status']);
            $table->index(['business_id', 'branch_id']);
        });

        Schema::create('staff_services', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_profile_id');
            $table->unsignedBigInteger('branch_service_offering_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->foreign(['staff_profile_id', 'business_id', 'branch_id'], 'staff_services_staff_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['branch_service_offering_id', 'business_id', 'branch_id'], 'staff_services_offering_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('branch_service_offerings')->restrictOnDelete();
            $table->primary(['staff_profile_id', 'branch_service_offering_id']);
            $table->index(['branch_service_offering_id', 'branch_id'], 'staff_services_offering_index');
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32);
            $table->string('path')->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->enum('visibility', ['PUBLIC', 'PRIVATE'])->default('PUBLIC');
            $table->timestamps();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->index(['business_id', 'branch_id']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('business_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'media_file_id']);
        });

        Schema::create('branch_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'media_file_id']);
        });

        Schema::create('service_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['branch_service_offering_id', 'media_file_id'], 'service_images_unique');
        });

        Schema::create('staff_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['staff_profile_id', 'media_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_images');
        Schema::dropIfExists('service_images');
        Schema::dropIfExists('branch_images');
        Schema::dropIfExists('business_images');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('staff_services');
        Schema::dropIfExists('staff_invitations');
        Schema::dropIfExists('staff_branch_assignments');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('service_dependencies');
        Schema::dropIfExists('service_price_rules');
        Schema::dropIfExists('service_variants');

        Schema::table('branch_service_offerings', function (Blueprint $table) {
            $table->dropUnique('offerings_id_business_branch_unique');
            $table->dropColumn(['duration_minutes', 'bookable', 'deleted_at']);
        });

        Schema::table('business_services', function (Blueprint $table) {
            $table->dropForeign(['canonical_service_id']);
            $table->dropIndex(['business_id', 'status', 'deleted_at']);
            $table->dropColumn([
                'canonical_service_id',
                'description',
                'keywords',
                'mapping_status',
                'base_price',
                'base_duration_minutes',
                'status',
                'deleted_at',
            ]);
        });

        Schema::dropIfExists('canonical_services');
    }
};
