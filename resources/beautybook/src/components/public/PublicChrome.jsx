import AccountMenuLinks from '../layout/AccountMenuLinks';
import '../../styles/glowme/public-refresh.css';
import { useEffect, useRef, useState } from 'react';
import { LogOut, Wallet, MessageCircle, ArrowUpRight, CalendarCheck, ChevronDown, ChevronRight, Menu, Store, UserRound, X } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../store/authStore';
import { BRAND } from '../../config/brand';
import { BrandLogo } from '../brand/BrandLogo';
import '../../styles/glowme/public-navigation.css';

const discoveryLinks = [
  { label: 'Tìm dịch vụ', to: '/explore' },
  { label: 'Dịch vụ', to: '/#services' },
  { label: 'Cơ sở', to: '/#salons' },
  { label: 'Cách đặt', to: '/#how-it-works' },
];

function portalPath(sessionType) {
  if (sessionType === 'admin') return '/admin';
  if (sessionType === 'salon') return '/salon';
  if (sessionType === 'customer') return '/customer/profile';
  return '/explore';
}

function CustomerAccountMenu({user}) {
 const logout=useAuthStore(s=>s.logout),navigate=useNavigate();const signOut=async()=>{try{await logout();navigate('/login',{replace:true});}catch(error){navigate('/login',{replace:true,state:{logoutError:error.message||'Chưa thể kết thúc phiên. Vui lòng thử lại.'}});}};
  const [open,setOpen]=useState(false),root=useRef(null),trigger=useRef(null),location=useLocation();
  const initials=(user?.fullName||'BeautyBook').trim().split(/\s+/).slice(-2).map(part=>part[0]).join('').toUpperCase();
  useEffect(()=>setOpen(false),[location.pathname,location.search,location.hash]);
  useEffect(()=>{if(!open)return;const outside=event=>{if(!root.current?.contains(event.target))setOpen(false);};document.addEventListener('pointerdown',outside);return()=>document.removeEventListener('pointerdown',outside);},[open]);
  return <div ref={root} className="bb-public-personal" onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget))setOpen(false);}} onKeyDown={event=>{if(open&&event.key==='Escape'){event.preventDefault();event.stopPropagation();setOpen(false);trigger.current?.focus();}}}>
    <button ref={trigger} type="button" className="bb-public-personal__trigger" aria-expanded={open} aria-controls="public-personal-links" onClick={()=>setOpen(value=>!value)}><span className="bb-public-personal__avatar" aria-hidden="true">{initials}</span><span className="bb-public-personal__name" title={user?.fullName}>{user?.fullName?.trim()||'Tài khoản'}</span><ChevronDown size={13} aria-hidden="true"/></button>
    {open&&<div id="public-personal-links" className="bb-public-personal__panel">
      <header className="bb-public-personal__identity"><span className="bb-public-personal__avatar" aria-hidden="true">{initials}</span><div><span className="bb-public-personal__eyebrow">Không gian cá nhân</span><strong>{user?.fullName||'Tài khoản của tôi'}</strong><small title={user?.email}>{user?.email||'Chào mừng bạn đến BeautyBook'}</small></div></header>
      <AccountMenuLinks variant="public" onNavigate={()=>setOpen(false)}/>
      <div className="gm-account-menu-footer"><Link to="/for-business" onClick={()=>setOpen(false)}><Store size={17}/>Dành cho cơ sở</Link><button type="button" onClick={signOut}><LogOut size={17}/>Đăng xuất</button></div>

    </div>}
  </div>;
}

export function PublicHeader({luxury=false}) {
  const [scrolled,setScrolled]=useState(false);
  useEffect(()=>{if(!luxury)return;const update=()=>setScrolled(window.scrollY>24);update();window.addEventListener('scroll',update,{passive:true});return()=>window.removeEventListener('scroll',update);},[luxury]);
  const links=luxury?[{label:'Tìm dịch vụ',to:'/explore'},{label:'Cơ sở làm đẹp',to:'/#salons'},{label:'Ưu đãi',to:'/#offers'}]:discoveryLinks;
  const location = useLocation();
  const menuButtonRef = useRef(null);
  const closeButtonRef = useRef(null);
  const drawerRef = useRef(null);
  const [open, setOpen] = useState(false);
  const user = useAuthStore((state) => state.user);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated());

  useEffect(() => {
    setOpen(false);
  }, [location.pathname, location.search, location.hash]);

  useEffect(() => {
    if (!open) return undefined;
    const previousOverflow = document.body.style.overflow;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        menuButtonRef.current?.focus();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...(drawerRef.current?.querySelectorAll('a[href], button:not([disabled])') || [])]
        .filter((element) => element.getClientRects().length);
      if (!focusable.length) return;
      if (event.shiftKey && document.activeElement === focusable[0]) {
        event.preventDefault();
        focusable[focusable.length - 1].focus();
      } else if (!event.shiftKey && document.activeElement === focusable[focusable.length - 1]) {
        event.preventDefault();
        focusable[0].focus();
      }
    };
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeyDown);
    closeButtonRef.current?.focus();
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const accountLink = isAuthenticated ? portalPath(user?.sessionType) : '/login';
  const accountLabel = isAuthenticated ? user?.sessionType === 'customer' ? 'Tài khoản của tôi' : 'Vào hệ thống' : 'Đăng nhập';

  return (
    <>
      <a className="bb-home-skip" href="#main-content">Bỏ qua điều hướng</a>
      <header className={`gm-site-header bb-public-header bb-public-header--clear${scrolled?' bb-public-header--scrolled':''}`}>
        <div className="gm-site-header__inner bb-public-header__inner">
          <Link to="/" className="gm-site-header__brand bb-public-brand" aria-label={`${BRAND.name} — trang chủ`}>
            <BrandLogo size={38} />
          </Link>

          <nav className="bb-public-nav" aria-label="Điều hướng chính">
            {links.map((item) => <Link key={item.to} to={item.to}>{item.label}</Link>)}
            <Link className="bb-public-partner-link" to="/for-business"><Store size={15} aria-hidden="true"/>Dành cho cơ sở<ArrowUpRight size={12} aria-hidden="true"/></Link>
          </nav>

          <div className="bb-public-actions">
            {isAuthenticated&&user?.sessionType==='customer'?<CustomerAccountMenu key={user.sessionId} user={user}/>:<Link className="bb-public-account-link bb-public-account-link--account" to={accountLink}><UserRound size={16} aria-hidden="true" />{accountLabel}</Link>}
            <Link className="bb-home-button bb-home-button--primary bb-home-button--small bb-public-cta" to="/explore"><CalendarCheck size={16} aria-hidden="true" />Đặt lịch</Link>
          </div>

          <button
            ref={menuButtonRef}
            type="button"
            className="bb-public-menu-button"
            aria-label="Mở menu"
            aria-expanded={open}
            aria-controls="public-mobile-menu"
            onClick={() => setOpen(true)}
          >
            <Menu size={21} aria-hidden="true" />
          </button>
        </div>
      </header>

      {open ? (
        <div className="bb-public-drawer" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setOpen(false);
        }}>
          <section ref={drawerRef} id="public-mobile-menu" className="bb-public-drawer__panel" role="dialog" aria-modal="true" aria-label="Điều hướng di động">
            <div className="bb-public-drawer__top">
              <span className="bb-public-drawer__label">Khám phá {BRAND.name}</span>
              <button ref={closeButtonRef} type="button" aria-label="Đóng menu" onClick={() => setOpen(false)}>
                <X size={22} aria-hidden="true" />
              </button>
            </div>
            <div className="bb-public-drawer__links">
              {links.map((item, index) => (
                <Link key={item.to} to={item.to}><span>0{index + 1}</span>{item.label}</Link>
              ))}
            </div>
            <div className="bb-public-drawer__footer">
              <div className="bb-public-drawer__personal"><p>DÀNH CHO BẠN</p>{isAuthenticated&&user?.sessionType==='customer'&&<Link to="/customer/appointments"><CalendarCheck size={17} aria-hidden="true"/>Lịch hẹn của tôi</Link>}<Link to={accountLink}><UserRound size={17} aria-hidden="true"/>{accountLabel}</Link><Link className="bb-home-button bb-home-button--primary" to="/explore"><CalendarCheck size={17} aria-hidden="true"/>Đặt lịch</Link></div>
              <Link className="bb-public-drawer__partner" to="/for-business"><Store size={18} aria-hidden="true"/><span>Dành cho cơ sở<small>Quản lý cơ sở & phát triển kinh doanh</small></span><ArrowUpRight size={15} aria-hidden="true"/></Link>
            </div>
          </section>
        </div>
      ) : null}
    </>
  );
}

export function PublicFooter({luxury=false,compact=false}) {
  if(compact)return <footer className="bb-account-footer"><Link to="/" aria-label="BeautyBook — trang chủ"><BrandLogo size={28}/></Link><span>© {new Date().getFullYear()} BeautyBook</span><nav aria-label="Liên kết cuối trang"><Link to="/explore">Tìm dịch vụ</Link><Link to="/for-business">Dành cho cơ sở</Link></nav></footer>;
  if(luxury)return <footer className="gm-footer"><div className="gm-container"><div className="gm-footer__top"><div><BrandLogo size={44} subtitle={BRAND.tagline}/><p>Nơi những cuộc hẹn làm đẹp<br/>trở thành thời gian dành cho bạn.</p></div><nav aria-label="Khám phá cuối trang"><span>KHÁM PHÁ</span><Link to="/explore">Dịch vụ làm đẹp</Link><Link to="/#salons">Cơ sở làm đẹp</Link><Link to="/#offers">Ưu đãi đang có</Link></nav><nav aria-label="Hỗ trợ cuối trang"><span>ĐỒNG HÀNH CÙNG BẠN</span><Link to="/#how-it-works">Hướng dẫn đặt lịch</Link><Link to="/customer/appointments">Lịch hẹn của tôi</Link><Link to="/login">Đăng nhập</Link></nav><nav aria-label="Đối tác cuối trang"><span>BEAUTYBOOK BUSINESS</span><Link to="/for-business">Dành cho đối tác</Link><Link to="/register/business">Đăng ký cơ sở</Link><p>Kết nối trải nghiệm.<br/>Chăm chút từng cuộc hẹn.</p></nav></div><div className="gm-footer__bottom"><span>© {new Date().getFullYear()} BeautyBook.</span><span>Làm đẹp theo cách của bạn.</span><Link to="/customer/security">Quản lý tài khoản</Link><Link to="/#main-content">Về đầu trang ↑</Link></div></div></footer>;
  return (
    <footer className="bb-public-footer">
      <div className="bb-public-footer__mast">
        <BrandLogo size={44} className="bb-brand--on-dark" subtitle={BRAND.tagline} />
        <p className="bb-public-footer__statement">Chọn nơi bạn tin tưởng. Đặt thời gian phù hợp. Dành phần còn lại cho chính mình.</p>
      </div>
      <div className="bb-public-footer__lower">
        <div>
          <strong>{BRAND.name}</strong>
          <p>Nền tảng tìm kiếm và đặt lịch dịch vụ làm đẹp.</p>
        </div>
        <nav aria-label="Điều hướng cuối trang">
          <Link to="/explore">Tìm dịch vụ</Link>
          <Link to="/customer/security">Quản lý tài khoản</Link>
          <Link to="/#services">Dịch vụ</Link>
          <Link to="/#how-it-works">Cách đặt lịch</Link>
          <Link to="/for-business">Dành cho cơ sở</Link>
          <Link to="/login">Đăng nhập</Link>
        </nav>
      </div>
    </footer>
  );
}
