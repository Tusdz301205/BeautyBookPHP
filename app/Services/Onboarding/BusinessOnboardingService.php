<?php

namespace App\Services\Onboarding;

use App\Enums\BusinessStatus;
use App\Enums\RoleName;
use App\Models\Business;
use App\Models\BusinessOwnerProfile;
use App\Models\BusinessReviewEvent;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use DomainException;
use Illuminate\Support\Facades\DB;

class BusinessOnboardingService
{
    public function __construct(private RoleAssignmentService $assignments)
    {
    }

    public function create(User $owner, array $data): Business
    {
        return DB::transaction(function () use ($owner, $data) {
            $profile = BusinessOwnerProfile::query()->firstOrCreate(
                ['user_id' => $owner->id],
                ['company_name' => $data['company_name'] ?? null, 'tax_code' => $data['tax_code'] ?? null],
            );

            $profile->fill([
                'company_name' => $data['company_name'] ?? $profile->company_name,
                'tax_code' => $data['tax_code'] ?? $profile->tax_code,
            ])->save();

            $business = Business::query()->create([
                'owner_profile_id' => $profile->id,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'address_line' => $data['address_line'] ?? null,
                'status' => BusinessStatus::Draft,
            ]);

            $this->assignments->grant($owner, RoleName::BusinessOwner, $business, grantedBy: $owner);

            return $business;
        }, 3);
    }

    public function updateDraft(Business $business, array $data): Business
    {
        if (! in_array($business->status, [BusinessStatus::Draft, BusinessStatus::NeedMoreInfo], true)) {
            throw new DomainException('Chỉ hồ sơ DRAFT hoặc NEED_MORE_INFO mới được chỉnh sửa.');
        }

        return DB::transaction(function () use ($business, $data) {
            $locked = Business::query()->lockForUpdate()->findOrFail($business->id);

            if (! in_array($locked->status, [BusinessStatus::Draft, BusinessStatus::NeedMoreInfo], true)) {
                throw new DomainException('Trạng thái hồ sơ vừa thay đổi.');
            }

            $locked->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'],
                'address_line' => $data['address_line'],
                'review_note' => null,
            ])->save();

            $locked->ownerProfile()->update([
                'company_name' => $data['company_name'],
                'tax_code' => $data['tax_code'],
            ]);

            return $locked->fresh('ownerProfile');
        }, 3);
    }

    public function submit(Business $business, User $actor): Business
    {
        return DB::transaction(function () use ($business, $actor) {
            $locked = Business::query()->with('ownerProfile')->lockForUpdate()->findOrFail($business->id);

            if (! in_array($locked->status, [BusinessStatus::Draft, BusinessStatus::NeedMoreInfo], true)) {
                throw new DomainException('Hồ sơ không ở trạng thái có thể gửi.');
            }

            $missing = collect([
                'name' => $locked->name,
                'contact_email' => $locked->contact_email,
                'contact_phone' => $locked->contact_phone,
                'address_line' => $locked->address_line,
                'company_name' => $locked->ownerProfile?->company_name,
                'tax_code' => $locked->ownerProfile?->tax_code,
            ])->filter(fn ($value) => blank($value))->keys()->all();

            if ($missing !== []) {
                throw new DomainException('Thiếu thông tin: '.implode(', ', $missing));
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => BusinessStatus::PendingReview,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'review_note' => null,
            ])->save();

            BusinessReviewEvent::query()->create([
                'business_id' => $locked->id,
                'actor_id' => $actor->id,
                'action' => $from === BusinessStatus::NeedMoreInfo ? 'RESUBMIT' : 'SUBMIT',
                'from_status' => $from->value,
                'to_status' => BusinessStatus::PendingReview->value,
            ]);

            return $locked;
        }, 3);
    }

    public function review(Business $business, User $actor, string $decision, ?string $note): Business
    {
        return DB::transaction(function () use ($business, $actor, $decision, $note) {
            $locked = Business::query()->lockForUpdate()->findOrFail($business->id);

            if ($locked->status !== BusinessStatus::PendingReview) {
                throw new DomainException('Business không chờ review.');
            }

            $target = match ($decision) {
                'APPROVE' => BusinessStatus::Approved,
                'REQUEST_INFO' => BusinessStatus::NeedMoreInfo,
                'REJECT' => BusinessStatus::Rejected,
                default => throw new DomainException('Quyết định review không hợp lệ.'),
            };

            if ($target !== BusinessStatus::Approved && blank($note)) {
                throw new DomainException('Cần ghi rõ lý do.');
            }

            $locked->forceFill([
                'status' => $target,
                'review_note' => filled($note) ? trim($note) : null,
                'reviewed_at' => now(),
            ])->save();

            BusinessReviewEvent::query()->create([
                'business_id' => $locked->id,
                'actor_id' => $actor->id,
                'action' => $decision,
                'from_status' => BusinessStatus::PendingReview->value,
                'to_status' => $target->value,
                'reason' => filled($note) ? trim($note) : null,
            ]);

            return $locked;
        }, 3);
    }
}
