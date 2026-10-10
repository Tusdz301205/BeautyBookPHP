<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('account_kind', ['CUSTOMER', 'OPERATIONAL'])
                ->default('CUSTOMER')
                ->after('email')
                ->index();
        });

        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'SUSPENDED', 'ARCHIVED'])->default('DRAFT')->index();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->enum('status', ['DRAFT', 'ACTIVE', 'SUSPENDED', 'ARCHIVED'])->default('DRAFT')->index();
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
            $table->unique(['id', 'business_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->string('label', 80);
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT chk_roles_official_names CHECK (name IN ('CUSTOMER','BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN'))");

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('code', 100)->primary();
            $table->string('label', 120);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role_name', 32);
            $table->string('permission_code', 100);
            $table->foreign('role_name')->references('name')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_code')->references('code')->on('permissions')->cascadeOnDelete();
            $table->primary(['role_name', 'permission_code']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_name', 32);
            $table->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('scope_key', 191);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('role_name')->references('name')->on('roles')->restrictOnDelete();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->unique(['user_id', 'role_name', 'scope_key'], 'role_assignments_user_role_scope_unique');
            $table->index(['business_id', 'branch_id', 'role_name']);
        });

        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT chk_role_assignments_scope_shape CHECK ((role_name = 'CUSTOMER' AND business_id IS NULL AND branch_id IS NULL AND scope_key = 'self') OR (role_name = 'PLATFORM_ADMIN' AND business_id IS NULL AND branch_id IS NULL AND scope_key = 'platform') OR (role_name = 'BUSINESS_OWNER' AND business_id IS NOT NULL AND branch_id IS NULL AND scope_key = CONCAT('business:', business_id)) OR (role_name IN ('RECEPTIONIST','STAFF') AND business_id IS NOT NULL AND branch_id IS NOT NULL AND scope_key = CONCAT('branch:', business_id, ':', branch_id)))");

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission_code', 100);
            $table->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('scope_key', 191);
            $table->boolean('allowed')->default(true);
            $table->timestamps();
            $table->foreign('permission_code')->references('code')->on('permissions')->cascadeOnDelete();
            $table->foreign(['branch_id', 'business_id'])->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->unique(['user_id', 'permission_code', 'scope_key'], 'user_permissions_user_permission_scope_unique');
        });

        DB::statement("ALTER TABLE user_permissions ADD CONSTRAINT chk_user_permissions_scope_shape CHECK ((branch_id IS NULL AND business_id IS NULL AND scope_key = 'platform') OR (branch_id IS NULL AND business_id IS NOT NULL AND scope_key = CONCAT('business:', business_id)) OR (branch_id IS NOT NULL AND business_id IS NOT NULL AND scope_key = CONCAT('branch:', business_id, ':', branch_id)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_kind']);
            $table->dropColumn('account_kind');
        });
    }
};
