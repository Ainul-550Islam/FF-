// G3 — k6 spike test (tournament opening).
//
//   Low traffic → sudden registration/detail demand → high concurrency →
//   recovery. Measures latency, HTTP 429/5xx counts and DB contention during
//   the ramp. Uses read surfaces plus the leaderboard (the highest-value page
//   during a tournament opening); it does NOT fabricate registrations — a
//   dedicated test tournament/data must be supplied via env when registration
//   writes are needed.
//
//   k6 run tests/load/k6-spike.js -e BASE_URL=... -e TEST_TOURNAMENT_ID=slug
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

const APP_ENV = __ENV.APP_ENV || 'testing';
if (APP_ENV === 'production' && __ENV.ALLOW_PRODUCTION_LOAD_TEST !== 'true') {
  throw new Error('Refusing to load-test production: set ALLOW_PRODUCTION_LOAD_TEST=true.');
}
if (APP_ENV === 'production' && __ENV.CONFIRM_PRODUCTION_LOAD_TEST !== 'I_UNDERSTAND') {
  throw new Error('Refusing to load-test production: set CONFIRM_PRODUCTION_LOAD_TEST=I_UNDERSTAND.');
}

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const TOURNAMENT_ID = __ENV.TEST_TOURNAMENT_ID || '';

const errorRate = new Rate('errors');
const latency = new Trend('latency');
const rateLimited = new Counter('http_429');
const serverErrors = new Counter('http_5xx');

export const options = {
  scenarios: {
    spike: {
      executor: 'ramping-vus',
      startVUs: 2,
      stages: [
        { duration: '15s', target: 2 },
        { duration: '10s', target: 120 },
        { duration: '30s', target: 120 },
        { duration: '20s', target: 2 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.10'],
    http_req_duration: ['p(95)<2500'],
  },
};

export default function () {
  let res = http.get(`${BASE_URL}/api/v1/tournaments`, { timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  if (res.status === 429) rateLimited.add(1);
  if (res.status >= 500) serverErrors.add(1);

  if (TOURNAMENT_ID !== '') {
    res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    if (res.status === 429) rateLimited.add(1);
    if (res.status >= 500) serverErrors.add(1);

    res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}/leaderboard`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    if (res.status === 429) rateLimited.add(1);
    if (res.status >= 500) serverErrors.add(1);
  }

  check(true, { 'alive': () => true });
  sleep(1);
}
