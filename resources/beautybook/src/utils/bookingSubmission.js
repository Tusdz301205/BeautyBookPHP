// A tab-scoped journal for a dispatched booking, separate from editable wizard
// state. Never store credentials. Account changes cannot replay another user's
// attempt. Keep unresolved attempts when the wizard is reset or unmounted.
import { createIdempotencyKey } from './idempotencyKey.js';

const prefix = 'beautybook-booking-submission:';
const active = new Set();
const memory = new Map();
export const REPLAY_WINDOW_MS = 15 * 60_000;

export function readBookingSubmission(userId) {
  if (!userId) return null;
  let raw = memory.get(userId);
  try { raw ||= typeof sessionStorage === 'undefined' ? null : sessionStorage.getItem(prefix + userId); } catch { /* begin will fail before dispatch if persistence is unavailable */ }
  if (!raw) return null;
  try {
    // Restoring a dispatched request never implies that it did not commit.
    const attempt = JSON.parse(raw);
    return { ...attempt, status: 'unknown' };
  } catch { return { userId, kind: 'unrecoverable', status: 'unknown' }; }
}

function save(attempt) {
  const raw = JSON.stringify(attempt);
  if (typeof sessionStorage === 'undefined') memory.set(attempt.userId, raw);
  else sessionStorage.setItem(prefix + attempt.userId, raw);
  memory.set(attempt.userId, raw);
  return JSON.parse(raw);
}

export function clearBookingSubmission(userId) {
  memory.delete(userId);
  if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(prefix + userId);
}

export function beginBookingSubmission(userId, kind, payload) {
  const existing = readBookingSubmission(userId);
  if (existing) return existing;
  // Persist before dispatch. If storage is unavailable, no write is sent.
  return save({ userId, kind, payload: JSON.parse(JSON.stringify(payload)), key: createIdempotencyKey(), startedAt: Date.now(), status: 'pending' });
}

export function canReplayBookingSubmission(attempt, now = Date.now()) {
  // Recurring replay remains blocked until its server contract is runtime verified.
  return attempt?.kind === 'single' && now >= attempt.startedAt && now - attempt.startedAt < REPLAY_WINDOW_MS;
}

export function validBookingResult(booking) {
  return Boolean(booking?.id && booking.bookingCode && ['PENDING', 'CONFIRMED'].includes(booking.status));
}

export async function submitBookingSubmission(attempt, send) {
  if (attempt.status === 'unknown' && !canReplayBookingSubmission(attempt)) return { kind: 'unknown', attempt };
  if (active.has(attempt.userId)) return { kind: 'busy' };
  active.add(attempt.userId);
  try {
    const result = await send(attempt.payload, attempt.key);
    const booking = attempt.kind === 'recurring' ? result?.bookings?.[0] : result;
    if (!validBookingResult(booking)) throw new Error('Unverified booking response');
    clearBookingSubmission(attempt.userId);
    return { kind: 'success', booking, plan: attempt.kind === 'recurring' ? result : undefined };
  } catch (error) {
    // A rejection of a replay cannot prove that the first write did not commit.
    const definite = attempt.status === 'pending' && [400, 401, 403, 404, 422, 429].includes(error.status);
    // Only recognized pre-commit availability rejection messages are definite;
    // a generic409 can also originate from read-back after a committed write.
    const conflict = attempt.status === 'pending' && error.status === 409
      && /khung giờ này vừa được người khác đặt|nhân viên (này không còn khả dụng|phù hợp trong khung giờ)|không còn nhân viên phù hợp trong khung giờ/i.test(error.message || '')
      && !/idempotency/i.test(JSON.stringify(error.details || error.message));
    if (definite || conflict) {
      clearBookingSubmission(attempt.userId);
      return { kind: 'rejected', error };
    }
    const unknown = { ...attempt, status: 'unknown' };
    try { save(unknown); } catch { memory.set(attempt.userId, JSON.stringify(unknown)); }
    return { kind: 'unknown', attempt: unknown };
  } finally { active.delete(attempt.userId); }
}
