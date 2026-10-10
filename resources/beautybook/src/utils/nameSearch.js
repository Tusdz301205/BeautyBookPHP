export function normalizeNameSearch(value) {
  return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[đĐ]/g, 'd').toLocaleLowerCase('vi-VN').trim().replace(/\s+/g, ' ');
}
