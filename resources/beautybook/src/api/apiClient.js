/**
 * API Client cho GlowBook Frontend
 * - Base URL từ env (VITE_API_BASE)
 * - Auto-attach Bearer token từ zustand authStore
 * - Auto-refresh token khi 401
 * - Error chuẩn hoá với message tiếng Việt
 */
import { useAuthStore } from '../store/authStore';
import { sanitizeApiErrorMessage } from '../utils/requestError';
import { createIdempotencyKey } from '../utils/idempotencyKey';

const API_BASE = import.meta.env.VITE_API_BASE || '/api/v1';
const csrfHeaders = () => ({
  Accept: 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
});
let refreshPromise = null;

async function performRefresh() {
  const response = await fetch(`${API_BASE}/auth/refresh`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      ...csrfHeaders(),
      'Content-Type': 'application/json',
      'Idempotency-Key': createIdempotencyKey(),
    },
    body: JSON.stringify({}),
  });
  const rotatedCsrf = response.headers.get('X-CSRF-TOKEN');
  if (rotatedCsrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', rotatedCsrf);
  if (!response.ok) {
    const error = new Error('Không thể làm mới phiên đăng nhập');
    error.status = response.status;
    throw error;
  }
  return response.json();
}

export async function refreshSession() {
  if (!refreshPromise) {
    refreshPromise = (async () => {
      // React StrictMode can initialize twice and separate tabs share the same
      // rotating refresh cookie. A per-tab promise plus the browser-wide Web
      // Lock serializes both cases so a valid token is not mistaken for replay.
      if (typeof navigator !== 'undefined' && navigator.locks?.request) {
        return navigator.locks.request('beautybook-auth-refresh', performRefresh);
      }
      return performRefresh();
    })().finally(() => {
      refreshPromise = null;
    });
  }
  return refreshPromise;
}

/**
 * Gọi API — tự động attach token + auto-refresh khi 401.
 */
async function request(endpoint, options = {}, _isRetry = false) {
  const url = `${API_BASE}${endpoint}`;
  const { accessToken, user, setSession, clearSession } =
    useAuthStore.getState();

  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const headers = {
    ...csrfHeaders(),
    ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
    ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
    ...options.scopeHeaders,
    ...options.headers,
  };

  if (options.method === 'POST' && !headers['Idempotency-Key']) {
    headers['Idempotency-Key'] = createIdempotencyKey();
  }

  // Always forward active tenant/branch context so server-side ScopeGuard
  // can resolve RBAC without an extra round-trip. Sender can override by
  // passing `options.businessId` / `options.branchId` explicitly.
  const ctx = useAuthStore.getState().activeContext?.() ?? {
    tenantId: null,
    branchId: null,
  };
  const explicitBiz = options.businessId ?? ctx.tenantId;
  const explicitBr = options.branchId ?? ctx.branchId;
  if (explicitBiz && !headers['X-Business-Id']) headers['X-Business-Id'] = explicitBiz;
  if (explicitBr && !headers['X-Branch-Id']) headers['X-Branch-Id'] = explicitBr;

  const config = { ...options, credentials: 'include', headers };

  const response = await fetch(url, config);
  const rotatedCsrf = response.headers.get('X-CSRF-TOKEN');
  if (rotatedCsrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', rotatedCsrf);

  // Auto-refresh token khi 401 và chưa retry
  if (response.status === 401 && user && !_isRetry && endpoint !== '/auth/refresh') {
    try {
      const data = await refreshSession();
      if (!setSession(data)) throw new Error('Phiên đăng nhập không còn quyền truy cập.');
      const retryOptions = {
        ...options,
        headers: {
          ...options.headers,
          ...(headers['Idempotency-Key']
            ? { 'Idempotency-Key': headers['Idempotency-Key'] }
            : {}),
        },
      };
      return request(endpoint, retryOptions, true);
    } catch {
      clearSession();
    }
  }

  if (!response.ok) {
    let message = `HTTP ${response.status}`;
    let details = null;
    try {
      const errorData = await response.json();
      details = errorData;
      // class-validator trả về mảng message; lấy message đầu tiên
      if (Array.isArray(errorData.message) && errorData.message.length) {
        message = errorData.message[0];
      } else if (typeof errorData.message === 'string') {
        message = errorData.message;
      }
    } catch {
      // ignore JSON parse error
    }
    const err = new Error(sanitizeApiErrorMessage(response.status, message));
    err.status = response.status;
    err.details = details;
    throw err;
  }

  // 204 No Content
  if (response.status === 204) return null;
  if (options.responseType === 'blob') return response.blob();
  // Nest/Express may legitimately return an empty 200 body for a nullable
  // resource (for example a new owner without an onboarding draft yet).
  // Parse from text so an empty success response becomes `null` instead of a
  // misleading "Unexpected end of JSON input" client error.
  const responseText = await response.text();
  if (!responseText.trim()) return null;
  try {
    return JSON.parse(responseText);
  } catch {
    throw new Error('Phản hồi từ máy chủ không đúng định dạng JSON');
  }
}

// ======================== AUTH ========================
export const authApi = {
  login: (email, password, context = {}) =>
    request('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password, ...context }),
    }),

  register: (data) =>
    request('/auth/register', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  refresh: () => refreshSession(),
  verifyEmail: (token) => request('/auth/verify-email', { method: 'POST', body: JSON.stringify({ token }) }),
  forgotPassword: (email) => request('/auth/forgot-password', { method: 'POST', body: JSON.stringify({ email }) }),
  resetPassword: (token, newPassword) => request('/auth/reset-password', { method: 'POST', body: JSON.stringify({ token, newPassword }) }),
  changePassword: (currentPassword, newPassword) => request('/auth/change-password', { method: 'POST', body: JSON.stringify({ currentPassword, newPassword }) }),
  logout: () => request('/auth/logout', { method: 'POST' }),
  getSessions: () => request('/auth/sessions'),
  revokeSession: (sessionId) => request(`/auth/sessions/${sessionId}`, { method: 'DELETE' }),
  revokeOtherSessions: () => request('/auth/sessions', { method: 'DELETE' }),
  getSecurityHistory: () => request('/auth/security-history'),
};

// ======================== BOOKINGS ========================
// Status label to enum mapping
const STATUS_LABEL_TO_ENUM = {
  'Mới': 'PENDING',
  'Đã xác nhận': 'CONFIRMED',
  'Đã check-in': 'CHECKED_IN',
  'Đang thực hiện': 'IN_PROGRESS',
  'Hoàn thành': 'COMPLETED',
  'Đã huỷ': 'CANCELLED',
  'No-show': 'NO_SHOW',
};

export const bookingsApi = {
  selfBookingPolicy: (branchId) => request(`/bookings/self-booking-policy?branchId=${encodeURIComponent(branchId)}`),
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.append(key, String(value));
      }
    });
    return request(`/bookings?${query.toString()}`);
  },

  getById: (id) => request(`/bookings/${id}`),

  getForceCancelPreview: (id) => request(`/bookings/${id}/force-cancel-preview`),

  forceCancel: (id, reason) => request(`/bookings/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status: 'CANCELLED', note: reason }),
  }),

  getStats: () => request('/bookings/stats'),

  getByCategory: (name) =>
    request(`/bookings/by-category?name=${encodeURIComponent(name)}`),

  getByBranch: (branchId) => request(`/bookings/by-branch/${branchId}`),

  getByCustomer: (userId) => request(`/bookings/by-customer/${userId}`),

  updateStatus: (id, status, changedBy, note, noShowConfirmed) => {
    // Convert label to enum if needed
    const mappedStatus = STATUS_LABEL_TO_ENUM[status] || status;
    return request(`/bookings/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status: mappedStatus, changedBy, note, noShowConfirmed }),
    });
  },

  create: (data, idempotencyKey, options = {}) =>
    request('/bookings', {
      method: 'POST',
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
      signal: options.signal,
      body: JSON.stringify(data),
    }),

  createGuest: (data, idempotencyKey) =>
    request('/bookings/guest', {
      method: 'POST',
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
      body: JSON.stringify(data),
    }),

  availableSlots: ({ branchId, staffId, serviceIds, date, variantSelections }) => {
    const query = new URLSearchParams({
      branchId,
      staffId: staffId || '',
      date,
      serviceIds: serviceIds.join(','),
    });
    if (variantSelections && Object.keys(variantSelections).length) query.set('variantSelections', JSON.stringify(variantSelections));
    return request(`/bookings/available-slots?${query.toString()}`);
  },

  counterSlots: ({ branchId, staffId, serviceIds, date, variantSelections, source }) => {
    const query = new URLSearchParams({
      branchId,
      staffId: staffId || '',
      date,
      serviceIds: serviceIds.join(','),
    });
    if (source) query.set('source', source);
    if (variantSelections && Object.keys(variantSelections).length) query.set('variantSelections', JSON.stringify(variantSelections));
    return request(`/bookings/counter-slots?${query.toString()}`);
  },

  getScheduler: (branchId, startDate, endDate) =>
    request(
      `/bookings/scheduler?branchId=${branchId}&startDate=${startDate}&endDate=${endDate}`,
    ),

  getSchedulerBatch: async (branchIds, startDate, endDate) => {
    const ids = [...new Set(branchIds.map(String))];
    const chunks = [];
    for (let index = 0; index < ids.length; index += 50) chunks.push(ids.slice(index, index + 50));
    const responses = await Promise.all(chunks.map((chunk) => {
      const query = new URLSearchParams({ branchIds: chunk.join(','), startDate, endDate });
      return request(`/bookings/scheduler/batch?${query}`);
    }));
    return responses.flatMap((response) => response.branches || []);
  },

  moveBooking: (id, newStartTime, newEndTime, newStaffId) =>
    request(`/bookings/${id}/move`, {
      method: 'PATCH',
      body: JSON.stringify({ newStartTime, newEndTime, newStaffId }),
    }),

  resizeBooking: (id, newEndTime) =>
    request(`/bookings/${id}/resize`, {
      method: 'PATCH',
      body: JSON.stringify({ newEndTime }),
    }),

  checkin: (id) => request(`/bookings/${id}/checkin`, { method: 'POST' }),

  assignStaff: (id, staffId) =>
    request(`/bookings/${id}/assign`, {
      method: 'PATCH',
      body: JSON.stringify({ staffId }),
    }),
  addItem: (id, data) => request(`/bookings/${id}/items`, { method: 'POST', body: JSON.stringify(data) }),
  updateItem: (id, itemId, data) => request(`/bookings/${id}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify(data) }),
  correctActualTime: (id, itemId, data) => request(`/bookings/${id}/items/${itemId}/actual-time`, { method: 'PATCH', body: JSON.stringify(data) }),
  actualTimeCorrectionHistory: (id, itemId) => request(`/bookings/${id}/items/${itemId}/actual-time`),

  // ===== Bổ sung nghiệp vụ 2026-07-10 =====
  myAppointments: (tab = 'upcoming') =>
    request(`/bookings/my-appointments?tab=${tab}`),

  salonQueue: () => request('/bookings/salon-queue'),

  salonViolations: () => request('/bookings/salon-violations'),

  previewPrice: ({ customerId, branchId, serviceIds, comboId, voucherCode, variantSelections, appointmentDate }) =>
    request('/bookings/preview-price', {
      method: 'POST',
      body: JSON.stringify({ customerId, branchId, serviceIds, comboId, voucherCode, variantSelections, appointmentDate }),
    }),

  createChangeRequest: (bookingId, body) =>
    request(`/bookings/${bookingId}/change-requests`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),

  approveChangeRequest: (reqId, reviewNote) =>
    request(`/bookings/change-requests/${reqId}/approve`, {
      method: 'PATCH',
      body: JSON.stringify({ reviewNote }),
    }),

  rejectChangeRequest: (reqId, reviewNote) =>
    request(`/bookings/change-requests/${reqId}/reject`, {
      method: 'PATCH',
      body: JSON.stringify({ reviewNote }),
    }),

  pendingChangeRequests: () => request('/bookings/change-requests/pending'),
};

// ======================== REPORTS ========================
export const reportsApi = {
  getOverview: () => request('/reports/overview'),
  getDashboardOverview: () => request('/reports/overview'),
  getRevenue: (year) =>
    request(`/reports/revenue${year ? `?year=${year}` : ''}`),
  getCategories: () => request('/reports/categories'),
  getServices: () => request('/reports/services'),
  getTopSalons: (limit = 5) => request(`/reports/top-salons?limit=${limit}`),
  getUserGrowth: (year) =>
    request(`/reports/user-growth${year ? `?year=${year}` : ''}`),
  getStaffPerformance: (branchId) =>
    request(
      `/reports/staff-performance${branchId ? `?branchId=${branchId}` : ''}`,
    ),
  getOwnerDashboard: ({ branchId, from, to }) => {
    const query = new URLSearchParams({ from, to });
    if (branchId && branchId !== 'ALL') query.set('branchId', branchId);
    return request(`/reports/owner-dashboard?${query.toString()}`);
  },
  getFinancialSummary: ({ from, to, branchId } = {}) => {
    const query = new URLSearchParams({ from, to });
    if (branchId && branchId !== 'ALL') query.set('branchId', branchId);
    return request(`/reports/financial-summary?${query.toString()}`);
  },
};

// ======================== USERS ========================
export const usersApi = {
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.append(key, String(value));
      }
    });
    return request(`/users?${query.toString()}`);
  },

  getById: (id) => request(`/users/${id}`),
  getMe: () => request('/users/me/profile'),
  updateMe: (data) => request('/users/me/profile', { method: 'PATCH', body: JSON.stringify(data) }),
  suspensionImpact: (id) => request(`/users/${id}/suspension-impact`),
  suspend: (id, input) => request(`/users/${id}/suspend`, { method: 'POST', body: JSON.stringify(input) }),
  assignRole: (id, data) => request(`/users/${id}/roles`, { method: 'POST', body: JSON.stringify(data) }),
  revokeRole: (id, roleCode, businessId, branchId) => {
    const query = new URLSearchParams();
    if (businessId) query.set('businessId', businessId);
    if (branchId) query.set('branchId', branchId);
    return request(`/users/${id}/roles/${roleCode}?${query.toString()}`, { method: 'DELETE' });
  },
};

// ======================== BRANCHES ========================
export const branchesApi = {
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.append(key, String(value));
      }
    });
    return request(`/branches?${query.toString()}`);
  },

  getManage: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') query.append(key, String(value));
    });
    return request(`/branches/manage?${query.toString()}`);
  },

  getAccessible: () => request('/branches/accessible'),
  getAccessibleById: (id) => request(`/branches/accessible/${id}`),
  getPreview: (id) => request(`/branches/${id}/preview`),
  getDistricts: () => request('/branches/locations/districts'),
  createDraft: (data) => request('/branches', {
    method: 'POST',
    body: JSON.stringify(data),
  }),
  saveOnboarding: (id, data) => request(`/branches/${id}/onboarding`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  }),
  copyServices: (id, sourceBranchId, serviceIds) => request(`/branches/${id}/copy-services`, {
    method: 'POST',
    body: JSON.stringify({ sourceBranchId, serviceIds }),
  }),
  attachDocument: (id, data) => request(`/branches/${id}/documents`, {
    method: 'POST',
    body: JSON.stringify(data),
  }),
  archiveDocument: (id, documentId) => request(`/branches/${id}/documents/${documentId}`, {
    method: 'DELETE',
  }),
  submit: (id) => request(`/branches/${id}/submit`, { method: 'POST' }),
  publish: (id) => request(`/branches/${id}/publish`, { method: 'POST' }),

  update: (id, data) => request(`/branches/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  }),

  getById: (id) => request(`/branches/${id}`),
  getDetail: (id) => request(`/branches/accessible/${id}`),

  updateStatus: (id, status, reason) =>
    request(`/branches/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status, reason }),
    }),
};

// ======================== SERVICES ========================
export const servicesApi = {
  search: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
    });
    return request(`/services/search${query.size ? `?${query.toString()}` : ''}`);
  },

  getAll: (branchOrParams) => {
    const params = typeof branchOrParams === 'string' ? { branchId: branchOrParams } : branchOrParams || {};
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => value !== undefined && value !== null && value !== '' && query.set(key, String(value)));
    return request(`/services${query.size ? `?${query.toString()}` : ''}`);
  },

  getManage: (branchId) =>
    request(`/services/manage${branchId ? `?branchId=${branchId}` : ''}`),

  getManageOffering: (id) => request(`/services/offerings/${encodeURIComponent(id)}`),

  getWorkspace: (branchId) =>
    request(`/services/workspace/manage${branchId && branchId !== 'ALL' ? `?branchId=${encodeURIComponent(branchId)}` : ''}`),

  getCategories: () => request('/services/categories'),
  getManageCategories: () => request('/services/categories/manage'),
  createCategory: (data) => request('/services/categories', {
    method: 'POST', body: JSON.stringify(data),
  }),
  getCanonical: () => request('/services/canonical'),
  getCanonicalManage: () => request('/services/canonical/manage'),
  createCanonical: (data) => request('/services/canonical', {
    method: 'POST', body: JSON.stringify(data),
  }),
  updateCanonical: (id, data) => request(`/services/canonical/${id}`, {
    method: 'PATCH', body: JSON.stringify(data),
  }),

  getById: (id) => request(`/services/${id}`),
  getVariants: (id) => request(`/services/${id}/variants`),
  createVariant: (id, data) => request(`/services/${id}/variants`, { method: 'POST', body: JSON.stringify(data) }),
  updateVariant: (id, data) => request(`/services/variants/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  addDependency: (id, data) => request(`/services/${id}/dependencies`, { method: 'POST', body: JSON.stringify(data) }),
  createPriceRule: (id, data) => request(`/services/${id}/price-rules`, { method: 'POST', body: JSON.stringify(data) }),

  create: (data) =>
    request('/services', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  createCatalog: (data) => request('/services/catalog', {
    method: 'POST', body: JSON.stringify(data),
  }),

  updateCatalog: (id, data) => request(`/services/catalog/${id}`, {
    method: 'PATCH', body: JSON.stringify(data),
  }),

  archiveCatalog: (id) => request(`/services/catalog/${id}`, { method: 'DELETE' }),

  setBranchAvailability: (id, branchId, action) =>
    request(`/services/catalog/${id}/branches/${branchId}/${action}`, { method: 'POST' }),

  updateOfferingStatus: (id, data) => request(`/services/offerings/${id}/status`, {
    method: 'PATCH', body: JSON.stringify(data),
  }),

  updateOfferingPricing: (id, data) => request(`/services/offerings/${id}/pricing`, {
    method: 'PATCH', body: JSON.stringify(data),
  }),

  update: (id, data) =>
    request(`/services/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  remove: (id) =>
    request(`/services/${id}`, { method: 'DELETE' }),
};

// ======================== STAFF ========================
export const staffApi = {
  getPublic: (branchId, serviceIds = []) => {
    const query = new URLSearchParams({ branchId });
    if (serviceIds.length) query.set('serviceIds', serviceIds.join(','));
    return request(`/staff/public?${query.toString()}`);
  },
  getPublicById: (id) => request(`/staff/public/${id}`),

  getAll: (branchId) =>
    request(`/staff${branchId ? `?branchId=${branchId}` : ''}`),

  getById: (id) => request(`/staff/${id}`),
  getMine: () => request('/staff/me'),

  create: (data) =>
    request('/staff', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  update: (id, data) =>
    request(`/staff/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  getOffboardingImpact: (id) => request(`/staff/${id}/offboarding-impact`),
  deactivate: (id, data) =>
    request(`/staff/${id}`, { method: 'DELETE', body: JSON.stringify(data) }),

  getServices: (id) => request(`/staff/${id}/services`),

  assignServices: (id, serviceIds) =>
    request(`/staff/${id}/services`, {
      method: 'PATCH',
      body: JSON.stringify({ serviceIds }),
    }),

  getCommission: (id, startDate, endDate) => {
    const params = new URLSearchParams();
    if (startDate) params.append('startDate', startDate);
    if (endDate) params.append('endDate', endDate);
    return request(`/staff/${id}/commission?${params.toString()}`);
  },
  invite: (data) => request('/staff/invitations', { method: 'POST', body: JSON.stringify(data) }),
  getInvitations: (businessId, branchId) => {
    const query = new URLSearchParams({ businessId });
    if (branchId) query.set('branchId', branchId);
    return request(`/staff/invitations?${query.toString()}`);
  },
  getInvitationContext: (token) => request(`/staff/invitations/context?token=${encodeURIComponent(token)}`),
  acceptExistingInvitation: (token) => request('/staff/invitations/accept-existing', { method: 'POST', body: JSON.stringify({ token }) }),
  resendInvitation: (id) => request(`/staff/invitations/${id}/resend`, { method: 'POST' }),
  changeInvitationEmail: (id, email) => request(`/staff/invitations/${id}/email`, { method: 'PATCH', body: JSON.stringify({ email }) }),
  revokeInvitation: (id) => request(`/staff/invitations/${id}`, { method: 'DELETE' }),
  acceptInvitation: (data) => request('/staff/invitations/accept', { method: 'POST', body: JSON.stringify(data) }),
  upsertHoliday: (branchId, data) => request(`/staff/branches/${branchId}/holidays`, { method: 'PATCH', body: JSON.stringify(data) }),
  createSpecialDay: (branchId, data) => request(`/staff/branches/${branchId}/special-days`, { method: 'POST', body: JSON.stringify(data) }),
  assignBranch: (id, data) => request(`/staff/${id}/branch-assignments`, {
    method: 'POST',
    body: JSON.stringify(data),
  }),
};

// ======================== PROMOTIONS ========================
export const promotionsApi = {
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.append(key, String(value));
      }
    });
    return request(`/promotions?${query.toString()}`);
  },

  getActiveForService: (serviceId) =>
    request(`/promotions/service/${serviceId}`),

  create: (data) =>
    request('/promotions', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  update: (id, data) =>
    request(`/promotions/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  remove: (id) =>
    request(`/promotions/${id}`, { method: 'DELETE' }),
};

// ======================== COMBOS ========================
export const combosApi = {
  getPublic: (branchId) => request(`/combos/public${branchId ? `?branchId=${encodeURIComponent(branchId)}` : ''}`),
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => value && query.set(key, String(value)));
    return request(`/combos${query.size ? `?${query.toString()}` : ''}`);
  },
  getById: (id) => request(`/combos/${id}`),
  create: (data) => request('/combos', { method: 'POST', body: JSON.stringify(data) }),
  update: (id, data) => request(`/combos/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  remove: (id) => request(`/combos/${id}`, { method: 'DELETE' }),
};

// ======================== RECURRING BOOKINGS ========================
export const recurringApi = {
  preview: (data) => request('/recurring/preview', { method: 'POST', body: JSON.stringify(data) }),
  create: (data, idempotencyKey, options = {}) => request('/recurring', {
    method: 'POST', body: JSON.stringify(data), signal: options.signal,
    headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
  }),
  mine: () => request('/recurring/mine'),
  changeStatus: (id, status) => request(`/recurring/${id}/status`, { method: 'PATCH', body: JSON.stringify({ status }) }),
  cancelOccurrence: (id, bookingId) => request(`/recurring/${id}/occurrences/${bookingId}`, { method: 'DELETE' }),
  cancel: (id) => request(`/recurring/${id}`, { method: 'DELETE' }),
};

// ======================== MEDIA ========================
export const mediaApi = {
  upload: (file, metadata) => {
    const body = new FormData();
    body.append('file', file);
    Object.entries(metadata).forEach(([key, value]) => value !== undefined && value !== null && body.append(key, String(value)));
    return request('/media/upload', { method: 'POST', body });
  },
    remove: (id) => request(`/media/${id}`, { method: 'DELETE' }),
    download: (id) => request(`/media/${id}/content`, { responseType: 'blob' }),
  };

// ======================== VOUCHERS ========================
export const vouchersApi = {
  getMine: () => request('/vouchers/mine'),

  getAll: (status) =>
    request(`/vouchers${status ? `?status=${status}` : ''}`),

  create: (data) =>
    request('/vouchers', {
      method: 'POST',
      body: JSON.stringify({ ...data, businessId: data.businessId ?? useAuthStore.getState().activeContext?.().tenantId ?? undefined }),
    }),

  update: (id, data) =>
    request(`/vouchers/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),

  grant: (id, customerId) =>
    request(`/vouchers/${id}/grant`, {
      method: 'POST',
      body: JSON.stringify({ customerId }),
    }),

  remove: (id) =>
    request(`/vouchers/${id}`, { method: 'DELETE' }),
};

// ======================== NOTIFICATIONS ========================
export const notificationsApi = {
  getAll: (params = {}) => {
    const query = new URLSearchParams();
    if (params.page) query.append('page', String(params.page));
    if (params.limit) query.append('limit', String(params.limit));
    if (params.unreadOnly) query.append('unreadOnly', 'true');
    if (params.state) query.append('state', params.state);
    if (params.type && params.type !== 'ALL') query.append('type', params.type);
    if (params.severity && params.severity !== 'ALL') query.append('severity', params.severity);
    if (params.search?.trim()) query.append('search', params.search.trim());
    return request(`/notifications?${query.toString()}`);
  },

  getUnreadCount: () => request('/notifications/unread-count'),

  markAsRead: (id) =>
    request(`/notifications/${id}/read`, { method: 'PATCH' }).then(result => { window.dispatchEvent(new Event('beautybook:notifications-changed')); return result; }),

  markAllAsRead: () =>
    request('/notifications/read-all', { method: 'PATCH' }).then(result => { window.dispatchEvent(new Event('beautybook:notifications-changed')); return result; }),

  registerDeviceToken: (token, platform) =>
    request('/notifications/device-token', {
      method: 'POST',
      body: JSON.stringify({ token, platform }),
    }),
};

// ======================== REVIEWS ========================
export const reviewsApi = {
  getByBusiness: (businessId, page = 1, limit = 20) =>
    request(`/reviews/business/${businessId}?page=${page}&limit=${limit}`),

  getForManagement: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.append(key, String(value));
      }
    });
    return request(`/reviews/manage?${query.toString()}`);
  },

  getByStaff: (staffId) => request(`/reviews/staff/${staffId}`),
  getPublicByStaff: (staffId) => request(`/reviews/public/staff/${staffId}`),
  getByService: (serviceId) => request(`/reviews/service/${serviceId}`),

  create: (data) =>
    request('/reviews', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  reply: (reviewId, content) =>
    request(`/reviews/${reviewId}/reply`, {
      method: 'POST',
      body: JSON.stringify({ content }),
    }),

  moderate: (reviewId, data) =>
    request(`/reviews/${reviewId}/moderate`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),
  report: (reviewId, data) => request(`/reviews/${reviewId}/report`, { method: 'POST', body: JSON.stringify(typeof data === 'string' ? { reason: data } : data) }),
  appeal: (reviewId, reason) => request(`/reviews/${reviewId}/appeals`, { method: 'POST', body: JSON.stringify({ reason }) }),
  resolveAppeal: (appealId, data) => request(`/reviews/appeals/${appealId}`, { method: 'PATCH', body: JSON.stringify(data) }),
};

// ======================== ADMIN ========================
export const adminApi = {
  getSettings: () => request('/admin/settings'),
  updateSettings: (settings) => request('/admin/settings', { method: 'PATCH', body: JSON.stringify({ settings }) }),
  resetSettings: () => request('/admin/settings/reset', { method: 'POST' }),
  getTrustSnapshots: () => request('/admin/trust-snapshots'),
  rebuildTrustSnapshots: () => request('/admin/trust-snapshots/rebuild', { method: 'POST' }),
  getTrustActions: () => request('/admin/trust-actions'),
  createTrustAction: (data) => request('/admin/trust-actions', { method: 'POST', body: JSON.stringify(data) }),
  getAuditLogs: () => request('/admin/audit-logs'),
  getBusinessDirectory: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => value !== undefined && value !== null && value !== '' && query.set(key, String(value)));
    return request(`/admin/business-directory${query.size ? `?${query.toString()}` : ''}`);
  },
  getBranchDirectory: (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => value !== undefined && value !== null && value !== '' && query.set(key, String(value)));
    return request(`/admin/branch-directory${query.size ? `?${query.toString()}` : ''}`);
  },
  getComplianceQueue: () => request('/admin/compliance-queue'),
  getFinanceOverview: () => request('/admin/finance-overview'),
  getMarketingOverview: () => request('/admin/marketing-overview'),
};

export const platformPolicyApi = {
  getPublic: () => request('/platform-settings/public'),
};

// ======================== BUSINESS ========================
export const businessApi = {
  getDetail: (businessId) => request(`/business/${businessId}/detail`),
  getMembers: (businessId) => request(`/business/${businessId}/members`),
  getCancellationPolicy: (businessId) => request(`/business/${businessId}/cancellation-policy`),
  updateCancellationPolicy: (businessId, policy) => request(`/business/${businessId}/cancellation-policy`, {
    method: 'PATCH',
    body: JSON.stringify(policy),
  }),
  createDraft: (data) => request('/business/onboarding/draft', { method: 'POST', body: JSON.stringify(data) }),
  getOnboardingConfig: () => request('/business/onboarding/config'),
  getMyOnboarding: () => request('/business/onboarding/mine'),
  updateDraft: (businessId, data) => request(`/business/${businessId}/onboarding`, { method: 'PATCH', body: JSON.stringify(data) }),
  submit: (businessId) => request(`/business/${businessId}/submit`, { method: 'POST' }),
  review: (businessId, decision, note) => request(`/business/${businessId}/review`, { method: 'PATCH', body: JSON.stringify({ decision, note }) }),
};

// ======================== PAYMENTS / REFUNDS ========================
export const paymentsApi = {
  getAll: () => request('/payments'),
  collect: (bookingId, method, options = {}) => request('/payments/collect', {
    method: 'POST',
    body: JSON.stringify({ bookingId, method, ...options }),
  }),
  getProviders: () => request('/payments/providers'),
  getCheckout: (bookingId) => request(`/payments/checkout/${bookingId}`),
  verifyTransaction: (transactionId, settlementReference, evidence) =>
    request(`/payments/transactions/${transactionId}/verify`, {
      method: 'POST',
      body: JSON.stringify({ settlementReference, evidence }),
    }),
  reverseTransaction: (transactionId, reason, idempotencyKey) =>
    request(`/payments/transactions/${transactionId}/reverse`, {
      method: 'POST',
      body: JSON.stringify({ reason, idempotencyKey }),
    }),
  getPolicies: (businessId) => request(`/payments/policies?businessId=${encodeURIComponent(businessId)}`),
  createPolicy: (data) => request('/payments/policies', { method: 'POST', body: JSON.stringify(data) }),
  getLedger: (filters = {}) => {
    const query = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value) query.set(key, value);
    });
    return request(`/payments/ledger${query.size ? `?${query}` : ''}`);
  },
  getStatements: (businessId) => request(`/payments/platform-statements${businessId ? `?businessId=${encodeURIComponent(businessId)}` : ''}`),
  generateStatement: (data) => request('/payments/platform-statements/generate', { method: 'POST', body: JSON.stringify(data) }),
  transitionStatement: (id, data) => request(`/payments/platform-statements/${id}/status`, { method: 'PATCH', body: JSON.stringify(data) }),
  getPackages: (filters = {}) => {
    const query = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value) query.set(key, value);
    });
    return request(`/payments/packages${query.size ? `?${query}` : ''}`);
  },
  createPackage: (data) => request('/payments/packages', { method: 'POST', body: JSON.stringify(data) }),
  purchasePackage: (packageId, data) => request(`/payments/packages/${packageId}/purchases`, { method: 'POST', body: JSON.stringify(data) }),
  getPackagePurchases: (businessId) => request(`/payments/package-purchases${businessId ? `?businessId=${encodeURIComponent(businessId)}` : ''}`),
  payPackageInstallment: (installmentId, data) => request(`/payments/package-installments/${installmentId}/pay`, { method: 'POST', body: JSON.stringify(data) }),
  reservePackageSession: (purchaseId, data) => request(`/payments/package-purchases/${purchaseId}/sessions/reserve`, { method: 'POST', body: JSON.stringify(data) }),
  requestRefund: (paymentId, amount, reason, evidence) =>
    request(`/payments/${paymentId}/refund-requests`, {
      method: 'POST',
      body: JSON.stringify({ amount, reason, evidence }),
    }),
  reviewRefund: (refundId, approve, note) =>
    request(`/payments/refunds/${refundId}/review`, {
      method: 'PATCH',
      body: JSON.stringify({ approve, note }),
    }),
  processRefund: (refundId, payload = { action: 'START' }) =>
    request(`/payments/refunds/${refundId}/process`, {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
};

// ======================== PRIVACY CENTER ========================
export const privacyApi = {
  getCenter: () => request('/privacy/center'),
  createDataRequest: (data) => request('/privacy/data-requests', {
    method: 'POST',
    body: JSON.stringify(data),
  }),
  getDataRequests: () => request('/privacy/data-requests'),
  getMarketingPreferences: () => request('/privacy/marketing-preferences'),
  updateMarketingPreferences: (data) => request('/privacy/marketing-preferences', {
    method: 'PUT',
    body: JSON.stringify(data),
  }),
  createExport: (currentPassword) => request('/privacy/exports', {
    method: 'POST',
    body: JSON.stringify({ currentPassword }),
  }),
  downloadExport: (id, downloadToken) => request(`/privacy/exports/${id}/download`, {
    headers: { 'X-Privacy-Download-Token': downloadToken },
    cache: 'no-store',
  }),
};

// ======================== BUSINESS COMPLETION 2026-08 ========================
export const savedServicesApi = {
  list: () => request('/customer/saved-services'),
  save: (serviceId) => request(`/customer/saved-services/${serviceId}`, { method: 'POST' }),
  remove: (serviceId) => request(`/customer/saved-services/${serviceId}`, { method: 'DELETE' }),
};

export const impactApi = {
  list: () => request('/operational-impacts'),
  detail: (id) => request(`/operational-impacts/${id}`),
  resolve: (id, itemId, data) => request(`/operational-impacts/${id}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify(data) }),
  resolveBatch: (id, data) => request(`/operational-impacts/${id}/items`, { method: 'PATCH', body: JSON.stringify(data) }),
  complete: (id) => request(`/operational-impacts/${id}/complete`, { method: 'POST' }),
};

export const ownershipApi = {
  list: (businessId) => request(`/ownership-transfers?businessId=${encodeURIComponent(businessId)}`),
  platformQueue: () => request('/ownership-transfers/platform/pending'),
  incoming: () => request('/ownership-transfers/pending-for-me'),
  create: (data) => request('/ownership-transfers', { method: 'POST', body: JSON.stringify(data) }),
  accept: (id) => request(`/ownership-transfers/${id}/accept`, { method: 'PATCH' }),
  submitMoreInfo: (id, data) => request(`/ownership-transfers/${id}/submit-more-info`, { method: 'PATCH', body: JSON.stringify(data) }),
  cancel: (id, reason) => request(`/ownership-transfers/${id}/cancel`, { method: 'PATCH', body: JSON.stringify({ reason }) }),
  review: (id, data) => request(`/ownership-transfers/${id}/review`, { method: 'PATCH', body: JSON.stringify(data) }),
  execute: (id) => request(`/ownership-transfers/${id}/execute`, { method: 'POST' }),
  versions: (businessId) => request(`/ownership-transfers/business/${businessId}/versions`),
  legalVersion: (businessId, data) => request(`/ownership-transfers/business/${businessId}/legal-entity`, { method: 'POST', body: JSON.stringify(data) }),
  payoutVersion: (businessId, data) => request(`/ownership-transfers/business/${businessId}/payout-account`, { method: 'POST', body: JSON.stringify(data) }),
  verifyLegalVersion: (id, data) => request(`/ownership-transfers/platform/legal-entity/${id}/verify`, { method: 'PATCH', body: JSON.stringify(data) }),
  verifyPayoutVersion: (id, data) => request(`/ownership-transfers/platform/payout-account/${id}/verify`, { method: 'PATCH', body: JSON.stringify(data) }),
};
