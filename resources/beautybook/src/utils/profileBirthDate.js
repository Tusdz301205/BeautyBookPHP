export function currentBirthDateDay(now = new Date()) {
  return new Date(now.getTime() + 7 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

export function birthDateError(value, now = new Date()) {
  if (!value) return '';
  const parsed = new Date(`${value}T00:00:00Z`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== value) return 'Nhập ngày sinh hợp lệ.';
  return value > currentBirthDateDay(now) ? 'Ngày sinh không được vượt quá ngày hiện tại.' : '';
}
