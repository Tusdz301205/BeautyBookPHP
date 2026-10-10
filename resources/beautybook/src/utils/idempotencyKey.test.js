import test from 'node:test';
import assert from 'node:assert/strict';
import { createIdempotencyKey } from './idempotencyKey.js';

const uuidV4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

test('uses native UUID when supported', () => {
  assert.equal(createIdempotencyKey({ randomUUID: () => 'native-uuid' }), 'native-uuid');
});

test('HTTP browser without randomUUID still generates unique RFC4122 v4 keys', () => {
  let counter = 0;
  const cryptoApi = {
    getRandomValues(bytes) {
      for (let i = 0; i < bytes.length; i += 1) bytes[i] = (i + counter) & 255;
      counter += 1;
      return bytes;
    },
  };
  const first = createIdempotencyKey(cryptoApi);
  const second = createIdempotencyKey(cryptoApi);
  assert.equal(first, '00010203-0405-4607-8809-0a0b0c0d0e0f');
  assert.match(second, uuidV4);
  assert.notEqual(first, second);
});

test('older environments without WebCrypto can still dispatch a deduplicated request', () => {
  assert.match(createIdempotencyKey(null), uuidV4);
});
