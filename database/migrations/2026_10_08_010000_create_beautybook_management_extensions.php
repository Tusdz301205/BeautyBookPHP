<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operational_impact_cases', function (Blueprint $table) {
            $table->foreignId('subject_staff_profile_id')->nullable()->constrained('staff_profiles');
        });
        Schema::create('beautybook_business_settings', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->unique()->constrained();
            $table->json('metadata')->nullable(); $table->json('cancellation_policy')->nullable(); $table->timestamps();
        });
        Schema::create('beautybook_branch_settings', function (Blueprint $table) {
            $table->id(); $table->foreignId('branch_id')->unique()->constrained();
            $table->json('onboarding')->nullable(); $table->json('booking_policy')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('beautybook_branch_calendar', function (Blueprint $table) {
            $table->id(); $table->foreignId('branch_id')->constrained(); $table->date('date'); $table->string('name')->nullable();
            $table->boolean('is_closed')->default(true); $table->time('open_time')->nullable(); $table->time('close_time')->nullable();
            $table->timestamps(); $table->unique(['branch_id', 'date']);
        });
        Schema::create('beautybook_documents', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained(); $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('document_type'); $table->string('document_name'); $table->string('document_number')->nullable();
            $table->date('issued_at')->nullable(); $table->date('expires_at')->nullable(); $table->string('status')->default('ACTIVE'); $table->timestamps();
        });
        Schema::create('beautybook_document_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('document_id')->constrained('beautybook_documents');
            $table->foreignId('media_id')->constrained('media_files'); $table->unsignedInteger('version'); $table->timestamps(); $table->unique(['document_id','version']);
        });
        Schema::create('beautybook_staff_settings', function (Blueprint $table) {
            $table->id(); $table->foreignId('staff_profile_id')->unique()->constrained(); $table->json('metadata')->nullable(); $table->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['beautybook_staff_settings','beautybook_document_versions','beautybook_documents','beautybook_branch_calendar','beautybook_branch_settings','beautybook_business_settings'] as $table) Schema::dropIfExists($table);
        Schema::table('operational_impact_cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('subject_staff_profile_id'));
    }
};
