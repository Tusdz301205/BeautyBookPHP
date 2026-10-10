// Prisma DATE/TIME projections encode branch-local fields in ISO strings.
// They are not absolute instants to convert into the browser's timezone.
export function formatBookingWallDate(value) {
  if (typeof value !== 'string') return '—';
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:T|$)/.exec(value);
  if (!match) return '—';
  const [, year, month, day] = match;
  const date = new Date(`${year}-${month}-${day}T00:00:00Z`);
  if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== `${year}-${month}-${day}`) return '—';
  return `${Number(day)}/${Number(month)}/${year}`;
}

export function formatBookingWallTime(value) {
  if (typeof value !== 'string') return '—';
  const match = /^(?:\d{4}-\d{2}-\d{2}T)?(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?Z?$/.exec(value);
  if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) return '—';
  return `${match[1]}:${match[2]}`;
}
