export function formatBranchSlotTime(value, timezone) {
  return new Intl.DateTimeFormat('vi-VN', {
    timeZone: timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).format(new Date(value));
}

// datetime-local has no timezone. Interpret it in the branch, never the device.
// Reject nonexistent or ambiguous local times rather than guessing a DST offset.
export function branchWallTimeToInstant(value, timezone) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) return null;
  const wall = Date.parse(`${value}:00Z`);
  if (!Number.isFinite(wall)) return null;
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  });
  const localStamp = (timestamp) => {
    const parts = Object.fromEntries(formatter.formatToParts(timestamp).map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
  };
  const candidates = new Set();
  for (const delta of [-86400000, 0, 86400000]) {
    const sample = wall + delta;
    const offset = Date.parse(`${localStamp(sample)}Z`) - sample;
    const candidate = wall - offset;
    if (localStamp(candidate) === `${value}:00`) candidates.add(candidate);
  }
  return candidates.size === 1 ? new Date([...candidates][0]) : null;
}
