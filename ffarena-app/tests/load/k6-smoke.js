// G3 — k6 smoke test (sanity load).
//
//   1–5 virtual users for a short window hitting the unauthenticated health
//   and discovery surfaces plus (optionally) the authenticated profile.
//   Validates correctness, latency and error rate, and — critically — refuses
//   to run against production unless explicitly unlocked twice.
//
//   k6 run tests/load/k6-smoke.js -e BASE_URL=http://127.0.0.1:8000
//   k6 run tests/load/k6-smoke.js -e BASE_URL=... -e API_TOKEN=... -e TEST_TOURNAMENT_ID=slug
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ---------------------------------------------------------------------------
// Production safety gate (shared policy across all G3 load scripts).
// A production run needs BOTH unlocks, so a single stray env var can never
// DDoS production.
// ---------------------------------------------------------------------------
const APP_ENV = __ENV.APP_ENV || 'testing';
if (APP_ENV === 'production' && __ENV.ALLOW_PRODUCTION_LOAD_TEST !== 'true') {
  throw new Error('Refusing to load-test production: set ALLOW_PRODUCTION_LOAD_TEST=true to acknowledge.');
}
if (APP_ENV === 'production' && __ENV.CONFIRM_PRODUCTION_LOAD_TEST !== 'I_UNDERSTAND') {
  throw new Error('Refusing to load-test production: set CONFIRM_PRODUCTION_LOAD_TEST=I_UNDERSTAND.');
}

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const API_TOKEN = __ENV.API_TOKEN || '';
const TOURNAMENT_ID = __ENV.TEST_TOURNAMENT_ID || '';

const errorRate = new Rate('errors');
const latency = new Trend('latency');

export const options = {
  scenarios: {
    smoke: {
      executor: 'shared-iterations',
      vus: 3,
      iterations: 30,
      maxDuration: '60s',
    },
  },
  thresholds: {
    errors: ['rate<0.05'],
    latency: ['p(95)<2000'],
  },
};

export default function () {
  const authHeaders = API_TOKEN ? { Authorization: `Bearer ${API_TOKEN}` } : {};

  let res = http.get(`${BASE_URL}/health`, { timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'health 200': (r) => r.status === 200 });

  res = http.get(`${BASE_URL}/api/v1/tournaments`, { timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'tournaments 200': (r) => r.status === 200 });

  if (TOURNAMENT_ID !== '') {
    res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'tournament detail 200': (r) => r.status === 200 });

    res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}/leaderboard`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'leaderboard 200': (r) => r.status === 200 });
  }

  if (API_TOKEN !== '') {
    res = http.get(`${BASE_URL}/api/v1/me`, { headers: authHeaders, timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'me 200': (r) => r.status === 200 });
  }

  sleep(1);
}
