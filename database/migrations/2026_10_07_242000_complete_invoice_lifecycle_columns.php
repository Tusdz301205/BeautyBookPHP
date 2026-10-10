<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('invoices','version')) Schema::table('invoices',fn(Blueprint $table)=>$table->unsignedInteger('version')->default(1));
        if (!Schema::hasColumn('invoices','cancelled_at')) Schema::table('invoices',fn(Blueprint $table)=>$table->timestamp('cancelled_at')->nullable());
    }
    public function down(): void
    {
        // Columns also belong to the fresh-install migration; retain them during rollback.
    }
};
