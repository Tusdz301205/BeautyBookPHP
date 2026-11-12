<?php

namespace App\Services\GlowMeImport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Source identities/catalog projected into the existing Laravel schema; no demo seeder is invoked. */
final class CoreImport
{
    private array $records = [];

    public function run(ImportContext $c): void
    {
        foreach ($c->snapshot['tables'] as $key => $data) {
            foreach ($data['rows'] as $row) {
                if (isset($row['id'])) {
                    $this->records[substr($key, strpos($key, '.') + 1)][$row['id']] = $row;
                }
            }
        }
        $this->identities($c);
        $this->businesses($c);
        $this->catalog($c);
        $this->staff($c);
        $this->media($c);
        $this->documents($c);
        $this->grants($c);
        $c->note('Core: source APPROVED businesses/branches project to native ACTIVE/public-ready; original review/deletion/address fields remain archived. Source service UUIDs identify both business_services and branch_service_offerings. Category/service slugs are namespaced for native uniqueness.');
        $c->note('Core: staff resources retain no login identity because source has no staff user. Native staff branch assignments derive from each source profile branch and created_at. Source inactive capabilities/images remain archive-only where target has no active flag.');
        $c->note('Security: source sessions, recovery tokens, reset codes, push devices and delivery queues are preserved only in the exact archive and are not activated in PHP.');
    }

    private function identities(ImportContext $c): void
    {
        $operational = [];
        foreach ($c->source('user_roles') as $r) {
            if ($this->record('roles', $r['role_id'])['code'] !== 'CUSTOMER') {
                $operational[$r['user_id']] = true;
            }
        }
        foreach ($c->source('users') as $r) {
            $c->insert('users', [
                'id' => $c->id('users', $r['id']), 'name' => $r['full_name'], 'email' => $r['email'], 'password' => $r['password_hash'],
                'account_kind' => isset($operational[$r['id']]) ? 'OPERATIONAL' : 'CUSTOMER', 'is_active' => $r['is_active'] && ! $r['deleted_at'],
                'email_verified_at' => $r['is_email_verified'] ? $r['updated_at'] : null,
            ] + $this->times($r));
        }
        foreach ($c->source('customer_profiles') as $r) {
            $user = $this->record('users', $r['user_id']);
            $c->insert('customer_profiles', ['id' => $c->id('customer_profiles', $r['id']), 'user_id' => $c->id('users', $r['user_id']), 'phone' => $user['phone'], 'date_of_birth' => $user['date_of_birth'], 'address' => $r['address'] ?? $user['address'], 'note' => $r['note']] + $this->times($r));
        }
        foreach ($c->source('business_owner_profiles') as $r) {
            $c->insert('business_owner_profiles', ['id' => $c->id('business_owner_profiles', $r['id']), 'user_id' => $c->id('users', $r['user_id']), 'company_name' => $r['company_name'], 'tax_code' => $r['tax_code']] + $this->times($r));
        }
        foreach (['CUSTOMER' => 'Khách hàng', 'BUSINESS_OWNER' => 'Chủ doanh nghiệp', 'RECEPTIONIST' => 'Lễ tân', 'STAFF' => 'Nhân viên dịch vụ', 'PLATFORM_ADMIN' => 'Quản trị nền tảng'] as $code => $label) {
            $c->insert('roles', ['name' => $code, 'label' => $label]);
        }
        foreach (['CUSTOMER' => 'customer.self', 'BUSINESS_OWNER' => 'business.manage', 'RECEPTIONIST' => 'branch.operate', 'STAFF' => 'staff.schedule.view', 'PLATFORM_ADMIN' => 'platform.review'] as $role => $permission) {
            $c->insert('permissions', ['code' => $permission, 'label' => $permission]);
            $c->insert('role_permissions', ['role_name' => $role, 'permission_code' => $permission]);
        }
    }

    private function businesses(ImportContext $c): void
    {
        foreach ($c->source('businesses') as $r) {
            $id = $c->id('businesses', $r['id']);
            $c->insert('businesses', ['id' => $id, 'owner_profile_id' => $c->id('business_owner_profiles', $r['owner_id']), 'name' => $r['name'], 'slug' => $r['slug'], 'description' => $r['description'], 'contact_email' => $r['contact_email'], 'contact_phone' => $r['contact_phone'], 'address_line' => $r['registered_address'],
                'status' => match ($r['status']) {
                    'APPROVED' => $r['deleted_at'] ? 'SUSPENDED' : 'ACTIVE', 'PENDING' => 'PENDING_REVIEW', default => $r['status']
                }, 'reviewed_at' => $r['reviewed_at'], 'review_note' => $r['review_note'], 'legal_entity_type' => $r['legal_entity_type'], 'legal_name' => $r['legal_name'], 'registration_number' => $r['registration_number'], 'registered_address' => $r['registered_address']] + $this->times($r));
            $c->insert('beautybook_business_settings', ['business_id' => $id, 'metadata' => $this->camel($r)] + $this->times($r));
        }
        $policies = [];
        foreach ($c->source('branch_booking_policies') as $r) {
            $policies[$r['branch_id']] = $r;
        }
        foreach ($c->source('branches') as $r) {
            $id = $c->id('branches', $r['id']);
            $approved = $r['status'] === 'APPROVED' && ! $r['deleted_at'];
            $c->insert('branches', ['id' => $id, 'business_id' => $c->id('businesses', $r['business_id']), 'name' => $r['name'], 'public_name' => $r['name'], 'slug' => Str::slug($r['name']).'-'.$id, 'description' => $r['description'], 'address_line' => $r['address'], 'phone' => $r['contact_phone'], 'timezone' => $r['timezone'],
                'status' => $approved ? 'ACTIVE' : 'PENDING', 'review_status' => match ($r['status']) {
                    'APPROVED' => 'APPROVED', 'PENDING' => 'PENDING_REVIEW', 'SUSPENDED' => 'APPROVED', default => $r['status']
                }, 'operational_status' => $approved ? 'ACTIVE' : 'INACTIVE', 'reviewed_at' => $r['reviewed_at'], 'published_at' => $approved ? $r['reviewed_at'] : null, 'review_note' => $r['review_note'], 'registration_type' => $r['registration_type']] + $this->times($r));
            $metadata = $this->camel($r);
            $metadata['districtId'] = $c->id('districts', $r['district_id']);
            if (! $r['district_id'] && $r['ward_id']) {
                $metadata['districtId'] = 'glowme-ward-'.$c->id('wards', $r['ward_id']);
                $metadata['addressLevel'] = 'WARD';
            }
            if ($r['district_id']) {
                $district = $this->record('districts', $r['district_id']);
                $province = $this->record('provinces', $district['province_id']);
                $metadata['district'] = ['id' => (string) $metadata['districtId'], 'name' => $district['name'], 'provinceName' => $province['name']];
            }
            $policy = isset($policies[$r['id']]) ? $this->camel($policies[$r['id']]) : [];
            $metadata['pendingHoldMinutes'] = $policy['pendingHoldMinutes'] ?? null;
            $metadata['bookingConfirmationMode'] = $policy['confirmationMode'] ?? 'MANUAL';
            $policy['gracePeriodMinutes'] = $policy['noShowGraceMinutes'] ?? 15;
            $c->insert('beautybook_branch_settings', ['branch_id' => $id, 'metadata' => $metadata, 'booking_policy' => $policy] + $this->times($r));
        }
        foreach ($c->source('branch_working_hours') as $r) {
            $c->insert('branch_working_hours', ['id' => $c->id('branch_working_hours', $r['id']), 'branch_id' => $c->id('branches', $r['branch_id']), 'day_of_week' => $r['day_of_week'], 'is_closed' => $r['is_off'], 'open_time' => $r['is_off'] ? null : $this->time($r['open_minute']), 'close_time' => $r['is_off'] ? null : $this->time($r['close_minute'])]);
        }
        foreach ($c->source('branch_holidays') as $r) {
            $c->insert('beautybook_branch_calendar', ['id' => $c->id('branch_holidays', $r['id']), 'branch_id' => $c->id('branches', $r['branch_id']), 'date' => $r['date'], 'name' => $r['reason'], 'is_closed' => $r['mode'] === 'CLOSED', 'open_time' => $r['mode'] === 'CLOSED' ? null : $this->time($r['open_minute']), 'close_time' => $r['mode'] === 'CLOSED' ? null : $this->time($r['close_minute'])] + $this->times($r));
        }
    }

    private function catalog(ImportContext $c): void
    {
        foreach ($c->source('service_categories') as $r) {
            $id = $c->id('service_categories', $r['id']);
            $c->insert('service_categories', ['id' => $id, 'name' => $r['name'], 'slug' => 'glowme-'.$id.'-'.$r['slug']] + $this->times($r));
            $c->insert('canonical_services', ['id' => $id, 'code' => 'GLOWME_CATEGORY_'.$id, 'name' => $r['name'], 'slug' => 'glowme-'.$id.'-'.$r['slug'], 'status' => $r['deleted_at'] ? 'DEPRECATED' : 'ACTIVE'] + $this->times($r));
            $c->insert('beautybook_business_categories', ['business_id' => $c->id('businesses', $r['business_id']), 'service_category_id' => $id, 'source_slug' => $r['slug']] + $this->times($r));
        }
        foreach ($c->source('services') as $r) {
            $id = $c->id('services', $r['id']);
            $branch = $this->record('branches', $r['branch_id']);
            $business = $c->id('businesses', $branch['business_id']);
            $c->insert('business_services', ['id' => $id, 'business_id' => $business, 'service_category_id' => $c->id('service_categories', $r['category_id']), 'canonical_service_id' => $c->id('service_categories', $r['category_id']), 'mapping_status' => 'MAPPED', 'name' => $r['name'], 'slug' => Str::slug($r['name']).'-'.$id, 'description' => $r['description'], 'base_price' => $r['price'], 'base_duration_minutes' => $r['duration_minutes'], 'is_active' => $r['status'] === 'ACTIVE', 'status' => $r['status'], 'deleted_at' => $r['deleted_at']] + $this->times($r));
            $c->insert('branch_service_offerings', ['id' => $id, 'business_id' => $business, 'branch_id' => $c->id('branches', $r['branch_id']), 'business_service_id' => $id, 'price' => $r['price'], 'duration_minutes' => $r['duration_minutes'], 'bookable' => $r['is_bookable'], 'is_active' => $r['status'] === 'ACTIVE', 'deleted_at' => $r['deleted_at']] + $this->times($r));
        }
        foreach ($c->source('service_variants') as $r) {
            $c->insert('service_variants', ['id' => $c->id('service_variants', $r['id']), 'branch_service_offering_id' => $c->id('services', $r['service_id']), 'code' => $r['code'], 'name' => $r['name'], 'price' => $r['price'], 'duration_minutes' => $r['duration_minutes'], 'status' => $r['active'] ? 'ACTIVE' : 'INACTIVE', 'deleted_at' => $r['deleted_at']] + $this->times($r));
        }
        foreach ($c->source('combos') as $r) {
            $branch = $this->record('branches', $r['branch_id']);
            $c->insert('combos', ['id' => $c->id('combos', $r['id']), 'business_id' => $c->id('businesses', $branch['business_id']), 'branch_id' => $c->id('branches', $r['branch_id']), 'name' => $r['name'], 'description' => $r['description'], 'combo_price' => $r['price'], 'status' => $r['active'] ? 'ACTIVE' : 'INACTIVE', 'deleted_at' => $r['deleted_at']] + $this->times($r));
        }
        foreach ($c->source('combo_services') as $r) {
            $service = $this->record('services', $r['service_id']);
            $variant = $r['variant_id'] ? $this->record('service_variants', $r['variant_id']) : null;
            $c->insert('combo_items', ['combo_id' => $c->id('combos', $r['combo_id']), 'branch_service_offering_id' => $c->id('services', $r['service_id']), 'quantity' => $r['quantity'], 'sort_order' => $r['sort_order'], 'price_snapshot' => $variant['price'] ?? $service['price'], 'duration_snapshot' => $variant['duration_minutes'] ?? $service['duration_minutes']] + $this->times($r));
        }
    }

    private function staff(ImportContext $c): void
    {
        foreach ($c->source('staff_profiles') as $r) {
            $id = $c->id('staff_profiles', $r['id']);
            $branch = $this->record('branches', $r['branch_id']);
            $business = $c->id('businesses', $branch['business_id']);
            $c->insert('staff_profiles', ['id' => $id, 'business_id' => $business, 'branch_id' => $c->id('branches', $r['branch_id']), 'full_name' => $r['name'], 'bio' => $r['bio'], 'is_bookable' => $r['is_bookable'], 'public_visible' => $r['public_visible'], 'status' => $r['status'], 'deleted_at' => $r['deleted_at']] + $this->times($r));
            $c->insert('staff_branch_assignments', ['staff_profile_id' => $id, 'business_id' => $business, 'branch_id' => $c->id('branches', $r['branch_id']), 'start_date' => substr($r['created_at'], 0, 10), 'status' => $r['status'], 'is_primary' => true, 'is_bookable' => $r['is_bookable']] + $this->times($r));
        }
        foreach ($c->source('staff_services') as $r) {
            if (! $r['active']) {
                continue;
            }
            $staff = $this->record('staff_profiles', $r['staff_id']);
            $branch = $this->record('branches', $staff['branch_id']);
            $c->insert('staff_services', ['staff_profile_id' => $c->id('staff_profiles', $r['staff_id']), 'branch_service_offering_id' => $c->id('services', $r['service_id']), 'business_id' => $c->id('businesses', $branch['business_id']), 'branch_id' => $c->id('branches', $staff['branch_id'])] + $this->times($r));
        }
        foreach ($c->source('staff_working_hours') as $r) {
            $c->insert('staff_working_hours', ['id' => $c->id('staff_working_hours', $r['id']), 'staff_profile_id' => $c->id('staff_profiles', $r['staff_id']), 'day_of_week' => $r['day_of_week'], 'start_minute' => $r['start_minute'], 'end_minute' => $r['end_minute'], 'is_off' => $r['is_off']]);
        }
        foreach ($c->source('staff_time_offs') as $r) {
            $c->insert('staff_time_offs', ['id' => $c->id('staff_time_offs', $r['id']), 'staff_profile_id' => $c->id('staff_profiles', $r['staff_id']), 'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'reason' => $r['reason'], 'recorded_by_id' => $c->id('users', $r['recorded_by_id'])] + $this->times($r));
        }
    }

    private function media(ImportContext $c): void
    {
        $links = [];
        $public = [];
        foreach (['business_images' => ['businesses', 'business_id'], 'branch_images' => ['branches', 'branch_id'], 'staff_images' => ['staff_profiles', 'staff_id'], 'service_images' => ['services', 'service_id'], 'combo_images' => ['combos', 'combo_id']] as $table => [$entityTable, $field]) {
            foreach ($c->source($table) as $image) {
                if (! ($image['active'] ?? true) || ($image['deleted_at'] ?? null) || ($image['revoked_at'] ?? null) || (($image['visibility'] ?? 'PUBLIC') !== 'PUBLIC') || (($image['image_type'] ?? 'PROMOTIONAL') !== 'PROMOTIONAL')) {
                    continue;
                }
                if ($this->publicEntity($entityTable, $image[$field])) {
                    $public[$image['media_id']] = true;
                }
            }
        }
        foreach ($c->source('businesses') as $business) {
            if ($business['logo_media_id'] && $this->publicEntity('businesses', $business['id'])) {
                $public[$business['logo_media_id']] = true;
            }
        }
        foreach ($c->source('service_categories') as $category) {
            if ($category['cover_media_id'] && ! $category['deleted_at'] && $this->publicEntity('businesses', $category['business_id'])) {
                $public[$category['cover_media_id']] = true;
            }
        }
        foreach (['business_images' => ['business_id', 'BUSINESS', 'businesses'], 'branch_images' => ['branch_id', 'BRANCH', 'branches'], 'staff_images' => ['staff_id', 'STAFF', 'staff_profiles'], 'service_images' => ['service_id', 'SERVICE', 'services'], 'combo_images' => ['combo_id', 'COMBO', 'combos']] as $table => [$field, $type, $source]) {
            foreach ($c->source($table) as $r) {
                $links[$r['media_id']] = [$type, $c->id($source, $r[$field])];
            }
        }
        foreach ($c->source('review_photos') as $r) {
            $links[$r['media_id']] = ['REVIEW_PHOTO', $c->id('bookings', $r['booking_id'])];
        }
        foreach ($c->source('service_images') as $r) {
            if ($r['image_type'] === 'RESULT') {
                $links[$r['media_id']] = ['RESULT', $c->id('services', $r['service_id'])];
            }
        }
        foreach ($c->source('users') as $r) {
            if ($r['avatar_media_id']) {
                $links[$r['avatar_media_id']] = ['USER_AVATAR', $c->id('users', $r['id'])];
            }
        }
        foreach ($c->source('media_files') as $r) {
            [$type, $entity] = $links[$r['id']] ?? [$r['purpose'], $c->id('media_files', $r['id'])];
            $visibility = isset($public[$r['id']]) && ! $r['deleted_at'] && $r['visibility'] === 'PUBLIC' && ! in_array($type, ['REVIEW_PHOTO', 'RESULT', 'CONSENT_PROOF', 'USER_AVATAR', 'LEGAL_DOCUMENT']) ? 'PUBLIC' : 'PRIVATE';
            $c->insert('media_files', ['id' => $c->id('media_files', $r['id']), 'disk' => 'local', 'path' => 'glowme/media/'.$r['storage_key'], 'mime_type' => $r['mime_type'], 'file_size' => $r['size_bytes'], 'sha256' => $r['sha256'], 'uploaded_by' => $c->id('users', $r['uploader_id']), 'business_id' => $c->id('businesses', $r['business_id']), 'branch_id' => $c->id('branches', $r['branch_id']), 'entity_type' => $type, 'entity_id' => $entity, 'visibility' => $visibility] + $this->times($r));
        }
        foreach ($c->source('users') as $r) {
            if ($r['avatar_media_id']) {
                DB::table('users')->where('id', $c->id('users', $r['id']))->update(['avatar_media_file_id' => $c->id('media_files', $r['avatar_media_id'])]);
            }
            if ($r['google_subject']) {
                $c->insert('external_identities', ['user_id' => $c->id('users', $r['id']), 'provider' => 'google', 'subject' => $r['google_subject'], 'email' => $r['email']] + $this->times($r));
            }
        }
        foreach (['business_images' => ['business_id', 'businesses'], 'branch_images' => ['branch_id', 'branches'], 'staff_images' => ['staff_id', 'staff_profiles'], 'combo_images' => ['combo_id', 'combos']] as $table => [$field, $source]) {
            foreach ($c->source($table) as $r) {
                if (! ($r['active'] ?? true)) {
                    continue;
                }
                $nativeField = $field === 'staff_id' ? 'staff_profile_id' : $field;
                $c->insert($table, ['id' => $c->id($table, $r['id']), $nativeField => $c->id($source, $r[$field]), 'media_file_id' => $c->id('media_files', $r['media_id']), 'sort_order' => $r['sort_order']] + $this->times($r));
            }
        }
    }

    private function documents(ImportContext $c): void
    {
        foreach (['business_documents', 'branch_documents'] as $table) {
            foreach ($c->source($table) as $r) {
                $branch = isset($r['branch_id']) ? $this->record('branches', $r['branch_id']) : null;
                $id = $c->id($table, $r['id']) + ($table === 'branch_documents' ? count($c->source('business_documents')) : 0);
                $c->insert('beautybook_documents', ['id' => $id, 'business_id' => $c->id('businesses', $r['business_id'] ?? $branch['business_id']), 'branch_id' => $c->id('branches', $r['branch_id'] ?? null), 'document_type' => $r['document_type'], 'document_name' => $r['document_type'], 'status' => $r['status']] + $this->times($r));
                $c->insert('beautybook_document_versions', ['document_id' => $id, 'media_id' => $c->id('media_files', $r['media_id']), 'version' => 1] + $this->times($r));
            }
        }
        foreach ($c->source('branch_change_requests') as $r) {
            $c->insert('branch_change_requests', ['id' => $c->id('branch_change_requests', $r['id']), 'branch_id' => $c->id('branches', $r['branch_id']), 'requested_by_user_id' => $c->id('users', $r['requested_by_id']), 'reviewed_by_user_id' => $c->id('users', $r['reviewed_by_id']), 'media_id' => $c->id('media_files', $r['media_id']), 'base_snapshot' => $r['base_snapshot'], 'proposed_snapshot' => $r['proposed_snapshot'], 'reason' => $r['reason'], 'status' => $r['status'], 'version' => $r['version'], 'review_note' => $r['review_note'], 'reviewed_at' => $r['reviewed_at']] + $this->times($r));
        }
    }

    private function grants(ImportContext $c): void
    {
        foreach ($c->source('user_roles') as $r) {
            $role = $this->record('roles', $r['role_id'])['code'];
            $business = $c->id('businesses', $r['business_id']);
            $branch = $c->id('branches', $r['branch_id']);
            $scope = match ($role) {
                'CUSTOMER' => 'self', 'PLATFORM_ADMIN' => 'platform', 'BUSINESS_OWNER' => 'business:'.$business, default => 'branch:'.$business.':'.$branch
            };
            $c->insert('role_assignments', ['id' => $c->id('user_roles', $r['id']), 'user_id' => $c->id('users', $r['user_id']), 'role_name' => $role, 'business_id' => $business, 'branch_id' => $branch, 'scope_key' => $scope, 'granted_by_user_id' => $c->id('users', $r['granted_by_id']), 'expires_at' => $r['expires_at']] + $this->times($r));
        }
    }

    private function record(string $table, string $id): array
    {
        return $this->records[$table][$id] ?? throw new \RuntimeException('Missing core source record in '.$table);
    }

    private function publicEntity(string $table, string $id): bool
    {
        $row = $this->record($table, $id);
        if ($row['deleted_at'] ?? null) {
            return false;
        }
        if ($table === 'businesses') {
            if ($row['status'] !== 'APPROVED') {
                return false;
            }
            foreach ($this->records['branches'] as $branch) {
                if ($branch['business_id'] === $id && $branch['status'] === 'APPROVED' && ! $branch['deleted_at']) {
                    return true;
                }
            }

            return false;
        }
        if ($table === 'branches') {
            return $row['status'] === 'APPROVED' && $this->publicEntity('businesses', $row['business_id']);
        }
        if ($table === 'services' && ($row['status'] !== 'ACTIVE' || ! $row['is_bookable'])) {
            return false;
        }
        if ($table === 'staff_profiles' && ($row['status'] !== 'ACTIVE' || ! $row['is_bookable'] || ! $row['public_visible'])) {
            return false;
        }
        if ($table === 'combos' && ! $row['active']) {
            return false;
        }

        return $this->publicEntity('branches', $row['branch_id']);
    }

    private function times(array $r): array
    {
        return ['created_at' => $r['created_at'] ?? null, 'updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null];
    }

    private function time(?int $minute): ?string
    {
        return $minute === null ? null : sprintf('%02d:%02d:00', intdiv($minute, 60), $minute % 60);
    }

    private function camel(array $r): array
    {
        $result = [];
        foreach ($r as $key => $value) {
            $result[Str::camel($key)] = $value;
        }

return $result;
    }
}
