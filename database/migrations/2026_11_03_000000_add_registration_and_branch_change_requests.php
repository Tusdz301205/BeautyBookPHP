<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('legal_entity_type', 32)->nullable();
            $table->string('legal_name', 200)->nullable();
            $table->string('registration_number', 80)->nullable();
            $table->string('registered_address', 500)->nullable();
        });
        Schema::table('branches', fn (Blueprint $table) => $table->string('registration_type', 40)->nullable());
        Schema::table('bookings', fn (Blueprint $table) => $table->json('branch_location_snapshot')->nullable());
        Schema::create('branch_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('media_id')->constrained('media_files')->restrictOnDelete();
            $table->json('base_snapshot');
            $table->json('proposed_snapshot');
            $table->string('reason', 2000)->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 2000)->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['branch_id', 'id']);
        });
        // NULL values may repeat; only the PENDING row claims a branch ID.
        DB::statement("ALTER TABLE branch_change_requests ADD pending_branch_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'PENDING' THEN branch_id ELSE NULL END) STORED, ADD UNIQUE KEY branch_change_one_pending (pending_branch_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_change_requests');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('branch_location_snapshot'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('registration_type'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn(['legal_entity_type', 'legal_name', 'registration_number', 'registered_address']));
    }
};
