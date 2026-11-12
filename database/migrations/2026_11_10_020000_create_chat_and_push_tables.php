<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedInteger('customer_read_sequence')->default(0);
            $table->unsignedInteger('branch_read_sequence')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_profile_id', 'branch_id']);
            $table->index(['branch_id', 'last_message_at']);
        });
        // Private images: the bytes live on the private media disk (media_files keeps the disk per row); an upload is unused
        // until a message claims it, and only its uploader can read it back before that.
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->restrictOnDelete();
            $table->foreignId('uploader_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('media_file_id')->unique()->constrained('media_files')->restrictOnDelete();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index(['conversation_id', 'uploader_user_id']);
        });
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->unique()->constrained('chat_attachments')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('from_branch');
            $table->char('client_message_id', 36);
            $table->text('body')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->dateTime('created_at');
            // Sequence gives total order; the client id makes a retried send idempotent.
            $table->unique(['conversation_id', 'sequence']);
            $table->unique(['conversation_id', 'sender_user_id', 'client_message_id'], 'chat_messages_client_key_unique');
        });

        // Reuse the existing device registry (encrypted token, one row per token hash); add a revocation flag.
        Schema::table('beautybook_device_tokens', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('platform');
            // push-devices.service.ts: a device is one app installation signed in through one session. Only a hash of the
            // session id is kept (the id itself is a credential); rows from before this binding existed have neither.
            $table->char('installation_id', 36)->nullable()->after('platform');
            $table->char('session_id_hash', 64)->nullable()->after('installation_id');
            $table->index(['user_id', 'active'], 'device_tokens_user_active_index');
            $table->index('installation_id', 'device_tokens_installation_index');
            $table->index('session_id_hash', 'device_tokens_session_index');
        });
        // A device with no session cannot be tied to anybody's login, so it must register again before it receives pushes.
        DB::table('beautybook_device_tokens')->update(['active' => false]);
        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('beautybook_device_tokens')->restrictOnDelete();
            // The session the device was bound to when this push was queued: a rebound device never replays old pushes.
            $table->char('session_id_hash', 64);
            $table->enum('status', ['PENDING', 'PROCESSING', 'DELIVERED', 'FAILED'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('locked_until', 6)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('sent_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'device_id', 'session_id_hash'], 'push_deliveries_notification_device_session_unique');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        // Conversation history is customer data: back it up before dropping on a populated environment.
        Schema::dropIfExists('push_deliveries');
        Schema::table('beautybook_device_tokens', function (Blueprint $table) {
            $table->dropIndex('device_tokens_user_active_index');
            $table->dropIndex('device_tokens_installation_index');
            $table->dropIndex('device_tokens_session_index');
            $table->dropColumn(['active', 'installation_id', 'session_id_hash']);
        });
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_attachments');
        Schema::dropIfExists('chat_conversations');
    }
};
