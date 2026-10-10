import test from 'node:test';
import assert from 'node:assert/strict';
import { schedulerSocketUrl } from './socketEndpoint.js';
test('calendar and list use the API origin when no dedicated socket origin is configured', () => {
  assert.equal(schedulerSocketUrl({ wsUrl: '', apiBase: 'http://127.0.0.1:3014/api/v1', browserOrigin: 'http://127.0.0.1:5176' }), 'http://127.0.0.1:3014');
  assert.equal(schedulerSocketUrl({ wsUrl: '', apiBase: '/api/v1', browserOrigin: 'https://app.example.test' }), 'https://app.example.test');
});
test('an explicitly separate realtime origin/namespace retains priority', () => {
  assert.equal(schedulerSocketUrl({ wsUrl: ' https://socket.example.test/scheduler ', apiBase: 'https://api.example.test/api/v1' }), 'https://socket.example.test/scheduler');
});
