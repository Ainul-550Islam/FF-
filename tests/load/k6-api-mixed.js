// G3 — k6 realistic mixed user-journey test (weighted traffic).
//
//   Simulates a realistic API journey with weighted endpoints rather than a
//   uniform hammer: discovery is hottest, detail/leaderboard next, then
//   profile and notifications. Authentication is optional (some users are
//   anonymous).
//
//   k6 run tests/load/k6-api-mixed.js -e BASE_URL=... -e API_TOKEN=... -e TEST_TOURNAMENT_ID=slug
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
const TOURNAMENT_ID = __ENV.TEST_TOURNAMENT_ID || '';

const errorRate = new Rate('errors');
const latency = new Trend('latency');

export const options = {
  scenarios: {
    mixed: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '20s', target: 25 },
        { duration: '60s', target: 25 },
        { duration: '20s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

export default function () {
  const headers = API_TOKEN ? { Authorization: `Bearer ${API_TOKEN}` } : {};
  const roll = Math.random();

  // Weighted journey (weights are deliberately uneven, like real traffic).
  if (roll < 0.30) {
    // 30% — tournament discovery
    const res = http.get(`${BASE_URL}/api/v1/tournaments`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'list 2xx': (r) => r.status >= 200 && r.status < 400 });
  } else if (roll < 0.55 && TOURNAMENT_ID !== '') {
    // 25% — tournament detail
    const res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'detail 2xx': (r) => r.status >= 200 && r.status < 400 });
  } else if (roll < 0.75 && TOURNAMENT_ID !== '') {
    // 20% — leaderboard
    const res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}/leaderboard`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'leaderboard 2xx': (r) => r.status >= 200 && r.status < 400 });
  } else if (roll < 0.90 && API_TOKEN !== '') {
    // 15% — profile
    const res = http.get(`${BASE_URL}/api/v1/me`, { headers, timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'me 2xx': (r) => r.status >= 200 && r.status < 400 });
  } else if (API_TOKEN !== '') {
    // 10% — notifications
    const res = http.get(`${BASE_URL}/api/v1/me/notifications`, { headers, timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'notifications 2xx': (r) => r.status >= 200 && r.status < 400 });
  } else {
    // anonymous fallback — health
    const res = http.get(`${BASE_URL}/health`, { timeout: '10s' });
    errorRate.add(res.status >= 400);
    latency.add(res.timings.duration);
    check(res, { 'health 2xx': (r) => r.status >= 200 && r.status < 400 });
  }

  sleep(Math.random() * 1.5);
}
