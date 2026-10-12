import React, { useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate, Outlet, useLocation } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { useAuthStore } from './store/authStore';
import { FINANCE_WORKSPACE_ENABLED } from './config/featureAvailability';
import UnavailableFeature from './components/UnavailableFeature';
import { businessApi } from './api/apiClient';
import { CUSTOMER_ACCOUNT_REQUIRED, isCustomerAccount } from './utils/authScope';
import { getHomeMedia } from './config/homeMedia';
import { applySeo, getSiteUrl } from './utils/seo';

import { PlatformShell } from './components/layout/PlatformShell';
import { SalonShell } from './components/layout/SalonShell';
import { CustomerShell } from './components/layout/CustomerShell';

const lazyNamed = (loader, name) => React.lazy(() =>
  loader().then((module) => ({ default: module[name] })),
);

// Route-level chunks keep scheduler, charts and admin workspaces out of the
// initial public/login bundle while preserving the existing guards and UI.
const LoginScreen = lazyNamed(() => import('./pages/Login/LoginScreen'), 'LoginScreen');
const RegisterScreen = lazyNamed(() => import('./pages/Login/RegisterScreen'), 'RegisterScreen');
const AcceptInvitation = lazyNamed(() => import('./pages/Login/AuthActionPages'), 'AcceptInvitation');
const ForgotPassword = lazyNamed(() => import('./pages/Login/AuthActionPages'), 'ForgotPassword');
const ResetPassword = lazyNamed(() => import('./pages/Login/AuthActionPages'), 'ResetPassword');
const VerifyEmail = lazyNamed(() => import('./pages/Login/AuthActionPages'), 'VerifyEmail');
const SalonOverview = lazyNamed(() => import('./pages/Salon/SalonOverview'), 'SalonOverview');
const SalonServices = lazyNamed(() => import('./pages/Salon/SalonServices'), 'SalonServices');
const SalonCombos = React.lazy(() => import('./pages/Salon/SalonCombos'));
const SalonAppointments = lazyNamed(() => import('./pages/Salon/SalonAppointments'), 'SalonAppointments');
const SalonStats = lazyNamed(() => import('./pages/Salon/SalonStats'), 'SalonStats');
const SalonProfile = lazyNamed(() => import('./pages/Salon/SalonProfile'), 'SalonProfile');
const SalonStaffManagement = React.lazy(() => import('./pages/Salon/SalonStaffManagement'));
const StaffDetail = React.lazy(() => import('./pages/Salon/StaffDetail'));
const SalonPromotions = React.lazy(() => import('./pages/Salon/SalonPromotions'));
const SalonReviews = React.lazy(() => import('./pages/Salon/SalonReviews'));
const SalonNotifications = React.lazy(() => import('./pages/Salon/SalonNotifications'));
const BusinessOnboarding = React.lazy(() => import('./pages/Salon/BusinessOnboarding'));
const BranchOnboardingWizard = React.lazy(() => import('./pages/Salon/BranchOnboardingWizard'));
const PaymentsWorkspace = React.lazy(() => import('./pages/Salon/PaymentsWorkspace'));
const SalonOperations = React.lazy(() => import('./pages/Salon/SalonOperations'));
const SalonAudit = React.lazy(() => import('./pages/Admin/AdminAudit').then((module) => ({ default: () => <module.AdminAudit scopeLabel="thao tác của chính tài khoản bạn" /> })));
const AdminOverview = lazyNamed(() => import('./pages/Admin/AdminOverview'), 'AdminOverview');
const AdminSalons = lazyNamed(() => import('./pages/Admin/AdminSalons'), 'AdminSalons');
const AdminUsers = lazyNamed(() => import('./pages/Admin/AdminUsers'), 'AdminUsers');
const AdminUserDetail = lazyNamed(() => import('./pages/Admin/AdminEntityDetails'), 'AdminUserDetail');
const AdminBranchDetail = lazyNamed(() => import('./pages/Admin/AdminEntityDetails'), 'AdminBranchDetail');
const AdminBusinessDetail = lazyNamed(() => import('./pages/Admin/AdminEntityDetails'), 'AdminBusinessDetail');
const AdminAppointmentsView = lazyNamed(() => import('./pages/Admin/AdminAppointmentsView'), 'AdminAppointmentsView');
const AdminReports = lazyNamed(() => import('./pages/Admin/AdminReports'), 'AdminReports');
const AdminReviewsModeration = React.lazy(() => import('./pages/Admin/AdminReviewsModeration'));
const AdminNotifications = React.lazy(() => import('./pages/Admin/AdminNotifications'));
const AdminSettings = React.lazy(() => import('./pages/Admin/AdminSettings'));
const AdminAudit = React.lazy(() => import('./pages/Admin/AdminAudit'));
const BookingStep1 = React.lazy(() => import('./pages/Customer/BookingStep1'));
const BookingStep2 = React.lazy(() => import('./pages/Customer/BookingStep2'));
const BookingStep3 = React.lazy(() => import('./pages/Customer/BookingStep3'));
const BookingStep4 = React.lazy(() => import('./pages/Customer/BookingStep4'));
const BookingConfirm = React.lazy(() => import('./pages/Customer/BookingConfirm'));
const BookingSuccess = React.lazy(() => import('./pages/Customer/BookingSuccess'));
const CustomerAppointments = lazyNamed(() => import('./pages/Customer/CustomerAppointments'), 'CustomerAppointments');
const CustomerAppointmentDetail = lazyNamed(() => import('./pages/Customer/CustomerAppointmentDetail'), 'CustomerAppointmentDetail');
const CustomerNotifications = lazyNamed(() => import('./pages/Customer/CustomerNotifications'), 'CustomerNotifications');
const CustomerVouchers = lazyNamed(() => import('./pages/Customer/CustomerVouchers'), 'CustomerVouchers');
const CustomerReviews = lazyNamed(() => import('./pages/Customer/CustomerReviews'), 'CustomerReviews');
const CustomerBenefits = React.lazy(() => import('./pages/Customer/CustomerBenefits'));
const IncomingOwnership = React.lazy(() => import('./pages/Salon/IncomingOwnership'));
const SecuritySettings = React.lazy(() => import('./pages/SecuritySettings'));
const ProfileSettings = React.lazy(() => import('./pages/ProfileSettings'));
const PublicHome = React.lazy(() => import('./pages/Public/PublicHome'));
const PublicBranchDetail = lazyNamed(() => import('./pages/Public/PublicDetails'), 'PublicBranchDetail');
const PublicServiceDetail = lazyNamed(() => import('./pages/Public/PublicDetails'), 'PublicServiceDetail');
const PublicStaffDetail = lazyNamed(() => import('./pages/Public/PublicDetails'), 'PublicStaffDetail');
const PublicNotFound = lazyNamed(() => import('./pages/Public/PublicNotFound'), 'PublicNotFound');
const BusinessLanding = React.lazy(() => import('./pages/Public/BusinessLanding'));

function RouteFallback() {
  return (
    <div role="status" aria-live="polite" className="mx-auto mt-10 w-full max-w-5xl animate-pulse px-5">
      <span className="sr-only">Đang tải trang…</span>
      <div className="mb-4 h-8 w-56 rounded-lg bg-slate-200" />
      <div className="h-48 rounded-2xl bg-slate-100" />
    </div>
  );
}

function PublicRouteSeo() {
  const location = useLocation();

  useEffect(() => {
    const defaultImage = getHomeMedia('hero', 'primary');
    const pages = {
      '/': {
        title: 'BeautyBook | Đặt lịch salon, spa và dịch vụ làm đẹp',
        description: 'Khám phá salon, spa và dịch vụ làm đẹp, so sánh thông tin và đặt lịch phù hợp trên BeautyBook.',
        indexable: true,
      },
      '/explore': {
        title: 'Khám phá dịch vụ làm đẹp | BeautyBook',
        description: 'Tìm dịch vụ làm đẹp theo nhu cầu, địa điểm, giá và thời lượng tại các cơ sở đang nhận lịch trên BeautyBook.',
        indexable: true,
      },
      '/for-business': {
        title: 'BeautyBook cho cơ sở làm đẹp',
        description: 'Giới thiệu BeautyBook dành cho salon, spa và cơ sở làm đẹp muốn quản lý dịch vụ, lịch hẹn và hoạt động kinh doanh.',
        indexable: true,
      },
      '/login': {
        title: 'Đăng nhập | BeautyBook',
        description: 'Đăng nhập BeautyBook để quản lý lịch hẹn và tài khoản của bạn.',
        indexable: false,
      },
      '/register': {
        title: 'Đăng ký tài khoản | BeautyBook',
        description: 'Tạo tài khoản BeautyBook để lưu dịch vụ và đặt lịch làm đẹp.',
        indexable: false,
      },
      '/register/business': {
        title: 'Đăng ký cơ sở làm đẹp | BeautyBook',
        description: 'Tạo tài khoản doanh nghiệp BeautyBook và bắt đầu quy trình đưa cơ sở làm đẹp lên nền tảng.',
        indexable: false,
      },
    };
    const detailPage = location.pathname.startsWith('/explore/branches/')
      ? { title: 'Cơ sở làm đẹp | BeautyBook', description: 'Xem thông tin cơ sở, dịch vụ, chuyên viên và lịch đặt hẹn trên BeautyBook.', indexable: true }
      : location.pathname.startsWith('/explore/services/')
        ? { title: 'Dịch vụ làm đẹp | BeautyBook', description: 'Xem thông tin dịch vụ, giá, thời lượng và cơ sở cung cấp trên BeautyBook.', indexable: true }
        : location.pathname.startsWith('/explore/staff/')
          ? { title: 'Chuyên viên làm đẹp | BeautyBook', description: 'Xem hồ sơ chuyên viên, dịch vụ phụ trách và thông tin đặt lịch trên BeautyBook.', indexable: true }
          : null;
    const explicitNoIndex = [
      '/forgot-password',
      '/reset-password',
      '/verify-email',
      '/accept-invitation',
    ].includes(location.pathname)
      || location.pathname.startsWith('/customer')
      || location.pathname.startsWith('/salon')
      || location.pathname.startsWith('/admin')
      || location.pathname.startsWith('/book');
    const page = pages[location.pathname] || detailPage || {
      title: 'BeautyBook',
      description: 'Nền tảng đặt lịch và vận hành dịch vụ làm đẹp.',
      indexable: false,
    };
    const homeStructuredData = location.pathname === '/' && getSiteUrl()
      ? [
          { '@type': 'WebSite', '@id': `${getSiteUrl()}/#website`, url: `${getSiteUrl()}/`, name: 'BeautyBook', inLanguage: 'vi-VN' },
          { '@type': 'Organization', '@id': `${getSiteUrl()}/#organization`, url: `${getSiteUrl()}/`, name: 'BeautyBook' },
        ]
      : [];

    applySeo({
      title: page.title,
      description: page.description,
      image: defaultImage,
      imageAlt: defaultImage?.alt || 'Hình ảnh BeautyBook',
      canonicalPath: location.pathname,
      indexable: !explicitNoIndex && page.indexable,
      structuredData: homeStructuredData,
    });
  }, [location.pathname]);

  return null;
}

function AuthBootstrap({ children }) {
  const initialized = useAuthStore((state) => state.initialized);
  const initialize = useAuthStore((state) => state.initialize);
  useEffect(() => {
    void initialize();
  }, [initialize]);
  return initialized ? children : <RouteFallback />;
}

/**
 * Route guard: yêu cầu đăng nhập + đúng role.
 *
 *   <ProtectedRoute roles={['salon']}>...</ProtectedRoute>
 *
 *   <ProtectedRoute can="booking:read:branch" scope={{ tenantId, branchId }}>
 *     ...
 *   </ProtectedRoute>
 */
function ProtectedRoute({ children, roles, can: canCode, scope }) {
  const { user, isAuthenticated, can } = useAuthStore();
  const location = useLocation();
  if (!isAuthenticated()) {
    return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />;
  }
  const isOwner = [...(user?.roles || []), ...(user?.scopes || []).map((item) => item.code)].includes('BUSINESS_OWNER');
  if (isOwner && ['/salon/audit', '/salon/stats', '/salon/incoming-ownership'].some((path) => location.pathname === path || location.pathname.startsWith(`${path}/`))) {
    return <Navigate to="/salon" replace />;
  }
  if (location.pathname === '/customer/benefits' && new URLSearchParams(location.search).get('tab') === 'ownership') {
    return <Navigate to={isOwner ? '/salon' : '/salon/incoming-ownership'} replace />;
  }
  if (roles?.includes('customer') && !isCustomerAccount(user)) {
    return <main className="mx-auto max-w-2xl p-8"><h1 className="text-2xl font-bold">Cần tài khoản khách hàng riêng</h1><p className="my-4">{CUSTOMER_ACCOUNT_REQUIRED}</p><a className="underline" href="/explore">Tiếp tục xem nội dung công khai</a></main>;
  }
  if (roles && roles.length && !roles.includes(user?.sessionType)) {
    return <Navigate to={user?.sessionType === 'admin' ? '/admin' : user?.sessionType === 'salon' ? '/salon' : '/customer/appointments'} replace />;
  }
  if (canCode && !can(canCode, scope ?? {})) {
    return <Navigate to={user?.sessionType === 'admin' ? '/admin' : user?.sessionType === 'salon' ? '/salon' : '/customer/appointments'} replace />;
  }
  return children;
}

function PermissionGate({ anyOf, fallback, children }) {
  const can = useAuthStore((state) => state.can);
  return anyOf.some((permission) => can(permission))
    ? children
    : <Navigate to={fallback} replace />;
}

function SalonLanding() {
  const can = useAuthStore((state) => state.can);
  if (can('report:overview:tenant')) return <SalonOverview />;
  if (can('booking:read:tenant') || can('booking:read:branch')) return <Navigate to="/salon/appointments" replace />;
  if (can('user:read:tenant') || can('user:read:branch')) return <Navigate to="/salon/staff" replace />;
  return <Navigate to="/salon/account" replace />;
}

function ApprovedBusinessGate() {
  const user = useAuthStore((state) => state.user);
  const isOwner = user?.roles?.includes('BUSINESS_OWNER') || user?.scopes?.some((scope) => scope.code === 'BUSINESS_OWNER');
  const [status, setStatus] = useState(isOwner ? 'loading' : 'allowed');

  useEffect(() => {
    let active = true;
    if (!isOwner) { setStatus('allowed'); return () => { active = false; }; }
    setStatus('loading');
    businessApi.getMyOnboarding()
      .then((business) => { if (active) setStatus(['APPROVED', 'ACTIVE'].includes(business?.status) ? 'allowed' : 'onboarding'); })
      .catch(() => { if (active) setStatus('onboarding'); });
    return () => { active = false; };
  }, [isOwner]);

  if (status === 'loading') return <RouteFallback />;
  if (status === 'onboarding') return <Navigate to="/salon/onboarding" replace />;
  return <Outlet />;
}

function BusinessOnboardingRoute() {
  const [status, setStatus] = useState('loading');
  useEffect(() => {
    let active = true;
    businessApi.getMyOnboarding()
      .then((business) => {
        if (active) setStatus(['APPROVED', 'ACTIVE'].includes(business?.status) ? 'approved' : 'onboarding');
      })
      .catch(() => {
        if (active) setStatus('onboarding');
      });
    return () => { active = false; };
  }, []);
  if (status === 'loading') return <RouteFallback />;
  return status === 'approved'
    ? <Navigate to="/salon/profile" replace />
    : <BusinessOnboarding />;
}

function AdminLanding() {
  return <AdminOverview />;
}

export default function App() {
  return (
    <BrowserRouter basename={document.querySelector('meta[name="beautybook-base"]')?.content ?? '/beautybook'}>
      <PublicRouteSeo />
      <Toaster
        position="top-right"
        toastOptions={{
          style: { borderRadius: 'var(--bb-radius-control)', fontSize: '14px' },
          success: { iconTheme: { primary: 'var(--bb-success)', secondary: 'var(--color-accent-ink)' } },
        }}
      />
      <AuthBootstrap>
      <React.Suspense fallback={<RouteFallback />}>
      <Routes>
        {/* Public */}
        <Route path="/login" element={<LoginScreen />} />
        <Route path="/register" element={<RegisterScreen accountType="CUSTOMER" />} />
        <Route path="/register/business" element={<RegisterScreen accountType="BUSINESS_OWNER" />} />
        <Route path="/for-business" element={<BusinessLanding />} />
        <Route path="/business" element={<Navigate to="/for-business" replace />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/verify-email" element={<VerifyEmail />} />
        <Route path="/accept-invitation" element={<AcceptInvitation />} />
        <Route path="/explore" element={<PublicHome exploreMode />} />
        <Route path="/explore/branches/:id" element={<PublicBranchDetail />} />
        <Route path="/salon/branches/:id/preview" element={<ProtectedRoute roles={['salon']}><PermissionGate fallback="/salon/profile" anyOf={['branch:read:tenant']}><PublicBranchDetail preview /></PermissionGate></ProtectedRoute>} />
        <Route path="/explore/services/:id" element={<PublicServiceDetail />} />
        <Route path="/explore/staff/:id" element={<PublicStaffDetail />} />

        {/* Customer authenticated zone */}
        <Route
          path="/customer/*"
          element={
            <ProtectedRoute roles={['customer']}>
              <CustomerShell>
                <Routes>
                  <Route index element={<Navigate to="/customer/appointments" replace />} />
                  <Route path="appointments" element={<CustomerAppointments />} />
                  <Route path="appointments/:id" element={<CustomerAppointmentDetail />} />
                  <Route path="notifications" element={<CustomerNotifications />} />
                  <Route path="vouchers" element={<CustomerVouchers />} />
                  <Route path="reviews" element={<CustomerReviews />} />
                  <Route path="benefits" element={<CustomerBenefits />} />
                  <Route path="security" element={<SecuritySettings />} />
                  <Route path="profile" element={<ProfileSettings />} />
                  <Route path="privacy" element={<UnavailableFeature home="/customer/profile" />} />
                  <Route path="*" element={<Navigate to="/customer/appointments" replace />} />
                </Routes>
              </CustomerShell>
            </ProtectedRoute>
          }
        />

        {/* Public users may browse availability, but creating a booking requires a customer account. */}
        <Route path="/book" element={<ProtectedRoute roles={['customer']}><BookingStep1 /></ProtectedRoute>} />
        <Route path="/book/staff" element={<ProtectedRoute roles={['customer']}><BookingStep2 /></ProtectedRoute>} />
        <Route path="/book/time" element={<ProtectedRoute roles={['customer']}><BookingStep3 /></ProtectedRoute>} />
        <Route path="/book/info" element={<ProtectedRoute roles={['customer']}><BookingStep4 /></ProtectedRoute>} />
        <Route path="/book/confirm" element={<ProtectedRoute roles={['customer']}><BookingConfirm /></ProtectedRoute>} />
        <Route path="/book/success" element={<ProtectedRoute roles={['customer']}><BookingSuccess /></ProtectedRoute>} />

        {/* Salon routes */}
        <Route
          path="/salon/*"
          element={
            <ProtectedRoute roles={['salon']}>
              <SalonShell>
                <Routes>
                  <Route path="notifications" element={<PermissionGate fallback="/salon" anyOf={['notification:read:self']}><SalonNotifications /></PermissionGate>} />
                  <Route path="onboarding" element={<PermissionGate fallback="/salon" anyOf={['business:create:self', 'business:update:tenant']}><BusinessOnboardingRoute /></PermissionGate>} />
                  <Route path="security" element={<SecuritySettings />} />
                  <Route path="account" element={<ProfileSettings />} />
                  <Route path="incoming-ownership" element={<IncomingOwnership />} />
                  <Route element={<ApprovedBusinessGate />}>
                    <Route index element={<SalonLanding />} />
                    <Route path="services" element={<PermissionGate fallback="/salon" anyOf={['business_service:update:tenant', 'branch_service_offering:status:tenant']}><SalonServices /></PermissionGate>} />
                    <Route path="combos" element={<PermissionGate fallback="/salon" anyOf={['combo:manage:tenant']}><SalonCombos /></PermissionGate>} />
                    <Route path="appointments" element={<PermissionGate fallback="/salon" anyOf={['booking:read:tenant', 'booking:read:branch']}><SalonAppointments /></PermissionGate>} />
                    <Route path="staff" element={<PermissionGate fallback="/salon" anyOf={['user:read:tenant', 'user:read:branch']}><SalonStaffManagement /></PermissionGate>} />
                    {/* Prevent the retired schedule route from being parsed as staffId="schedule". */}
                    <Route path="staff/schedule" element={<Navigate to="/salon/appointments" replace />} />
                    <Route path="staff/:staffId" element={<PermissionGate fallback="/salon" anyOf={['user:read:tenant', 'user:read:branch', 'user:read:self']}><StaffDetail /></PermissionGate>} />
                    <Route path="branches/new" element={<PermissionGate fallback="/salon/profile" anyOf={['branch:create:tenant']}><BranchOnboardingWizard /></PermissionGate>} />
                    <Route path="branches/:branchId/setup" element={<PermissionGate fallback="/salon/profile" anyOf={['branch:update:tenant', 'branch:create:tenant']}><BranchOnboardingWizard /></PermissionGate>} />
                    <Route path="promotions" element={<PermissionGate fallback="/salon" anyOf={['promotion:manage:tenant']}><SalonPromotions /></PermissionGate>} />
                    <Route path="reviews" element={<PermissionGate fallback="/salon" anyOf={['review:moderate:tenant']}><SalonReviews /></PermissionGate>} />
                    <Route path="stats" element={<PermissionGate fallback="/salon" anyOf={['report:revenue:tenant', 'report:revenue:branch']}><SalonStats /></PermissionGate>} />
                    <Route path="profile" element={<PermissionGate fallback="/salon" anyOf={['branch:update:tenant']}><SalonProfile /></PermissionGate>} />
                    <Route path="payments" element={<PermissionGate fallback="/salon" anyOf={['payment:read:branch', 'payment:read:tenant']}>{FINANCE_WORKSPACE_ENABLED ? <PaymentsWorkspace /> : <UnavailableFeature finance home="/salon" />}</PermissionGate>} />
                    <Route path="operations" element={<PermissionGate fallback="/salon" anyOf={['booking:read:tenant', 'booking:read:branch', 'payment:read:tenant', 'payment:read:branch']}><SalonOperations /></PermissionGate>} />
                    <Route path="audit" element={<PermissionGate fallback="/salon" anyOf={['audit:read:tenant']}><SalonAudit /></PermissionGate>} />
                  </Route>
                  <Route path="*" element={<Navigate to="/salon" replace />} />
                </Routes>
              </SalonShell>
            </ProtectedRoute>
          }
        />

        {/* Admin routes */}
        <Route
          path="/admin/*"
          element={
            <ProtectedRoute roles={['admin']}>
              <PlatformShell>
                <Routes>
                  <Route index element={<AdminLanding />} />
                  <Route path="salons" element={<PermissionGate fallback="/admin" anyOf={['branch:read:platform']}><AdminSalons /></PermissionGate>} />
                  <Route path="branches/:branchId" element={<PermissionGate fallback="/admin" anyOf={['branch:read:platform']}><AdminBranchDetail /></PermissionGate>} />
                  <Route path="businesses/:businessId" element={<PermissionGate fallback="/admin" anyOf={['branch:read:platform']}><AdminBusinessDetail /></PermissionGate>} />
                  <Route path="users" element={<PermissionGate fallback="/admin" anyOf={['user:read:platform']}><AdminUsers /></PermissionGate>} />
                  <Route path="users/:userId" element={<PermissionGate fallback="/admin" anyOf={['user:read:platform']}><AdminUserDetail /></PermissionGate>} />
                  <Route path="appointments" element={<PermissionGate fallback="/admin" anyOf={['booking:read:platform']}><AdminAppointmentsView /></PermissionGate>} />
                  <Route path="payments" element={<PermissionGate fallback="/admin" anyOf={['payment:read:platform']}>{FINANCE_WORKSPACE_ENABLED ? <PaymentsWorkspace /> : <UnavailableFeature finance home="/admin" />}</PermissionGate>} />
                  <Route path="reports" element={<PermissionGate fallback="/admin" anyOf={['report:overview:platform', 'report:revenue:platform', 'report:user_growth:platform']}><AdminReports /></PermissionGate>} />
                  <Route path="reviews" element={<PermissionGate fallback="/admin" anyOf={['review:moderate:platform']}><AdminReviewsModeration /></PermissionGate>} />
                  <Route path="ownership" element={<Navigate to="/admin" replace />} />
                  <Route path="violations" element={<Navigate to="/admin/salons" replace />} />
                  <Route path="audit" element={<PermissionGate fallback="/admin" anyOf={['audit:read:platform']}><AdminAudit /></PermissionGate>} />
                  <Route path="notifications" element={<PermissionGate fallback="/admin" anyOf={['notification:read:self']}><AdminNotifications /></PermissionGate>} />
                  <Route path="settings" element={<PermissionGate fallback="/admin" anyOf={['platform_setting:manage:platform']}><AdminSettings /></PermissionGate>} />
                  <Route path="compliance" element={<Navigate to="/admin/salons?view=review" replace />} />
                  <Route path="security" element={<SecuritySettings />} />
                  <Route path="profile" element={<ProfileSettings />} />
                  <Route path="*" element={<Navigate to="/admin" replace />} />
                </Routes>
              </PlatformShell>
            </ProtectedRoute>
          }
        />

        {/* Default: redirect to login or dashboard based on auth */}
        <Route
          path="/"
          element={
            useAuthStore.getState().isAuthenticated() ? (
              <Navigate
                to={
                  useAuthStore.getState().user?.sessionType === 'admin'
                    ? '/admin'
                    : useAuthStore.getState().user?.sessionType === 'salon'
                      ? '/salon'
                      : '/customer/appointments'
                }
                replace
              />
            ) : <PublicHome />
          }
        />
        <Route path="*" element={<PublicNotFound />} />
      </Routes>
      </React.Suspense>
      </AuthBootstrap>
    </BrowserRouter>
  );
}
