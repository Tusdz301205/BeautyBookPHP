// UI affordances only: the API remains the authorization boundary.
const SALON_ROLES = new Set(['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF']);
const SUPPORTED_ROLES = new Set(['PLATFORM_ADMIN', ...SALON_ROLES, 'CUSTOMER']);
const RECEPTIONIST_BRANCH = new Set([
  'user:read:branch', 'branch:read:branch', 'booking:create:branch',
  'booking:read:branch', 'booking:update:branch', 'booking:check_in:branch',
  'booking:cancel:branch', 'booking:assign:branch', 'booking:reschedule:branch',
  'change_request:approve:branch', 'booking:read_internal_note:branch',
  'payment:read:branch', 'payment:create:branch', 'payment_transaction:verify:branch',
  'package_purchase:create:branch',
]);
const STAFF_BRANCH = new Set([
  'branch:read:branch', 'booking:read:branch', 'booking:update:branch',
  'booking:complete:branch', 'booking:read_internal_note:branch',
]);
const SHARED_SELF = new Set(['user:read:self', 'user:update:self', 'notification:read:self']);
export const CUSTOMER_ACCOUNT_REQUIRED = 'Tài khoản vận hành chỉ xem nội dung công khai. Vui lòng dùng tài khoản khách hàng riêng để đặt lịch, lưu dịch vụ hoặc đánh giá.';

export function isCustomerAccount(user) {
  return user?.sessionType === 'customer' && (!user.workspace || user.workspace === 'CUSTOMER')
    && user.roles?.includes('CUSTOMER')
    && !user.roles.some((role) => SALON_ROLES.has(role) || role === 'PLATFORM_ADMIN')
    && activeScopes(user).some((scope) => scope.code === 'CUSTOMER')
    && !activeScopes(user).some((scope) => SALON_ROLES.has(scope.code) || scope.code === 'PLATFORM_ADMIN');
}

export function activeScopes(user, now = Date.now()) {
  return (user?.scopes || []).filter((scope) => {
    if (!SUPPORTED_ROLES.has(scope.code)) return false;
    if (scope.expiresAt && !(new Date(scope.expiresAt).getTime() > now)) return false;
    if (['RECEPTIONIST', 'STAFF'].includes(scope.code) && (!scope.businessId || !scope.branchId)) return false;
    return true;
  });
}

export function roleAt(user, code, tenantId = null, branchId = null) {
  return activeScopes(user).some((scope) => scope.code === code
    && (tenantId == null || scope.businessId === tenantId)
    && (branchId == null || scope.branchId === branchId));
}

export function sessionState(result) {
  const scopes = activeScopes(result?.user);
  const type = result?.user?.sessionType;
  const valid = type === 'salon' ? scopes.some((scope) => SALON_ROLES.has(scope.code))
    : type === 'admin' ? scopes.some((scope) => scope.code === 'PLATFORM_ADMIN')
      : type === 'customer' && isCustomerAccount(result?.user);
  if (!result?.accessToken || !valid) return { user: null, accessToken: null, initialized: true };
  return {
    user: { ...result.user, scopes, roles: [...new Set(scopes.map((scope) => scope.code))] },
    accessToken: result.accessToken,
    initialized: true,
  };
}

export function activeContextFor(user) {
  const scopes = activeScopes(user);
  const scope = scopes.find((item) => item.code === 'BUSINESS_OWNER' && item.businessId)
    || scopes.find((item) => SALON_ROLES.has(item.code));
  return { tenantId: scope?.businessId ?? null, branchId: scope?.branchId ?? null };
}

export function canAt(user, code, ctx = {}) {
  if (!user?.permissions?.includes(code)) return false;
  const suffix = code.split(':').at(-1);
  const kind = ['tenant', 'branch', 'self', 'own', 'public'].includes(suffix) ? suffix : 'platform';
  return activeScopes(user).some((scope) => {
    if (kind === 'platform') return scope.code === 'PLATFORM_ADMIN';
    if (kind === 'tenant') return scope.code === 'BUSINESS_OWNER' && !!scope.businessId
      && (!ctx.branchId || !!ctx.tenantId)
      && (!ctx.tenantId || scope.businessId === ctx.tenantId);
    if (kind === 'branch') {
      if (scope.code === 'BUSINESS_OWNER') return code === 'report:revenue:branch' && !!scope.businessId
        && (!ctx.tenantId || scope.businessId === ctx.tenantId)
        && (!ctx.branchId || !!ctx.tenantId);
      const granted = scope.code === 'RECEPTIONIST' ? RECEPTIONIST_BRANCH.has(code)
        : scope.code === 'STAFF' && STAFF_BRANCH.has(code);
      return granted && (!ctx.branchId || scope.branchId === ctx.branchId)
        && (!ctx.tenantId || scope.businessId === ctx.tenantId);
    }
    if (kind === 'self' || kind === 'own') {
      const granted = SHARED_SELF.has(code) || (code === 'business:create:self'
        ? scope.code === 'BUSINESS_OWNER' : scope.code === 'CUSTOMER' && isCustomerAccount(user));
      return granted && (!ctx.ownerUserId || ctx.ownerUserId === user.id);
    }
    return kind === 'public';
  });
}

export function bookingCapabilities(user, booking) {
  const tenantId = booking?.businessId || booking?.raw?.branch?.businessId || booking?.raw?.businessId;
  const branchId = booking?.branchId;
  const ctx = { tenantId, branchId };
  const owner = !!tenantId && roleAt(user, 'BUSINESS_OWNER', tenantId);
  const receptionist = !!branchId && roleAt(user, 'RECEPTIONIST', tenantId ?? null, branchId);
  const staff = !!branchId && roleAt(user, 'STAFF', tenantId ?? null, branchId);
  const assigned = staff && (booking?.services || []).some((item) => isAssignedItem(user, item));
  const permitted = (action) => (owner && canAt(user, `booking:${action}:tenant`, ctx))
    || (receptionist && canAt(user, `booking:${action}:branch`, ctx));
  return {
    owner,
    frontDesk: owner || receptionist,
    canUpdate: permitted('update'),
    canCheckIn: permitted('check_in'),
    canCancel: permitted('cancel'),
    canAssign: permitted('assign'),
    canProvide: (owner && permitted('update')) || (assigned && canAt(user, 'booking:update:branch', ctx)),
    canComplete: (owner && permitted('update')) || (assigned && canAt(user, 'booking:complete:branch', ctx)),
    ctx,
  };
}

function isAssignedItem(user, item) {
  return !!user?.id && (item?.staffUserId === user.id
    || (!!user.staffProfile?.id && item?.staffId === user.staffProfile.id));
}

export function bookingItemActions(user, booking, item) {
  const rights = bookingCapabilities(user, booking);
  const assigned = !!booking?.branchId && roleAt(user, 'STAFF', rights.ctx.tenantId ?? null, booking.branchId)
    && isAssignedItem(user, item) && canAt(user, 'booking:update:branch', rights.ctx);
  const actions = [];
  if ((rights.owner && rights.canUpdate) || assigned) actions.push('START');
  if ((rights.owner && rights.canUpdate) || (assigned && canAt(user, 'booking:complete:branch', rights.ctx))) actions.push('COMPLETE');
  if (rights.canCancel) actions.push('REMOVE', 'SKIP');
  if (rights.canAssign) actions.push('REASSIGN');
  if (rights.owner && rights.canUpdate) actions.push('RESIZE', 'REPRICE');
  return actions;
}
