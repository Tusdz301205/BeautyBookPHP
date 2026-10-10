<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = CustomerProfile::where('user_id', $request->user()->id)->first();
        $user = $request->user()->fresh();
        $avatar = $user->avatar_media_file_id ? MediaFile::whereKey($user->avatar_media_file_id)->where('uploaded_by', $user->id)->where('entity_type', 'CUSTOMER_AVATAR')->where('visibility', 'PUBLIC')->first() : null;
        $data = ['name' => $user->name, 'email' => $user->email, 'phone' => $profile?->phone, 'date_of_birth' => $profile?->date_of_birth, 'address' => $profile?->address, 'avatar_url' => $avatar ? route('profile.avatar', $avatar) : null];

        return $request->expectsJson() ? response()->json($data) : view('profile.index', $data);
    }

    public function update(Request $request)
    {
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'], 'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'address' => ['sometimes', 'nullable', 'string', 'max:1000'], 'avatar_media_file_id' => ['sometimes', 'nullable', 'integer']]);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->account_kind->value === 'CUSTOMER', 403);
            $profile = CustomerProfile::firstOrCreate(['user_id' => $user->id]);
            if (array_key_exists('avatar_media_file_id', $data)) {
                if ($data['avatar_media_file_id'] && ! MediaFile::whereKey($data['avatar_media_file_id'])->where('uploaded_by', $user->id)->where('entity_id', $user->id)->where('entity_type', 'CUSTOMER_AVATAR')->whereNull('business_id')->whereNull('branch_id')->where('visibility', 'PUBLIC')->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
                    throw ValidationException::withMessages(['avatar_media_file_id' => 'Ảnh đại diện không thuộc tài khoản này.']);
                }
                $user->forceFill(['avatar_media_file_id' => $data['avatar_media_file_id']]);
            }
            if (isset($data['name'])) {
                $user->name = trim($data['name']);
            }
            $user->save();
            $profile->forceFill(array_intersect_key($data, array_flip(['phone', 'date_of_birth', 'address'])))->save();
            $this->audit($user->id, array_keys($data));
        }, 3);

        return $request->expectsJson() ? $this->show($request) : back()->with('status', 'Đã cập nhật hồ sơ cá nhân.');
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate(['avatar' => ['required', 'file', 'image', 'max:10240', 'mimes:jpg,jpeg,png,webp']]);
        $file = $request->file('avatar');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if (! isset($extensions[$mime]) || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime], true) || ! @getimagesize($file->getRealPath())) {
            throw ValidationException::withMessages(['avatar' => 'Chỉ chấp nhận nội dung ảnh JPEG, PNG hoặc WEBP và phần mở rộng phù hợp.']);
        }
        $path = 'avatars/'.$request->user()->id.'/'.Str::uuid().'.'.$extensions[$mime][0];
        abort_unless(Storage::disk('public')->putFileAs(dirname($path), $file, basename($path)) !== false, 500);
        try {
            $media = DB::transaction(function () use ($request, $file, $mime, $path) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->is_active && $user->account_kind->value === 'CUSTOMER', 403);
                $media = MediaFile::create(['disk' => 'public', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime, 'file_size' => filesize($file->getRealPath()), 'uploaded_by' => $user->id, 'entity_type' => 'CUSTOMER_AVATAR', 'entity_id' => $user->id, 'visibility' => 'PUBLIC']);
                $user->forceFill(['avatar_media_file_id' => $media->id])->save();
                $this->audit($user->id, ['avatar_media_file_id']);

                return $media;
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        return $request->expectsJson() ? response()->json(['avatar_media_file_id' => $media->id, 'avatar_url' => route('profile.avatar', $media)], 201) : back()->with('status', 'Đã cập nhật ảnh đại diện.');
    }

    public function publicAvatar(MediaFile $media)
    {
        abort_unless($media->visibility === 'PUBLIC' && $media->entity_type === 'CUSTOMER_AVATAR' && ! $media->business_id && ! $media->branch_id && (int) $media->entity_id === (int) $media->uploaded_by && in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless(User::whereKey($media->uploaded_by)->where('account_kind', 'CUSTOMER')->where('is_active', true)->where('avatar_media_file_id', $media->id)->exists(), 404);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->response($media->path, null, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-cache']);
    }

    private function audit(int $userId, array $fields): void
    {
        DB::table('audit_logs')->insert(['user_id' => $userId, 'action' => 'UPDATE', 'entity_type' => 'UserProfile', 'entity_id' => (string) $userId, 'metadata' => json_encode(['fields' => $fields], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
