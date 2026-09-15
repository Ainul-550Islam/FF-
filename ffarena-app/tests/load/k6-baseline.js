// G3 — k6 baseline test (normal expected traffic).
//
//   Simulates realistic read-heavy traffic: tournament discovery → detail →
//   leaderboard → profile → notifications → API reads. Measures p50/p90/p95/
//   p99, error rate and requests/sec. Refuses production unless double-unlocked.
//
//   k6 run tests/load/k6-baseline.js -e BASE_URL=http://127.0.0.1:8000 -e TEST_TOURNAMENT_ID=slug
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

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
    baseline: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '20s', target: 20 },
        { duration: '60s', target: 20 },
        { duration: '20s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<1500'],
  },
};

function read(path, headers, tags) {
  const res = http.get(`${BASE_URL}${path}`, { headers, timeout: '10s', tags });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { [`${path} 2xx`]: (r) => r.status >= 200 && r.status < 400 });
}

export default function () {
  const headers = API_TOKEN ? { Authorization: `Bearer ${API_TOKEN}` } : {};

  read('/api/v1/tournaments', {}, { group: 'discovery' });

  if (TOURNAMENT_ID !== '') {
    read(`/api/v1/tournaments/${TOURNAMENT_ID}`, {}, { group: 'detail' });
    read(`/api/v1/tournaments/${TOURNAMENT_ID}/leaderboard`, {}, { group: 'leaderboard' });
  }

  if (API_TOKEN !== '') {
    read('/api/v1/me', headers, { group: 'profile' });
    read('/api/v1/me/notifications', headers, { group: 'notifications' });
  }

  sleep(1);
}
