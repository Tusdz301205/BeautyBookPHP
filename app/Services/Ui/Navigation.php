<?php

namespace App\Services\Ui;

use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;

class Navigation
{
    public function forRequest(Request $request): array
    {
        if ($request->attributes->has('ui.navigation')) return $request->attributes->get('ui.navigation');
        $workspace = app(SessionContext::class)->current($request);
        $role = $workspace?->role_name ?? ($request->user()?->account_kind->value === 'CUSTOMER' ? 'CUSTOMER' : null);
        $labels = ['CUSTOMER' => 'Khách hàng', 'BUSINESS_OWNER' => 'Chủ cơ sở', 'RECEPTIONIST' => 'Lễ tân', 'STAFF' => 'Chuyên viên', 'PLATFORM_ADMIN' => 'Quản trị nền tảng'];
        $groups = [];
        $add = function (string $group, string $label, string $route, string $icon, array $patterns = [], array $parameters = []) use (&$groups) {
            $groups[$group][] = ['label' => $label, 'url' => route($route, $parameters), 'icon' => $icon, 'active' => $patterns ? $this->active($patterns) : request()->routeIs($route)];
        };
        $add('Không gian làm việc', 'Tổng quan', 'app', 'grid');
        if ($role && $role !== 'PLATFORM_ADMIN') $add('Không gian làm việc', $role === 'STAFF' ? 'Lịch của tôi' : 'Lịch hẹn', 'bookings.index', 'calendar', ['bookings.*','booking.*']);
        if ($role === 'CUSTOMER') {
            $add('Không gian làm việc', 'Khám phá dịch vụ', 'marketplace.index', 'search', ['marketplace.*']);
            $add('Ưu đãi & dịch vụ', 'Dịch vụ đã lưu', 'engagement.favorites', 'heart');
            $add('Ưu đãi & dịch vụ', 'Voucher của tôi', 'vouchers.mine', 'ticket');
            $add('Ưu đãi & dịch vụ', 'Điểm tích lũy', 'loyalty.mine', 'star');
            $add('Ưu đãi & dịch vụ', 'Lịch định kỳ', 'recurring.index', 'repeat', ['recurring.*']);
            $add('Ưu đãi & dịch vụ', 'Danh sách chờ', 'waitlist.mine', 'clock');
            $add('Ưu đãi & dịch vụ', 'Thanh toán', 'finance.index', 'wallet', ['finance.index','finance.show']);
            $add('Ưu đãi & dịch vụ', 'Liệu trình & hóa đơn', 'finance.advanced', 'receipt', ['finance.advanced','finance.packages','finance.invoice']);
            $add('Ưu đãi & dịch vụ', 'Đánh giá của tôi', 'engagement.reviews', 'message');
            $add('Tài khoản', 'Hồ sơ cá nhân', 'profile.show', 'user');
            $add('Tài khoản', 'Quyền riêng tư', 'privacy.index', 'shield', ['privacy.*']);
        }
        if ($role === 'BUSINESS_OWNER') {
            $add('Quản lý cơ sở', 'Doanh nghiệp & chi nhánh', 'owner.businesses.index', 'building', ['owner.businesses.*','owner.branches.*']);
            $branch = $workspace->branch ?? $workspace->business?->branches()->orderBy('id')->first();
            if ($branch) {
                $add('Quản lý cơ sở', 'Dịch vụ', 'owner.catalog.index', 'sparkles', ['owner.catalog.*'], [$branch->business_id]);
                $add('Quản lý cơ sở', 'Đội ngũ', 'owner.staff.index', 'users', ['owner.staff.*'], [$branch->business_id]);
                $add('Quản lý cơ sở', 'Combo', 'owner.combos.index', 'layers', ['owner.combos.*'], [$branch->id]);
                $add('Quản lý cơ sở', 'Danh sách chờ', 'operations.waitlist', 'clock', ['operations.waitlist'], [$branch->id]);
            }
            if ($workspace->business_id) $add('Quản lý cơ sở', 'Khuyến mãi', 'owner.promotions.index', 'ticket', ['owner.promotions.*'], [$workspace->business_id]);
            $add('Quản lý cơ sở', 'Đánh giá', 'engagement.reviews', 'message');
        }
        if (in_array($role, ['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN'], true)) {
            $add('Tài chính & vận hành', 'Thanh toán & hoàn tiền', 'finance.index', 'wallet', ['finance.index','finance.show']);
            $add('Tài chính & vận hành', 'Liệu trình & hóa đơn', 'finance.advanced', 'receipt', ['finance.advanced','finance.invoice','finance.packages']);
        }
        if ($role === 'RECEPTIONIST' && $workspace->branch_id) $add('Tài chính & vận hành', 'Danh sách chờ', 'operations.waitlist', 'clock', ['operations.waitlist'], [$workspace->branch_id]);
        if (in_array($role, ['BUSINESS_OWNER','PLATFORM_ADMIN'], true)) {
            $add('Tài chính & vận hành', 'Báo cáo', 'governance.dashboard', 'chart', ['governance.dashboard','platform.report']);
            $add('Tài chính & vận hành', 'Lịch bị ảnh hưởng', 'impacts.index', 'alert', ['impacts.*']);
            $add('Tài chính & vận hành', 'Nhật ký hoạt động', 'platform.audit', 'history');
        }
        if ($role === 'PLATFORM_ADMIN') {
            $add('Quản trị nền tảng', 'Lịch toàn nền tảng', 'bookings.index', 'calendar', ['bookings.*']);
            $add('Quản trị nền tảng', 'Doanh nghiệp & chi nhánh', 'platform.directory', 'building');
            $add('Quản trị nền tảng', 'Người dùng', 'governance.users', 'users', ['governance.users','governance.user']);
            $add('Quản trị nền tảng', 'Kiểm duyệt', 'admin.reviews.index', 'check', ['admin.reviews.*']);
            $add('Quản trị nền tảng', 'Ưu đãi', 'admin.promotions.index', 'ticket');
            $add('Quản trị nền tảng', 'Độ tin cậy', 'governance.trust', 'shield');
            $add('Quản trị nền tảng', 'Cài đặt', 'platform.settings', 'settings');
        }
        $add('Tài khoản', 'Thông báo', 'notifications.index', 'bell');
        if ($request->user()?->account_kind->value === 'OPERATIONAL') {
            $add('Tài khoản', 'Chuyển chủ', 'ownership.index', 'exchange', ['ownership.*']);
            $add('Tài khoản', 'Đổi không gian làm việc', 'workspace.index', 'layers');
        }
        $add('Tài khoản', 'Bảo mật', 'security.index', 'lock');
        $isPublic = $request->routeIs('home','marketplace.*','login','register','password.*','verification.*','engagement.public','combos.public') || ($role === 'CUSTOMER' && $request->routeIs('booking.create','combos.booking.create'));
        $result = compact('workspace', 'role', 'groups') + ['roleLabel' => $labels[$role] ?? 'Chọn không gian làm việc', 'isApp' => $request->user() && ! $isPublic];
        $request->attributes->set('ui.navigation', $result);
        return $result;
    }

    private function active(array $patterns): bool
    {
        return request()->routeIs(...$patterns);
    }
}
