import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { isInternalBusinessDraftName, normalizeBusinessPhone, validateBusinessIdentity } from '../../utils/businessOnboardingValidation.js';

function harness() {
  const source = readFileSync(new URL('./BusinessOnboarding.jsx', import.meta.url), 'utf8');
  const state = {};
  const context = {
    account: {}, isInternalBusinessDraftName, normalizeBusinessPhone,
    setBusiness: () => {}, setIdentity: (value) => { state.identity = context.identity = value; },
    setDraft: () => {}, setDocuments: () => {}, setStep: () => {}, setDirty: () => {},
    legalPayload: () => [],
  };
  vm.runInNewContext([
    source.slice(source.indexOf('const identityDefaults ='), source.indexOf('const statusTone =')),
    source.slice(source.indexOf('  const hydrate ='), source.indexOf('  const load =')),
    source.slice(source.indexOf('  const payload ='), source.indexOf('  const persist =')),
    'this.hydrate = hydrate; this.payload = payload;',
  ].join('\n'), context);
  return { state, hydrate: context.hydrate, payload: context.payload };
}

test('existing unclassified business keeps its legal type empty through hydration and save payload', () => {
  const h = harness();
  h.hydrate({ id: 'existing', legalEntityType: null, name: 'Tiệm Hoa', slug: 'tiem-hoa',
    companyName: 'Công ty Hoa', taxCode: '123', contactEmail: 'hoa@example.vn', contactPhone: '0901234567',
    addressLine: '123 Đường Hoa', legalRepresentative: 'Nguyễn Hoa' });
  assert.equal(h.state.identity.legalEntityType, '');
  assert.equal(h.state.identity.registrationNumber, '');
  assert.deepEqual(validateBusinessIdentity(h.state.identity), {});
  assert.equal(h.payload(2, {}).legalEntityType, null);
});

test('new business retains the explicit default and an existing declared type survives reload', () => {
  const h = harness();
  h.hydrate(null);
  assert.equal(h.state.identity.legalEntityType, 'ENTERPRISE');
  assert.ok(validateBusinessIdentity(h.state.identity).registrationNumber);
  h.hydrate({ id: 'declared', legalEntityType: 'HOUSEHOLD_BUSINESS', registrationNumber: 'HKD-123' });
  assert.equal(h.state.identity.legalEntityType, 'HOUSEHOLD_BUSINESS');
  assert.equal(h.payload(2, {}).registrationNumber, 'HKD-123');
});
