// G3 — k6 payment load test (SAFE — read-only surfaces only).
//
//   Load-tests the payment READ surfaces under concurrency:
//     GET /api/v1/payments/methods   (provider status)
//     GET /api/v1/me/wallet          (wallet read)
//     GET /api/v1/me/wallet/ledger   (ledger pagination)
//
//   This script deliberately does NOT POST /payments: creating payment intents
//   under load is the job of scripts/perf/benchmark-payments.php (which never
//   contacts a real provider) and tests/Feature/Concurrency/PaymentRaceTest.
//   A payment is never marked successful here — no fake production success,
//   ever.
//
//   k6 run tests/load/k6-payment.js -e BASE_URL=... -e API_TOKEN=...
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const APP_ENV = __ENV.APP_ENV || 'testing';
if (APP_ENV === 'production' && __ENV.ALLOW_PRODUCTION_LOAD_TEST !== 'true') {
  throw new Error('Refusing to load-test production: set ALLOW_PRODUCTION_LOAD_TEST=true.');
}
if (APP_ENV === 'production' && __ENV.CONFIRM_PRODUCTION_LOAD_TEST !== 'I_UNDERSTAND') {
  throw new Error('Refusing to load-test production: set CONFIRM_PRODUCTION_LOAD_TEST=I_UNDERSTAND.');
}

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const API_TOKEN = __ENV.API_TOKEN || '';

const errorRate = new Rate('errors');
const latency = new Trend('latency');

export const options = {
  scenarios: {
    payments: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '10s', target: 15 },
        { duration: '30s', target: 15 },
        { duration: '10s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

export default function () {
  const headers = { Authorization: `Bearer ${API_TOKEN}` };

  let res = http.get(`${BASE_URL}/api/v1/payments/methods`, { headers, timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'methods 2xx': (r) => r.status >= 200 && r.status < 400 });

  res = http.get(`${BASE_URL}/api/v1/me/wallet`, { headers, timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'wallet 2xx': (r) => r.status >= 200 && r.status < 400 });

  res = http.get(`${BASE_URL}/api/v1/me/wallet/ledger?per_page=30`, { headers, timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'ledger 2xx': (r) => r.status >= 200 && r.status < 400 });

  sleep(0.5);
}
