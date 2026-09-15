// G3 — k6 leaderboard read-load test.
//
//   Heavy leaderboard reads (the page every spectator hits during a live
//   match, score submission and tournament completion). Measures p95/p99 and
//   error rate under sustained read concurrency.
//
//   k6 run tests/load/k6-leaderboard.js -e BASE_URL=... -e TEST_TOURNAMENT_ID=slug
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
const TOURNAMENT_ID = __ENV.TEST_TOURNAMENT_ID || '';

const errorRate = new Rate('errors');
const latency = new Trend('latency');

export const options = {
  scenarios: {
    leaderboard: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '15s', target: 30 },
        { duration: '45s', target: 30 },
        { duration: '15s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

export default function () {
  let res = http.get(`${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}/leaderboard`, { timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'leaderboard 2xx': (r) => r.status >= 200 && r.status < 400 });

  res = http.get(`${BASE_URL}/api/v1/leaderboards/${TOURNAMENT_ID}`, { timeout: '10s' });
  errorRate.add(res.status >= 400);
  latency.add(res.timings.duration);
  check(res, { 'leaderboards index 2xx': (r) => r.status >= 200 && r.status < 400 });

  sleep(0.3);
}
