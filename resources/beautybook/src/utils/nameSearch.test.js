import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeNameSearch } from './nameSearch.js';
test('name search supports Vietnamese accents, d/đ, Unicode decomposition and whitespace', () => {
  assert.equal(normalizeNameSearch('  ĐỖ   THỊ  HỒNG '), 'do thi hong');
  assert.equal(normalizeNameSearch('Đỗ Thị Hồng'.normalize('NFD')), 'do thi hong');
  assert.ok(normalizeNameSearch('Chăm sóc da').includes(normalizeNameSearch('  cham   SOC ')));
});
