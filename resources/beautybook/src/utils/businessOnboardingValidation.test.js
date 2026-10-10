import test from 'node:test';
import assert from 'node:assert/strict';
import {
  businessErrorField,
  isInternalBusinessDraftName,
  normalizeBusinessPhone,
  toBusinessOnboardingError,
  validateBusinessIdentity,
  validateBusinessType,
  validateDocuments,
  validCompletedSteps,
} from './businessOnboardingValidation.js';

const validIdentity = {
  name: 'Tiệm Hoa',
  slug: 'tiem-hoa',
  companyName: 'Công ty thử nghiệm',
  taxCode: 'AB-123',
  identityCardNumber: 'ID-456',
  contactEmail: 'owner@example.vn',
  contactPhone: '0912 345 678',
  addressLine: 'Quận 1, TP.HCM',
  legalRepresentative: 'Người đại diện',
  description: '',
};

test('rejects the generated draft name instead of treating it as the public brand', () => {
  assert.equal(isInternalBusinessDraftName(' Hồ sơ cơ sở của chủ tài khoản '), true);
  assert.equal(isInternalBusinessDraftName('Tiệm Hoa'), false);
  assert.equal(validateBusinessIdentity({ ...validIdentity, name: 'Hồ sơ cơ sở của chủ tài khoản' }).name,
    'Nhập tên thương hiệu có ít nhất 2 ký tự.');
});

test('validates required onboarding fields, formatting and shared limits', () => {
  assert.deepEqual(validateBusinessIdentity(validIdentity), {});
  const invalid = validateBusinessIdentity({
    ...validIdentity,
    contactEmail: 'owner@',
    contactPhone: 'abc',
    slug: 'đường dẫn',
    description: 'x'.repeat(2001),
  });
  assert.ok(invalid.contactEmail);
  assert.ok(invalid.contactPhone);
  assert.ok(invalid.slug);
  assert.ok(invalid.description);
  assert.equal(validateBusinessIdentity({ ...validIdentity, taxCode: 'MST-ABC' }).taxCode, undefined,
    'Identifier policy is not guessed in the client.');
});

test('normalizes Vietnamese contact phones and checks the current backend catalog', () => {
  assert.equal(normalizeBusinessPhone('0912.345.678'), '+84912345678');
  assert.equal(normalizeBusinessPhone('+84 912 345 678'), '+84912345678');
  const catalog = [{ code: 'SPA', label: 'Spa' }, { code: 'NAIL', label: 'Nail' }];
  assert.equal(validateBusinessType('SPA', catalog), '');
  assert.match(validateBusinessType('LEGAL_ENTITY', catalog), /đang được hỗ trợ/);
});

test('requires uploaded files, applies current document requirements and catches incomplete uploads', () => {
  assert.ok(validateDocuments([{ documentType: 'BUSINESS_LICENSE', documentName: 'Giấy phép' }], []).upload);
  assert.ok(validateDocuments([], ['BUSINESS_LICENSE']).BUSINESS_LICENSE);
  assert.deepEqual(validateDocuments([
    { documentType: 'BUSINESS_LICENSE', documentName: 'Giấy phép', mediaId: 'media-1' },
  ], ['BUSINESS_LICENSE']), {});
  assert.ok(validateDocuments([
    { documentType: 'BUSINESS_LICENSE', documentName: 'Giấy phép A', mediaId: 'media-1' },
    { documentType: 'BUSINESS_LICENSE', documentName: 'Giấy phép B', mediaId: 'media-2' },
  ], []).documentType);
});

test('maps legacy internal field errors to a Vietnamese field instruction', () => {
  assert.equal(toBusinessOnboardingError(new Error('Thiếu thông tin: name')),
    'Nhập tên thương hiệu trước khi gửi hồ sơ.');
  assert.match(toBusinessOnboardingError(new Error('PrismaClientKnownRequestError P2002')),
    /nội dung đã nhập vẫn được giữ lại/);
  assert.deepEqual(businessErrorField(new Error('Mã số thuế này đã được sử dụng trong một hồ sơ khác.')),
    { field: 'taxCode', message: 'Mã số thuế này đã được sử dụng trong một hồ sơ khác.' });
  assert.equal(toBusinessOnboardingError(Object.assign(new Error('Tên pháp nhân không được vượt quá 255 ký tự'), { status: 400 })),
    'Tên pháp nhân không được vượt quá 255 ký tự');
  assert.equal(businessErrorField(new Error('Error: PrismaClientKnownRequestError')), null);
});

test('started documents cannot disappear from the payload because their name or file is missing', () => {
  assert.ok(validateDocuments([{ documentType: 'BUSINESS_LICENSE', documentName: ' ', mediaId: 'media-1' }], []).documentDetails);
  assert.ok(validateDocuments([{ documentType: 'OTHER', documentName: '', documentNumber: 'REF' }], []).upload);
  assert.ok(validateDocuments([{ documentType: 'UNKNOWN', documentName: 'Test', mediaId: 'media-1' }], []).documentType);
  assert.deepEqual(validateDocuments([{ documentType: 'BUSINESS_LICENSE', documentName: '', mediaId: '' }], []), {});
});

test('document dates must be real calendar dates instead of normalized invalid dates', () => {
  const row = { documentType: 'OTHER', documentName: 'Test', mediaId: 'media-1' };
  assert.ok(validateDocuments([{ ...row, expiresAt: '2026-02-30' }], []).documentDetails);
  assert.ok(validateDocuments([{ ...row, expiresAt: 'not-a-date' }], []).documentDetails);
  assert.deepEqual(validateDocuments([{ ...row, expiresAt: '2028-02-29' }], []), {});
});

test('invalidated steps and their dependent steps cannot remain completed', () => {
  assert.deepEqual(validCompletedSteps([1, 2, 3], { 1: {}, 2: { name: 'missing' }, 3: {} }), [1]);
  assert.deepEqual(validCompletedSteps([1, 2, 3], { 1: {}, 2: {}, 3: {} }), [1, 2, 3]);
  assert.deepEqual(validCompletedSteps([2, 3], { 1: {}, 2: {}, 3: {} }), []);
});
