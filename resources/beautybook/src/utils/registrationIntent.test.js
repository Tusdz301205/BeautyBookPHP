import { test } from 'node:test';
import assert from 'node:assert/strict';
import { registrationReturnPath, businessRegistrationTarget, documentChecklist, currentLegalVersion } from './registrationIntent.js';

test('business intent survives query-based refresh and rejects external redirects', () => {
  assert.equal(registrationReturnPath({ search: '?from=%2Fregister%2Fbusiness' }), '/register/business');
  assert.equal(registrationReturnPath({ state: { from: '/salon/onboarding' } }), '/salon/onboarding');
  for (const from of ['//evil.example', 'https://evil.example', '/\\evil.example', '/\nfoo']) {
    assert.equal(registrationReturnPath({ state: { from } }), null);
  }
});

test('existing owners resume their existing onboarding and customers need an explicit supported path', () => {
  assert.equal(businessRegistrationTarget('salon'), '/salon/onboarding');
  assert.equal(businessRegistrationTarget('customer'), null);
  assert.equal(businessRegistrationTarget('admin'), null);
});

test('checklist follows required types rather than counting uploaded files', () => {
  assert.deepEqual(documentChecklist([
    { documentType: 'BUSINESS_LICENSE', mediaId: 'a', documentName: 'License' },
    { documentType: 'BUSINESS_LICENSE', mediaId: 'b', documentName: 'Another license' },
    { documentType: 'OWNER_ID_CARD', documentName: 'Not uploaded' },
  ], ['BUSINESS_LICENSE', 'OWNER_ID_CARD']), [
    { type: 'BUSINESS_LICENSE', complete: true }, { type: 'OWNER_ID_CARD', complete: false },
  ]);
  assert.deepEqual(documentChecklist([], []), []);
});

test('document review never silently substitutes a replaced version for the current one', () => {
  assert.equal(currentLegalVersion({ currentVersion: 2, versions: [{ version: 1, mediaId: 'old' }] }), null);
  assert.equal(currentLegalVersion({ currentVersion: 2, versions: [{ version: 1 }, { version: 2, mediaId: 'current' }] }).mediaId, 'current');
});
