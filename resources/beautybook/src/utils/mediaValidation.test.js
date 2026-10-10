import test from 'node:test';
import assert from 'node:assert/strict';
import { validateUploadedFile } from './mediaValidation.js';

test('accepts only the supported document and image MIME types', () => {
  assert.equal(validateUploadedFile({ type: 'application/pdf', size: 1024 }, { document: true }), '');
  assert.match(validateUploadedFile({ type: 'text/plain', size: 1024 }, { document: true }), /PDF/);
  assert.equal(validateUploadedFile({ type: 'image/webp', size: 1024 }), '');
  assert.match(validateUploadedFile({ type: 'image/gif', size: 1024 }), /JPG, PNG, WebP hoặc AVIF/);
});

test('rejects empty and oversized uploads using the server size boundary', () => {
  assert.match(validateUploadedFile({ type: 'application/pdf', size: 0 }, { document: true }), /không được rỗng/);
  assert.equal(validateUploadedFile({ type: 'application/pdf', size: 10 * 1024 * 1024 }, { document: true }), '');
  assert.match(validateUploadedFile({ type: 'application/pdf', size: 10 * 1024 * 1024 + 1 }, { document: true }), /10 MB/);
});
