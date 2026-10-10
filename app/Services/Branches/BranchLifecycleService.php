<?php

namespace App\Services\Branches;

use App\Enums\BranchOperationalStatus;
use App\Enums\BranchReviewStatus;
use App\Enums\BranchStatus;
use App\Enums\BusinessStatus;
use App\Models\Branch;
use App\Models\BranchStateTransition;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class BranchLifecycleService
{
    public function create(int $businessId, array $data): Branch
    {
        return Branch::query()->create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'public_name' => $data['public_name'] ?? $data['name'],
            'description' => $data['description'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'timezone' => $data['timezone'] ?? 'Asia/Ho_Chi_Minh',
            'status' => BranchStatus::Pending,
            'review_status' => BranchReviewStatus::Draft,
            'operational_status' => BranchOperationalStatus::Inactive,
        ]);
    }

    public function updateDraft(Branch $branch, array $data): Branch
    {
        if (! in_array($branch->review_status, [BranchReviewStatus::Draft, BranchReviewStatus::NeedMoreInfo], true)) {
            throw new DomainException('Chỉ chi nhánh DRAFT hoặc NEED_MORE_INFO mới được chỉnh sửa.');
        }

        $branch->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'public_name' => $data['public_name'],
            'description' => $data['description'] ?? null,
            'address_line' => $data['address_line'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'timezone' => $data['timezone'],
            'review_note' => null,
        ])->save();

        return $branch;
    }

    public function replaceHours(Branch $branch, array $hours): Branch
    {
        if (! in_array($branch->review_status, [BranchReviewStatus::Draft, BranchReviewStatus::NeedMoreInfo, BranchReviewStatus::Approved], true)) {
            throw new DomainException('Giờ mở cửa không thể sửa trong trạng thái hiện tại.');
        }

        if (count($hours) !== 7) {
            throw new DomainException('Cần khai báo đủ 7 ngày trong tuần.');
        }

        return DB::transaction(function () use ($branch, $hours) {
            foreach ($hours as $row) {
                $closed = (bool) ($row['is_closed'] ?? false);
                $branch->workingHours()->updateOrCreate(
                    ['day_of_week' => (int) $row['day_of_week']],
                    [
                        'is_closed' => $closed,
                        'open_time' => $closed ? null : $row['open_time'],
                        'close_time' => $closed ? null : $row['close_time'],
                    ],
                );
            }

            return $branch->fresh('workingHours');
        }, 3);
    }

    public function submit(Branch $branch, User $actor): Branch
    {
        return $this->transition($branch, $actor, 'SUBMIT');
    }

    public function review(Branch $branch, User $actor, string $decision, ?string $reason): Branch
    {
        return $this->transition($branch, $actor, $decision, $reason);
    }

    public function publish(Branch $branch, User $actor): Branch
    {
        return $this->transition($branch, $actor, 'PUBLISH');
    }

    public function suspend(Branch $branch, User $actor, string $reason): Branch
    {
        return $this->transition($branch, $actor, 'SUSPEND', $reason);
    }

    public function close(Branch $branch, User $actor, string $reason): Branch
    {
        return $this->transition($branch, $actor, 'CLOSE', $reason);
    }

    public function pause(Branch $branch, User $actor, string $reason): Branch
    {
        return $this->transition($branch, $actor, 'PAUSE', $reason);
    }

    public function restore(Branch $branch, User $actor, string $reason): Branch
    {
        return $this->transition($branch, $actor, 'RESTORE', $reason);
    }

    private function transition(Branch $branch, User $actor, string $action, ?string $reason = null): Branch
    {
        $result = DB::transaction(function () use ($branch, $actor, $action, $reason) {
            $current = Branch::query()
                ->with(['business', 'workingHours'])
                ->lockForUpdate()
                ->findOrFail($branch->id);

            if ($action === 'SUBMIT') {
                if (! in_array($current->review_status, [BranchReviewStatus::Draft, BranchReviewStatus::NeedMoreInfo], true)) {
                    throw new DomainException('Chi nhánh không ở trạng thái có thể gửi review.');
                }

                $this->assertBusinessReady($current);
                $this->assertComplete($current);
                $target = [BranchStatus::Pending, BranchReviewStatus::PendingReview, BranchOperationalStatus::Inactive];
            } elseif ($action === 'APPROVE') {
                $this->assertPendingReview($current);
                $target = [BranchStatus::Inactive, BranchReviewStatus::Approved, BranchOperationalStatus::ReadyToPublish];
            } elseif ($action === 'REQUEST_INFO') {
                $this->assertPendingReview($current);
                $this->assertReason($reason);
                $target = [BranchStatus::Pending, BranchReviewStatus::NeedMoreInfo, BranchOperationalStatus::Inactive];
            } elseif ($action === 'REJECT') {
                $this->assertPendingReview($current);
                $this->assertReason($reason);
                $target = [BranchStatus::Pending, BranchReviewStatus::Rejected, BranchOperationalStatus::Inactive];
            } elseif ($action === 'PUBLISH') {
                if ($current->review_status !== BranchReviewStatus::Approved || $current->operational_status !== BranchOperationalStatus::ReadyToPublish) {
                    throw new DomainException('Chi nhánh chưa sẵn sàng publish.');
                }

                $this->assertBusinessReady($current);
                $this->assertComplete($current);
                $target = [BranchStatus::Active, BranchReviewStatus::Approved, BranchOperationalStatus::Active];
            } elseif ($action === 'RESTORE') {
                $this->assertReason($reason);
                if ($current->review_status !== BranchReviewStatus::Approved || ! in_array($current->operational_status, [BranchOperationalStatus::Paused, BranchOperationalStatus::Suspended], true)) {
                    throw new DomainException('Chỉ chi nhánh đã duyệt và đang tạm dừng mới được khôi phục.');
                }
                $this->assertBusinessReady($current);
                $this->assertComplete($current);
                $target = [BranchStatus::Active, BranchReviewStatus::Approved, BranchOperationalStatus::Active];
            } elseif (in_array($action, ['SUSPEND', 'PAUSE'], true)) {
                $this->assertReason($reason);
                if (in_array($current->operational_status, [BranchOperationalStatus::Closed, BranchOperationalStatus::Archived], true)) {
                    throw new DomainException('Chi nhánh đã đóng hoặc lưu trữ.');
                }
                $target = [BranchStatus::Inactive, $current->review_status, $action === 'PAUSE' ? BranchOperationalStatus::Paused : BranchOperationalStatus::Suspended];
            } elseif ($action === 'CLOSE') {
                $this->assertReason($reason);
                if (in_array($current->operational_status, [BranchOperationalStatus::Closed, BranchOperationalStatus::Archived], true)) {
                    throw new DomainException('Chi nhánh đã đóng hoặc lưu trữ.');
                }
                if (! app(\App\Services\Operations\ImpactService::class)->refreshForClosure($current)) {
                    app(\App\Services\Operations\ImpactService::class)->openForBranch($current, $actor->id, 'CLOSE', $reason);
                    return null;
                }
                $target = [BranchStatus::Inactive, $current->review_status, BranchOperationalStatus::Closed];
            } else {
                throw new DomainException('Transition chi nhánh không hợp lệ.');
            }

            [$status, $reviewStatus, $operationalStatus] = $target;
            $fromStatus = $current->status;
            $fromReview = $current->review_status;
            $fromOperational = $current->operational_status;

            $current->forceFill([
                'status' => $status,
                'review_status' => $reviewStatus,
                'operational_status' => $operationalStatus,
                'review_note' => in_array($action, ['REQUEST_INFO', 'REJECT', 'SUSPEND'], true) ? trim((string) $reason) : $current->review_note,
                'submitted_at' => $action === 'SUBMIT' ? now() : $current->submitted_at,
                'reviewed_at' => in_array($action, ['APPROVE', 'REQUEST_INFO', 'REJECT'], true) ? now() : $current->reviewed_at,
                'published_at' => $action === 'PUBLISH' ? now() : $current->published_at,
            ])->save();

            if (in_array($action, ['SUSPEND', 'PAUSE'], true)) {
                app(\App\Services\Operations\ImpactService::class)->openForBranch($current, $actor->id, 'SUSPEND', $reason);
            }

            $version = ((int) BranchStateTransition::query()->where('branch_id', $current->id)->max('version')) + 1;

            BranchStateTransition::query()->create([
                'branch_id' => $current->id,
                'actor_id' => $actor->id,
                'action' => $action,
                'from_status' => $fromStatus->value,
                'to_status' => $status->value,
                'from_review_status' => $fromReview->value,
                'to_review_status' => $reviewStatus->value,
                'from_operational_status' => $fromOperational->value,
                'to_operational_status' => $operationalStatus->value,
                'reason' => filled($reason) ? trim($reason) : null,
                'version' => $version,
            ]);

            return $current;
        }, 3);
        if ($result === null) {
            throw new DomainException('Cần giải quyết và hoàn tất hồ sơ lịch bị ảnh hưởng trước khi đóng chi nhánh.');
        }
        return $result;
    }

    private function assertPendingReview(Branch $branch): void
    {
        if ($branch->review_status !== BranchReviewStatus::PendingReview) {
            throw new DomainException('Chi nhánh không chờ review.');
        }
    }

    private function assertBusinessReady(Branch $branch): void
    {
        if (! in_array($branch->business->status, [BusinessStatus::Approved, BusinessStatus::Active], true)) {
            throw new DomainException('Doanh nghiệp chưa được duyệt.');
        }
    }

    private function assertComplete(Branch $branch): void
    {
        $missing = collect([
            'public_name' => $branch->public_name,
            'address_line' => $branch->address_line,
            'phone' => $branch->phone,
            'timezone' => $branch->timezone,
        ])->filter(fn ($value) => blank($value))->keys()->all();

        if ($missing !== []) {
            throw new DomainException('Thiếu thông tin chi nhánh: '.implode(', ', $missing));
        }

        if ($branch->workingHours->count() !== 7 || $branch->workingHours->every(fn ($hour) => $hour->is_closed)) {
            throw new DomainException('Giờ mở cửa phải đủ 7 ngày và có ít nhất một ngày hoạt động.');
        }
    }

    private function assertReason(?string $reason): void
    {
        if (blank($reason)) {
            throw new DomainException('Lý do là bắt buộc.');
        }
    }
}
