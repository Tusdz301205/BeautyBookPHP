<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The provider subject is the durable identity; the email is only a hint recorded at link time.
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('subject', 255);
            $table->string('email', 254);
            $table->timestamps();
            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('email_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['email_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        // Linked identities are account history; back them up before dropping on a populated environment.
        Schema::dropIfExists('password_reset_codes');
        Schema::dropIfExists('external_identities');
    }
};
