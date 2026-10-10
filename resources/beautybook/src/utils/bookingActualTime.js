export function formatActualServiceTime(value, timezone = 'Asia/Ho_Chi_Minh') {
  if (!value) return null;
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  try {
    return new Intl.DateTimeFormat('vi-VN', {
      timeZone: timezone, day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).format(date);
  } catch {
    // Invalid branch configuration must not silently display device-local time.
    return `${date.toISOString()} (UTC)`;
  }
}
