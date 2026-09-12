import assert from 'node:assert/strict';
import { createWooRequestAuth } from './woo-oauth.js';

const http = createWooRequestAuth(
  'GET',
  'http://127.0.0.1:8080',
  'customers?email=buyer%40example.test&per_page=1',
  'ck_test',
  'cs_secret',
  { nonce: 'abc123', timestamp: 1700000000 },
);
const parsed = new URL(http.url);
assert.equal(http.httpCredentials, undefined);
assert.equal(parsed.searchParams.get('oauth_consumer_key'), 'ck_test');
assert.equal(parsed.searchParams.get('oauth_nonce'), 'abc123');
assert.equal(parsed.searchParams.get('oauth_timestamp'), '1700000000');
assert.equal(parsed.searchParams.get('oauth_signature_method'), 'HMAC-SHA256');
assert.ok(parsed.searchParams.get('oauth_signature'));

const repeat = createWooRequestAuth(
  'GET',
  'http://127.0.0.1:8080',
  'customers?email=buyer%40example.test&per_page=1',
  'ck_test',
  'cs_secret',
  { nonce: 'abc123', timestamp: 1700000000 },
);
assert.equal(new URL(repeat.url).searchParams.get('oauth_signature'), parsed.searchParams.get('oauth_signature'));

const https = createWooRequestAuth('POST', 'https://example.test', 'orders', 'ck_live', 'cs_live');
assert.deepEqual(https.httpCredentials, { username: 'ck_live', password: 'cs_live' });
assert.equal(new URL(https.url).searchParams.has('oauth_signature'), false);
console.log('WOO OAUTH SMOKE PASS');
