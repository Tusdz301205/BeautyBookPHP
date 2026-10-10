import { useEffect } from 'react';
import { useHref, useLocation } from 'react-router-dom';
import { PublicFooter, PublicHeader } from '../public/PublicChrome';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../store/authStore';
import { CUSTOMER_ACCOUNT_REQUIRED, isCustomerAccount } from '../../utils/authScope';

export function PublicShell({ children, compact = false, showFooter = true, preview = false }) {
  const location = useLocation();
  const basePath = useHref('/').replace(/\/$/, '');
  const user = useAuthStore((state) => state.user);
  const authenticated = useAuthStore((state) => state.isAuthenticated());
  const interceptCustomerLink = (event) => {
    const href = event.target.closest?.('a')?.getAttribute('href');
    const customerPath = basePath && href?.startsWith(`${basePath}/`) ? href.slice(basePath.length) : href;
    if (!customerPath || !/^\/(book(?:[/?#]|$)|customer(?:[/?#]|$))/.test(customerPath)) return;
    if (preview || (authenticated && !isCustomerAccount(user))) {
      event.preventDefault(); event.stopPropagation();
      toast.error(preview ? 'Chế độ xem trước không thực hiện thao tác khách hàng.' : CUSTOMER_ACCOUNT_REQUIRED);
    }
  };

  useEffect(() => {
    if (!location.hash) return;
    const target = document.getElementById(location.hash.slice(1));
    if (target) window.requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
  }, [location.pathname, location.hash]);

  return (
    <div className="bb-public-shell" onClickCapture={interceptCustomerLink} onAuxClickCapture={interceptCustomerLink}>
      <PublicHeader />
      <main id="main-content" className={compact ? 'bb-public-main bb-public-main--full' : 'bb-public-main bb-public-main--contained'}>
        {preview && <div role="status" className="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900"><strong>Chế độ xem trước</strong><p>Dữ liệu đã lưu, kể cả nội dung chưa công khai. Không xuất bản hoặc thực hiện thao tác khách hàng trong chế độ này.</p></div>}
        {children}
      </main>
      {showFooter ? <PublicFooter /> : null}
    </div>
  );
}
