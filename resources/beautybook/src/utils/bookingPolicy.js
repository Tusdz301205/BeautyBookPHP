// What the branch's own booking rules allow right now, from the instants the server computed (booking.policy). The server stays the
// authority and refuses what is out of the window; this only explains the window and keeps the screen from offering what will fail.

/** The customer may still ask to move the booking until `until`; a branch without a reschedule cutoff is always open. */
export function rescheduleWindow(policy, now = Date.now()) {
  const hours = Number(policy?.rescheduleHours) || 0;
  if (!hours || !policy?.rescheduleUntil) return { open: true, hours: 0, until: null };
  const until = new Date(policy.rescheduleUntil);
  return { open: now <= until.getTime(), hours, until };
}

/** Check-in opens `earlyCheckInMinutes` before the start; marking a no-show needs more than `gracePeriodMinutes` after it. */
export function visitWindow(policy, now = Date.now()) {
  const from = policy?.checkInFrom ? new Date(policy.checkInFrom) : null;
  const after = policy?.noShowAfter ? new Date(policy.noShowAfter) : null;
  return { checkInOpen: from ? now >= from.getTime() : false, checkInFrom: from, noShowOpen: after ? now > after.getTime() : false, noShowAfter: after };
}
