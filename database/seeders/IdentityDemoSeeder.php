<?php

namespace Database\Seeders;

use App\Enums\AccountKind;
use App\Enums\BranchOperationalStatus;
use App\Enums\BranchReviewStatus;
use App\Enums\BranchStatus;
use App\Enums\BusinessStatus;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\BusinessOwnerProfile;
use App\Models\BusinessService;
use App\Models\Business;
use App\Models\CanonicalService;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IdentityDemoSeeder extends Seeder
{
    public function run(RoleAssignmentService $assignments): void
    {
        Role::query()->upsert([
            ['name' => RoleName::Customer->value, 'label' => 'Khách hàng'],
            ['name' => RoleName::BusinessOwner->value, 'label' => 'Chủ doanh nghiệp'],
            ['name' => RoleName::Receptionist->value, 'label' => 'Lễ tân'],
            ['name' => RoleName::Staff->value, 'label' => 'Nhân viên dịch vụ'],
            ['name' => RoleName::PlatformAdmin->value, 'label' => 'Quản trị nền tảng'],
        ], ['name'], ['label']);

        Permission::query()->upsert([
            ['code' => 'customer.self', 'label' => 'Quản lý dữ liệu khách hàng của chính mình'],
            ['code' => 'business.manage', 'label' => 'Quản lý Business được cấp quyền'],
            ['code' => 'branch.operate', 'label' => 'Vận hành Branch được cấp quyền'],
            ['code' => 'staff.schedule.view', 'label' => 'Xem lịch dịch vụ được giao'],
            ['code' => 'platform.review', 'label' => 'Thực hiện nghiệp vụ kiểm duyệt nền tảng'],
        ], ['code'], ['label']);

        DB::table('role_permissions')->upsert([
            ['role_name' => RoleName::Customer->value, 'permission_code' => 'customer.self'],
            ['role_name' => RoleName::BusinessOwner->value, 'permission_code' => 'business.manage'],
            ['role_name' => RoleName::Receptionist->value, 'permission_code' => 'branch.operate'],
            ['role_name' => RoleName::Staff->value, 'permission_code' => 'staff.schedule.view'],
            ['role_name' => RoleName::PlatformAdmin->value, 'permission_code' => 'platform.review'],
        ], ['role_name', 'permission_code']);

        $customer = $this->actor('customer@beautybook.local', 'Khách hàng Demo', AccountKind::Customer);
        $owner = $this->actor('owner@beautybook.local', 'Chủ BeautyBook Demo', AccountKind::Operational);
        $ownerTwo = $this->actor('owner2@beautybook.local', 'Chủ Glow Studio', AccountKind::Operational);
        $receptionist = $this->actor('receptionist@beautybook.local', 'Lễ tân Demo', AccountKind::Operational);
        $staff = $this->actor('staff@beautybook.local', 'Nhân viên Demo', AccountKind::Operational);
        $admin = $this->actor('admin@beautybook.local', 'Quản trị Demo', AccountKind::Operational);
        CustomerProfile::query()->firstOrCreate(['user_id' => $customer->id]);

        $business = $this->business($owner, 'beautybook-demo', 'BeautyBook Demo', 'Công ty TNHH BeautyBook Demo', '0312345678', 'hello@beautybook.local', '0901234567', 'Tân Tạo, Bình Tân, TP.HCM');
        $businessTwo = $this->business($ownerTwo, 'glow-studio', 'Glow Studio', 'Công ty TNHH Glow Studio', '0312345679', 'hello@glow.local', '0901234568', 'Quận 3, TP.HCM');

        $publicBranches = [
            [$business, 'tan-tao', 'BeautyBook Tân Tạo', 'Tân Tạo, Bình Tân, TP.HCM'],
            [$business, 'binh-tri-dong', 'BeautyBook Bình Trị Đông', 'Bình Trị Đông, Bình Tân, TP.HCM'],
            [$business, 'tan-phu', 'BeautyBook Tân Phú', 'Tân Phú, TP.HCM'],
            [$business, 'quan-6', 'BeautyBook Quận 6', 'Quận 6, TP.HCM'],
            [$businessTwo, 'vo-van-tan', 'Glow Võ Văn Tần', 'Võ Văn Tần, Quận 3, TP.HCM'],
            [$businessTwo, 'nguyen-dinh-chieu', 'Glow Nguyễn Đình Chiểu', 'Nguyễn Đình Chiểu, Quận 3, TP.HCM'],
            [$businessTwo, 'phu-nhuan', 'Glow Phú Nhuận', 'Phú Nhuận, TP.HCM'],
            [$businessTwo, 'go-vap', 'Glow Gò Vấp', 'Gò Vấp, TP.HCM'],
        ];

        $branches = collect($publicBranches)->map(fn ($row) => $this->branch($row[0], $row[1], $row[2], $row[3], true));
        $branch = $branches->firstWhere('slug', 'tan-tao');

        $this->branch($business, 'coming-soon', 'BeautyBook Coming Soon', 'Bình Tân, TP.HCM', false);
        $suspended = $this->branch($businessTwo, 'suspended-demo', 'Glow Suspended', 'Quận 3, TP.HCM', true);
        $suspended->forceFill([
            'status' => BranchStatus::Inactive,
            'operational_status' => BranchOperationalStatus::Suspended,
        ])->save();

        $hair = ServiceCategory::query()->updateOrCreate(['slug' => 'hair'], ['name' => 'Tóc']);
        $nails = ServiceCategory::query()->updateOrCreate(['slug' => 'nails'], ['name' => 'Nail']);
        $spa = ServiceCategory::query()->updateOrCreate(['slug' => 'spa'], ['name' => 'Chăm sóc da']);

        $beauty = CanonicalService::query()->updateOrCreate(
            ['code' => 'BEAUTY'],
            ['slug' => 'beauty', 'name' => 'Làm đẹp', 'description' => 'Nhóm canonical gốc cho catalog demo.', 'status' => 'ACTIVE'],
        );
        $hairCut = $this->canonical('HAIR_CUT', 'hair-cut', 'Cắt & tạo kiểu tóc', $beauty);
        $headSpa = $this->canonical('HEAD_SPA', 'head-spa', 'Gội đầu dưỡng sinh', $beauty);
        $nailCare = $this->canonical('NAIL_CARE', 'nail-care', 'Chăm sóc nail', $beauty);
        $skinCare = $this->canonical('SKIN_CARE', 'skin-care', 'Chăm sóc da', $beauty);
        $facialMassage = $this->canonical('FACIAL_MASSAGE', 'facial-massage', 'Massage mặt', $beauty);

        $this->seedOfferings($business, $branches->where('business_id', $business->id), [
            [$hair, $hairCut, 'cat-tao-kieu', 'Cắt & tạo kiểu', 180000, 60],
            [$nails, $nailCare, 'nail-gel', 'Nail gel', 260000, 75],
            [$spa, $skinCare, 'cham-soc-da', 'Chăm sóc da cơ bản', 390000, 90],
        ]);
        $this->seedOfferings($businessTwo, $branches->where('business_id', $businessTwo->id), [
            [$hair, $headSpa, 'goi-duong-sinh', 'Gội đầu dưỡng sinh', 220000, 75],
            [$nails, $nailCare, 'nail-basic', 'Nail basic', 190000, 60],
            [$spa, $facialMassage, 'massage-mat', 'Massage mặt', 420000, 90],
        ]);

        $assignments->grant($customer, RoleName::Customer);
        $assignments->grant($owner, RoleName::BusinessOwner, $business);
        $assignments->grant($ownerTwo, RoleName::BusinessOwner, $businessTwo);
        $assignments->grant($receptionist, RoleName::Receptionist, $business, $branch);
        $assignments->grant($staff, RoleName::Staff, $business, $branch);
        $assignments->grant($admin, RoleName::PlatformAdmin);

        $staffProfile = StaffProfile::query()->updateOrCreate(
            ['user_id' => $staff->id],
            [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'full_name' => $staff->name,
                'position' => 'Chuyên viên dịch vụ',
                'bio' => 'Hồ sơ demo dùng để kiểm thử capability theo offering và chi nhánh.',
                'employee_code' => 'BB-TT-001',
                'experience_years' => 3,
                'public_visible' => true,
                'is_bookable' => true,
                'status' => 'ACTIVE',
                'hired_at' => '2025-01-01',
            ],
        );
        $staffProfile->assignments()->updateOrCreate(
            ['branch_id' => $branch->id, 'start_date' => '2025-01-01'],
            [
                'business_id' => $business->id,
                'status' => 'ACTIVE',
                'job_title' => 'Chuyên viên dịch vụ',
                'is_primary' => true,
                'is_bookable' => true,
                'end_date' => null,
            ],
        );
        $staffProfile->offerings()->sync(
            BranchServiceOffering::query()
                ->where('business_id', $business->id)
                ->where('branch_id', $branch->id)
                ->where('bookable', true)
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['business_id' => $business->id, 'branch_id' => $branch->id]])
                ->all(),
        );

        $foundation = User::query()->where('email', 'foundation@beautybook.local')->first();

        if ($foundation) {
            CustomerProfile::query()->firstOrCreate(['user_id' => $foundation->id]);
            $assignments->grant($foundation, RoleName::Customer);
        }
    }

    private function actor(string $email, string $name, AccountKind $kind): User
    {
        $user = User::query()->firstOrCreate([
            'email' => $email,
        ], [
            'name' => $name,
            'account_kind' => $kind,
            'password' => 'BeautyBook123!',
        ]);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    private function business(User $owner, string $slug, string $name, string $company, string $taxCode, string $email, string $phone, string $address): Business
    {
        $profile = BusinessOwnerProfile::query()->updateOrCreate(
            ['user_id' => $owner->id],
            ['company_name' => $company, 'tax_code' => $taxCode],
        );

        return Business::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'owner_profile_id' => $profile->id,
                'name' => $name,
                'description' => 'Không gian làm đẹp demo dùng cho kiểm thử marketplace BeautyBook.',
                'contact_email' => $email,
                'contact_phone' => $phone,
                'address_line' => $address,
                'status' => BusinessStatus::Active,
                'review_note' => null,
                'submitted_at' => now()->subDays(5),
                'reviewed_at' => now()->subDays(4),
            ],
        );
    }

    private function branch(Business $business, string $slug, string $name, string $address, bool $public): Branch
    {
        $branch = Branch::query()->updateOrCreate(
            ['business_id' => $business->id, 'slug' => $slug],
            [
                'name' => $name,
                'public_name' => $name,
                'description' => 'Chi nhánh demo với dữ liệu công khai phục vụ tìm kiếm và kiểm thử.',
                'address_line' => $address,
                'phone' => '0900000000',
                'email' => $slug.'@beautybook.local',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'status' => $public ? BranchStatus::Active : BranchStatus::Pending,
                'review_status' => $public ? BranchReviewStatus::Approved : BranchReviewStatus::Draft,
                'operational_status' => $public ? BranchOperationalStatus::Active : BranchOperationalStatus::Inactive,
                'submitted_at' => $public ? now()->subDays(3) : null,
                'reviewed_at' => $public ? now()->subDays(2) : null,
                'published_at' => $public ? now()->subDay() : null,
            ],
        );

        foreach (range(0, 6) as $day) {
            $branch->workingHours()->updateOrCreate(
                ['day_of_week' => $day],
                $day === 0
                    ? ['is_closed' => true, 'open_time' => null, 'close_time' => null]
                    : ['is_closed' => false, 'open_time' => '09:00', 'close_time' => '20:00'],
            );
        }

        return $branch;
    }

    private function canonical(string $code, string $slug, string $name, CanonicalService $parent): CanonicalService
    {
        return CanonicalService::query()->updateOrCreate(
            ['code' => $code],
            [
                'slug' => $slug,
                'name' => $name,
                'parent_id' => $parent->id,
                'synonyms' => [],
                'status' => 'ACTIVE',
            ],
        );
    }

    private function seedOfferings(Business $business, $branches, array $services): void
    {
        foreach ($services as [$category, $canonical, $slug, $name, $price, $duration]) {
            $service = BusinessService::query()->updateOrCreate(
                ['business_id' => $business->id, 'slug' => $slug],
                [
                    'service_category_id' => $category->id,
                    'canonical_service_id' => $canonical->id,
                    'name' => $name,
                    'mapping_status' => 'MAPPED',
                    'base_price' => $price,
                    'base_duration_minutes' => $duration,
                    'status' => 'ACTIVE',
                    'is_active' => true,
                ],
            );

            foreach ($branches as $branch) {
                BranchServiceOffering::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'business_service_id' => $service->id],
                    [
                        'business_id' => $business->id,
                        'price' => $price,
                        'duration_minutes' => $duration,
                        'bookable' => true,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
