import { useEffect } from 'react';
import { useHref, useLocation } from 'react-router-dom';
import { PublicFooter, PublicHeader } from '../public/PublicChrome';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../store/authStore';
import { CUSTOMER_ACCOUNT_REQUIRED, isCustomerAccount } from '../../utils/authScope';

export function PublicShell({ children, compact = false, showFooter = true, preview = false, luxury = true }) {
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
    if (!location.hash) { window.scrollTo(0, 0); return; }
    // Inventory and fonts can change section positions after the initial navigation.
    // Follow those layout changes briefly, until the visitor starts scrolling.
    let frame;
    let stopped = false;
    const align = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        if (!stopped) document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'start', behavior: 'instant' });
      });
    };
    const observer = new ResizeObserver(align);
    const main = document.getElementById('main-content');
    if (main) observer.observe(main);
    const stop = () => { stopped = true; observer.disconnect(); cancelAnimationFrame(frame); };
    const onKeyDown = (event) => { if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].includes(event.key)) stop(); };
    window.addEventListener('wheel', stop, { passive: true });
    window.addEventListener('touchstart', stop, { passive: true });
    window.addEventListener('keydown', onKeyDown);
    const timeout = window.setTimeout(stop, 4000);
    align();
    return () => {
      stop(); window.clearTimeout(timeout);
      window.removeEventListener('wheel', stop);
      window.removeEventListener('touchstart', stop);
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [location.pathname, location.hash]);

  return (
    <div className={`bb-public-shell${luxury ? ' bb-public-shell--luxury' : ''}${location.pathname !== '/' ? ' bb-public-shell--interior' : ''}`} onClickCapture={interceptCustomerLink} onAuxClickCapture={interceptCustomerLink}>
      <PublicHeader luxury={luxury} />
      <main id="main-content" tabIndex={-1} className={compact ? 'bb-public-main bb-public-main--full' : 'bb-public-main bb-public-main--contained'}>
        {preview && <div role="status" className="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900"><strong>Chế độ xem trước</strong><p>Dữ liệu đã lưu, kể cả nội dung chưa công khai. Không xuất bản hoặc thực hiện thao tác khách hàng trong chế độ này.</p></div>}
        {children}
      </main>
      {showFooter ? <PublicFooter luxury={luxury} /> : null}
    </div>
  );
}
