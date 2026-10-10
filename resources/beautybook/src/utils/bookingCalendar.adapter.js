import { serverTimeAnchor } from './operationalTiming.js';

const pad = (value) => String(value).padStart(2, '0');

function datePart(value) {
  if (!value) return null;
  if (typeof value === 'string') {
    const match = value.match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) return match[1];
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function timePart(value) {
  if (!value) return null;
  if (typeof value === 'string') {
    const match = value.match(/(?:T|\s)(\d{2}):(\d{2})/);
    if (match) return `${match[1]}:${match[2]}`;
    if (/^\d{2}:\d{2}/.test(value)) return value.slice(0, 5);
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
}

export function combineDateTime(dateValue, timeValue) {
  const day = datePart(dateValue) || datePart(timeValue);
  const time = timePart(timeValue) || '00:00';
  if (!day) return null;
  const result = new Date(`${day}T${time}:00`);
  return Number.isNaN(result.getTime()) ? null : result;
}

const asNumber = (value) => {
  if (value == null || value === '') return 0;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

export function normalizeBooking(raw) {
  const bookingServices = Array.isArray(raw?.bookingServices)
    ? raw.bookingServices
    : Array.isArray(raw?.services)
      ? raw.services
      : [];

  const services = bookingServices.map((item) => {
    const service = item?.service ?? item;
    const staff = item?.staff ?? item?.staffProfile ?? null;
    return {
      bookingServiceId: item?.bookingServiceId ?? item?.id ?? null,
      itemStartAt: item?.itemStartAt ?? null,
      itemEndAt: item?.itemEndAt ?? null,
      id: service?.id ?? item?.serviceId ?? null,
      name: service?.name ?? item?.name ?? 'Dịch vụ',
      durationMinutes: asNumber(item?.durationMinutes ?? item?.duration ?? service?.durationMinutes ?? service?.duration),
      actualStartedAt: item?.actualStartedAt ?? null,
      actualCompletedAt: item?.actualCompletedAt ?? null,
      actualStoppedAt: item?.actualStoppedAt ?? null,
      actualTimingSource: item?.actualTimingSource ?? 'UNAVAILABLE',
      actualTimingStatus: item?.actualTimingStatus ?? null,
      canCorrectActualTime: item?.canCorrectActualTime === true,
      staffId: item?.staffId ?? staff?.id ?? null,
      staffUserId: staff?.userId ?? staff?.user?.id ?? item?.staffUserId ?? null,
      staffName: staff?.fullName ?? staff?.user?.fullName ?? item?.staffName ?? null,
      status: item?.status ?? 'SCHEDULED',
      revision: item?.revision ?? 1,
      priceAtBooking: asNumber(item?.priceAtBooking),
    };
  });

  const startAt = combineDateTime(
    raw?.appointmentDate ?? raw?.appointment_time ?? raw?.startAt,
    raw?.appointmentStartTime ?? raw?.appointment_start ?? raw?.startAt ?? raw?.appointment_time,
  );
  const endAt = combineDateTime(
    raw?.appointmentDate ?? raw?.appointment_time ?? raw?.endAt,
    raw?.appointmentEndTime ?? raw?.appointment_end ?? raw?.endAt,
  );

  const primaryStaffId = services.find((item) => item.staffId)?.staffId ?? raw?.staffId ?? null;
  const primaryStaffName = services.find((item) => item.staffId)?.staffName ?? raw?.staffName ?? null;

  return {
    id: raw?.bookingId ?? raw?.id ?? raw?.bookingCode,
    bookingCode: raw?.bookingCode ?? raw?.booking_code ?? raw?.id ?? '—',
    status: raw?.statusEnum ?? raw?.status ?? 'PENDING',
    timingAnchor: raw?.timingAnchor ?? serverTimeAnchor(raw?.serverNow),
    customerId: raw?.customerId ?? raw?.customer?.id ?? null,
    customerName: raw?.customer?.user?.fullName ?? raw?.customer_name ?? raw?.customerName ?? 'Khách hàng',
    customerPhone: raw?.customer?.user?.phone ?? raw?.customer_phone ?? raw?.customerPhone ?? null,
    customerEmail: raw?.customer?.user?.email ?? raw?.customer_email ?? null,
    branchId: raw?.branchId ?? raw?.branch?.id ?? null,
    businessId: raw?.businessId ?? raw?.branch?.businessId ?? raw?.branch?.business?.id ?? null,
    branchName: raw?.branch?.name ?? raw?.branch_name ?? raw?.salon_name ?? null,
    businessName: raw?.branch?.business?.name ?? raw?.businessName ?? null,
    services,
    serviceIds: services.map((item) => item.id).filter(Boolean),
    serviceNames: services.map((item) => item.name),
    primaryStaffId,
    primaryStaffName,
    controlledOverbooking: Boolean(raw?.overbookingOverride) || (services.length > 0 && services.every((item) => !item.staffId)),
    startAt,
    endAt,
    totalAmount: asNumber(raw?.finalAmount ?? raw?.totalAmount ?? raw?.amount),
    discountAmount: asNumber(raw?.voucherDiscountAmount ?? raw?.discountAmount),
    note: raw?.note ?? null,
    internalNote: raw?.internalNote ?? raw?.internal_note ?? null,
    raw,
  };
}

export function normalizeSchedulerResponse(payload) {
  const staff = (Array.isArray(payload?.staff) ? payload.staff : []).map((item) => ({
    id: item.id,
    userId: item.userId ?? item.user?.id ?? null,
    name: item.fullName ?? item.user?.fullName ?? 'Nhân viên',
    avatarUrl: item.user?.avatarMedia?.url ?? null,
  }));

  const bookings = (Array.isArray(payload?.bookings) ? payload.bookings : [])
    .map(normalizeBooking)
    .filter((booking) => booking.id && booking.startAt && booking.endAt);

  return { staff, bookings };
}
