import { Bell, CalendarCheck, ChevronRight, Heart, KeyRound, MessageCircle, Repeat2, Star, TicketPercent, UserRound, Wallet } from 'lucide-react';
import { NavLink } from 'react-router-dom';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';
import '../../styles/glowme/shared-account-menu.css';

export const accountGroups = [
  { label: 'Lịch hẹn & trải nghiệm', items: [
    ['/customer/appointments', 'Lịch hẹn của tôi', CalendarCheck, 'Theo dõi lịch và các dịch vụ đã đặt'],
    ['/customer/recurring', 'Lịch hẹn định kỳ', Repeat2, 'Xem và quản lý các lần hẹn'],
    ['/customer/messages', 'Tin nhắn', MessageCircle, 'Trao đổi với chi nhánh'],
    ['/customer/benefits', 'Dịch vụ đã lưu & quyền lợi', Heart, 'Những trải nghiệm bạn yêu thích'],
    ...(FINANCE_WORKSPACE_ENABLED ? [['/customer/wallet', 'Ví của tôi', Wallet, 'Nạp tiền, xem số dư và giao dịch']] : []),
    ['/customer/vouchers', 'Ưu đãi', TicketPercent, 'Voucher và ưu đãi dành cho bạn'],
    ['/customer/reviews', 'Đánh giá của tôi', Star, 'Chia sẻ sau cuộc hẹn'],
  ] },
  { label: 'Tài khoản', items: [
    ['/customer/profile', 'Hồ sơ cá nhân', UserRound, 'Thông tin và cài đặt tài khoản'],
    ['/customer/security', 'Bảo mật', KeyRound, 'Mật khẩu và phiên đăng nhập'],
    ['/customer/notifications', 'Thông báo', Bell, 'Cập nhật về lịch hẹn của bạn'],
  ] },
];
export default function AccountMenuLinks({ onNavigate, variant = 'customer' }) {
  return <nav className={`gm-account-links bb-customer-account__groups gm-account-links--${variant}`} aria-label="Chức năng tài khoản">{accountGroups.map(group => <section key={group.label} className="bb-customer-account__group"><h2>{group.label}</h2><div>{group.items.map(([to, label, Icon, description]) => <NavLink key={to} to={to} onClick={onNavigate} className={({ isActive }) => `bb-customer-account__item gm-account-links__item${isActive ? ' bb-customer-account__item--active' : ''}`}><span className="bb-customer-account__item-icon" aria-hidden="true"><Icon size={18} /></span><span className="gm-account-links__copy"><strong>{label}</strong><small>{description}</small></span><ChevronRight size={14} aria-hidden="true" /></NavLink>)}</div></section>)}</nav>;
}
