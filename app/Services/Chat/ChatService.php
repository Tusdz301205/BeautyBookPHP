<?php

namespace App\Services\Chat;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\RoleAssignment;
use App\Services\Beautybook\ApiScope;
use App\Services\Booking\BookingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer <-> branch chat (chat.service.ts / chat-access.ts). One conversation per customer and branch,
 * opened from a booking the caller may see. Customers see their own conversations; business owners see
 * every branch of their business and receptionists only their branch. Messages carry a per-conversation
 * sequence and a client message id, so a retried send returns the original message instead of a duplicate.
 */
class ChatService
{
    public function __construct(private ApiScope $scope, private BookingNotificationService $notifications) {}

    public static function scopeName(string $value): string
    {
        abort_unless(in_array($value, ['customer', 'branch'], true), 400, 'Phạm vi trò chuyện không hợp lệ.');

        return $value;
    }

    /** Branch ids the caller may act on in the branch scope (owner: whole business, receptionist: own branch). */
    private function branchIds(Request $r): array
    {
        $grants = $this->scope->assignments($r)->filter(fn (RoleAssignment $a) => in_array($a->role_name, ['BUSINESS_OWNER', 'RECEPTIONIST'], true));
        abort_unless($grants->isNotEmpty(), 403, 'Cần quyền trò chuyện của chi nhánh.');
        $ids = [];
        foreach ($grants as $grant) {
            $ids = array_merge($ids, $grant->role_name === 'BUSINESS_OWNER'
                ? Branch::where('business_id', $grant->business_id)->pluck('id')->all()
                : [(int) $grant->branch_id]);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Restricts a conversation query to what this caller is allowed to read. */
    private function visible(Request $r, string $scope)
    {
        $query = DB::table('chat_conversations as c');
        if ($scope === 'customer') {
            return $query->where('c.customer_profile_id', $this->scope->customer($r)->id);
        }

        return $query->whereIn('c.branch_id', $this->branchIds($r));
    }

    private function conversation(Request $r, string $scope, int $id, bool $lock = false): object
    {
        $query = $this->visible($r, $scope)->where('c.id', $id)
            ->join('branches as b', 'b.id', '=', 'c.branch_id')
            ->join('customer_profiles as p', 'p.id', '=', 'c.customer_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->select('c.*', 'b.name as branch_name', 'b.business_id', 'b.status as branch_status', 'p.user_id as customer_user_id', 'u.name as customer_name', 'u.is_active as customer_active');
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        abort_unless($row, 404, 'Không tìm thấy cuộc trò chuyện.');

        return $row;
    }

    private function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toISOString() : null;
    }

    private function presentMessage(object $m): array
    {
        $booking = $m->booking_id ? DB::table('bookings')->where('id', $m->booking_id)->first(['id', 'booking_code', 'appointment_start_at', 'status']) : null;

        return ['id' => (string) $m->id, 'conversationId' => (string) $m->conversation_id, 'sequence' => (int) $m->sequence, 'body' => $m->body, 'fromBranch' => (bool) $m->from_branch,
            'senderId' => (string) $m->sender_user_id, 'clientMessageId' => $m->client_message_id, 'createdAt' => $this->iso($m->created_at),
            'sender' => ['fullName' => DB::table('users')->where('id', $m->sender_user_id)->value('name')],
            'attachment' => $m->attachment_id ? $this->presentAttachment($m->attachment_id) : null,
            'booking' => $booking ? ['id' => (string) $booking->id, 'bookingCode' => $booking->booking_code, 'startAt' => $this->iso($booking->appointment_start_at), 'status' => $booking->status] : null];
    }

    private function presentAttachment(int $id): ?array
    {
        $a = DB::table('chat_attachments')->where('id', $id)->first(['id', 'mime_type', 'size_bytes']);

        return $a ? ['id' => (string) $a->id, 'mimeType' => $a->mime_type, 'sizeBytes' => (int) $a->size_bytes] : null;
    }

    /** Stores a private image for this conversation. It stays unused (visible only to its uploader) until a message claims it. */
    public function upload(Request $r, string $scope, int $id, ?\Illuminate\Http\UploadedFile $file): array
    {
        abort_unless($file && $file->isValid(), 422, 'Cần chọn một ảnh.');
        $this->conversation($r, $scope, $id);
        [$mime, $extension, $size] = app(\App\Services\Media\MediaManagementService::class)->validatePrivateImage($file, 'Ảnh');
        $path = 'chat-images/'.\Illuminate\Support\Str::uuid().'.'.$extension;
        $sha = hash_file('sha256', $file->getRealPath());
        $disk = \App\Services\Media\PrivateMediaDisk::put($file, $path);
        $userId = (int) $r->user()->id;
        try {
            return DB::transaction(function () use ($r, $scope, $id, $mime, $size, $sha, $disk, $path, $userId) {
                $row = $this->conversation($r, $scope, $id, true);
                abort_unless($this->writable($row), 409, 'Cuộc trò chuyện ở chế độ chỉ đọc.');
                $unused = DB::table('chat_attachments as a')->leftJoin('chat_messages as m', 'm.attachment_id', '=', 'a.id')->where('a.conversation_id', $id)->where('a.uploader_user_id', $userId)->whereNull('m.id')->count();
                abort_if($unused >= 10, 422, 'Quá nhiều ảnh đã tải lên nhưng chưa gửi.');
                $media = \App\Models\MediaFile::create(['disk' => $disk, 'path' => $path, 'mime_type' => $mime, 'file_size' => $size, 'uploaded_by' => $userId, 'business_id' => $row->business_id, 'branch_id' => $row->branch_id,
                    'entity_type' => 'ChatAttachment', 'entity_id' => $id, 'visibility' => 'PRIVATE']);
                $attachment = DB::table('chat_attachments')->insertGetId(['conversation_id' => $id, 'uploader_user_id' => $userId, 'media_file_id' => $media->id, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $sha, 'created_at' => now(), 'updated_at' => now()]);

                return $this->presentAttachment($attachment);
            }, 3);
        } catch (\Throwable $error) {
            // Check the database before deleting bytes: after an uncertain commit the stored row wins and the bytes stay.
            $stored = \App\Models\MediaFile::where('path', $path)->first();
            if ($stored && DB::table('chat_attachments')->where('media_file_id', $stored->id)->exists()) return $this->presentAttachment((int) DB::table('chat_attachments')->where('media_file_id', $stored->id)->value('id'));
            \App\Services\Media\PrivateMediaDisk::delete($disk, $path);
            throw $error;
        }
    }

    /** Bytes of one image. Members only; an unsent upload is readable by its uploader alone; access is re-checked after the I/O. */
    public function content(Request $r, string $scope, int $id, int $imageId): array
    {
        $this->conversation($r, $scope, $id);
        $userId = (int) $r->user()->id;
        $attachment = DB::table('chat_attachments as a')->leftJoin('chat_messages as m', 'm.attachment_id', '=', 'a.id')->where('a.id', $imageId)->where('a.conversation_id', $id)
            ->where(fn ($q) => $q->whereNotNull('m.id')->orWhere('a.uploader_user_id', $userId))->first(['a.media_file_id', 'a.mime_type', 'a.size_bytes', 'a.sha256']);
        abort_unless($attachment, 404, 'Không tìm thấy ảnh.');
        $media = \App\Models\MediaFile::findOrFail($attachment->media_file_id);
        // The bytes must be what was uploaded: same length and same SHA-256 as the attachment row, else the image does not exist.
        $bytes = \App\Services\Media\PrivateMediaDisk::readVerified($media, (int) $attachment->size_bytes, (string) $attachment->sha256);
        abort_if($bytes === null, 404, 'Không tìm thấy ảnh.');
        $this->conversation($r, $scope, $id);

        return ['bytes' => $bytes, 'mimeType' => $attachment->mime_type];
    }

    public function open(Request $r, string $scope, int $bookingId): array
    {
        $booking = Booking::find($bookingId);
        $allowed = $booking && ($scope === 'customer' ? (int) $booking->customer_profile_id === (int) $this->scope->customer($r)->id : in_array((int) $booking->branch_id, $this->branchIds($r), true));
        abort_unless($allowed, 404, 'Không tìm thấy lịch hẹn.');
        DB::table('chat_conversations')->insertOrIgnore(['customer_profile_id' => $booking->customer_profile_id, 'branch_id' => $booking->branch_id, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('chat_conversations')->where('customer_profile_id', $booking->customer_profile_id)->where('branch_id', $booking->branch_id)->value('id');

        return ['id' => (string) $id];
    }

    public function list(Request $r, string $scope): array
    {
        $query = $this->visible($r, $scope)->join('branches as b', 'b.id', '=', 'c.branch_id')->join('customer_profiles as p', 'p.id', '=', 'c.customer_profile_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->select('c.*', 'b.name as branch_name', 'b.business_id', 'u.name as customer_name')->orderByRaw('c.last_message_at IS NULL')->orderByDesc('c.last_message_at')->orderByDesc('c.id');
        if ($r->filled('branchId')) $query->where('c.branch_id', (int) $r->query('branchId'));
        if ($r->filled('businessId')) $query->where('b.business_id', (int) $r->query('businessId'));
        $limit = max(1, min(50, (int) $r->query('limit', 20)));
        $page = max(1, (int) $r->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();
        $items = $rows->map(function ($row) use ($scope) {
            $last = DB::table('chat_messages')->where('conversation_id', $row->id)->orderByDesc('sequence')->first();
            $unread = DB::table('chat_messages')->where('conversation_id', $row->id)->where('from_branch', $scope === 'customer')
                ->where('sequence', '>', $scope === 'customer' ? $row->customer_read_sequence : $row->branch_read_sequence)->count();

            return ['id' => (string) $row->id, 'branchId' => (string) $row->branch_id, 'businessId' => (string) $row->business_id, 'branchName' => $row->branch_name, 'customerName' => $row->customer_name,
                'title' => $scope === 'customer' ? $row->branch_name : $row->customer_name, 'lastMessage' => $last ? $this->presentMessage($last) : null,
                'lastMessageAt' => $this->iso($row->last_message_at), 'unreadCount' => $unread];
        })->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function messages(Request $r, string $scope, int $id): array
    {
        $after = $r->query('after');
        $before = $r->query('before');
        abort_if($after !== null && $before !== null, 400, 'Chọn một con trỏ tin nhắn.');
        $row = $this->conversation($r, $scope, $id);
        $query = DB::table('chat_messages')->where('conversation_id', $id);
        if ($after !== null) $query->where('sequence', '>', (int) $after)->orderBy('sequence');
        else {
            if ($before !== null) $query->where('sequence', '<', (int) $before);
            $query->orderByDesc('sequence');
        }
        $items = $query->limit(51)->get();
        $more = $items->count() > 50;
        if ($more) $items->pop();
        if ($after === null) $items = $items->reverse()->values();

        return ['id' => (string) $id, 'title' => $scope === 'customer' ? $row->branch_name : $row->customer_name, 'branchName' => $row->branch_name, 'branchId' => (string) $row->branch_id, 'businessId' => (string) $row->business_id,
            'canSend' => $this->writable($row), 'lastSequence' => (int) $row->last_sequence,
            'peerReadSequence' => (int) ($scope === 'customer' ? $row->branch_read_sequence : $row->customer_read_sequence),
            'items' => $items->map(fn ($m) => $this->presentMessage($m))->all(), 'hasMore' => $more];
    }

    private function writable(object $row): bool
    {
        return (bool) $row->customer_active && Business::whereKey($row->business_id)->whereIn('status', ['APPROVED', 'ACTIVE'])->exists();
    }

    /** Owners of the business and receptionists of the branch, never the sender. */
    private function branchRecipients(object $row): array
    {
        return RoleAssignment::where('business_id', $row->business_id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->where(fn ($q) => $q->where('role_name', 'BUSINESS_OWNER')->orWhere(fn ($q) => $q->where('role_name', 'RECEPTIONIST')->where('branch_id', $row->branch_id)))
            ->whereHas('user', fn ($q) => $q->where('is_active', true))->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->all();
    }

    public function send(Request $r, string $scope, int $id, array $data): array
    {
        $text = isset($data['body']) ? trim((string) $data['body']) : '';
        $text = $text === '' ? null : $text;
        $bookingId = isset($data['bookingId']) ? (int) $data['bookingId'] : null;
        $attachmentId = isset($data['attachmentId']) ? (int) $data['attachmentId'] : null;
        abort_if($text === null && ! $bookingId && ! $attachmentId, 422, 'Tin nhắn trống.');
        $userId = $r->user()->id;

        return DB::transaction(function () use ($r, $scope, $id, $data, $text, $bookingId, $attachmentId, $userId) {
            $row = $this->conversation($r, $scope, $id, true);
            $dup = DB::table('chat_messages')->where('conversation_id', $id)->where('sender_user_id', $userId)->where('client_message_id', $data['clientMessageId'])->first();
            if ($dup) {
                abort_unless($dup->body === $text && ((int) $dup->booking_id ?: null) === $bookingId && ((int) $dup->attachment_id ?: null) === $attachmentId && (bool) $dup->from_branch === ($scope === 'branch'), 409, 'Mã tin nhắn đã được dùng cho nội dung khác.');

                return $this->presentMessage($dup);
            }
            abort_unless($this->writable($row), 409, 'Cuộc trò chuyện ở chế độ chỉ đọc.');
            if ($bookingId) abort_unless(Booking::whereKey($bookingId)->where('customer_profile_id', $row->customer_profile_id)->where('branch_id', $row->branch_id)->exists(), 422, 'Lịch hẹn không thuộc cuộc trò chuyện này.');
            // An image must be one this sender uploaded to this conversation and no message has claimed yet.
            if ($attachmentId) abort_unless(DB::table('chat_attachments')->where('id', $attachmentId)->where('conversation_id', $id)->where('uploader_user_id', $userId)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('chat_messages')->whereColumn('chat_messages.attachment_id', 'chat_attachments.id'))->lockForUpdate()->exists(), 422, 'Ảnh không thuộc tin nhắn này.');
            $sequence = (int) $row->last_sequence + 1;
            $messageId = DB::table('chat_messages')->insertGetId(['conversation_id' => $id, 'sequence' => $sequence, 'sender_user_id' => $userId, 'from_branch' => $scope === 'branch', 'client_message_id' => $data['clientMessageId'], 'body' => $text, 'booking_id' => $bookingId, 'attachment_id' => $attachmentId, 'created_at' => now('UTC')]);
            DB::table('chat_conversations')->where('id', $id)->update(['last_sequence' => $sequence, 'last_message_at' => now('UTC'), 'updated_at' => now()]);
            $recipients = $scope === 'branch' ? [(int) $row->customer_user_id] : $this->branchRecipients($row);
            $sender = $r->user()->name;
            foreach (array_unique(array_diff($recipients, [$userId])) as $recipient) {
                $this->notifications->record($recipient, 'CHAT_MESSAGE', $scope === 'branch' ? $row->branch_name : 'Tin nhắn từ '.$sender, $text !== null ? mb_substr($text, 0, 140) : ($attachmentId ? 'Đã gửi một ảnh' : 'Đã chia sẻ một lịch hẹn'), null, 'chat:'.$messageId.':'.$recipient, 'INFO', ['conversationId' => (string) $id, 'scope' => $scope === 'branch' ? 'customer' : 'branch']);
            }

            return $this->presentMessage(DB::table('chat_messages')->find($messageId));
        }, 3);
    }

    public function markRead(Request $r, string $scope, int $id, int $sequence): array
    {
        return DB::transaction(function () use ($r, $scope, $id, $sequence) {
            $row = $this->conversation($r, $scope, $id, true);
            abort_if($sequence > $row->last_sequence, 422, 'Vị trí đã đọc không hợp lệ.');
            $field = $scope === 'customer' ? 'customer_read_sequence' : 'branch_read_sequence';
            if ($sequence > $row->$field) DB::table('chat_conversations')->where('id', $id)->update([$field => $sequence]);

            return ['sequence' => max($sequence, (int) $row->$field)];
        }, 3);
    }
}
