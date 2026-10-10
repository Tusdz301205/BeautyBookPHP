import { test } from 'node:test';
import assert from 'node:assert/strict';
import { birthDateError, currentBirthDateDay } from './profileBirthDate.js';

test('DOB uses Vietnam calendar day at UTC rollover and validates real dates', () => {
  const now = new Date('2026-10-07T17:01:00Z');
  assert.equal(currentBirthDateDay(now), '2026-10-08');
  for (const value of ['', '2026-10-08', '2000-02-29']) assert.equal(birthDateError(value, now), '');
  for (const value of ['2026-10-09', '2025-02-29', '2026-02-30', 'not-a-date']) assert.ok(birthDateError(value, now));
});
