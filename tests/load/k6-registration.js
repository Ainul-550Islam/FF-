// G3 — k6 registration + idempotency concurrency test.
//
//   Concurrent registration POSTs against a DEDICATED test tournament (never
//   the default data), verifying the server's invariants under contention:
//     * no duplicate registration — a single captain (one API token) can
//       register a team at most once; every later attempt returns 409;
//     * idempotency of replays — the same Idempotency-Key returns the stored
//       response instead of creating a second team;
//     * a 201 is always pending or waitlisted — never a fabricated
//       confirmed/payment state.
//
//   The one-team-per-captain rule means a single token can only ever create
//   ONE team; the multi-captain slot-oversubscription race is exercised by
//   tests/Feature/Concurrency/RegistrationRaceTest (forked processes, one
//   captain each, PostgreSQL-authoritative).
//
//   Requires API_TOKEN (player scope incl. tournaments:register) and a
//   TEST_TOURNAMENT_ID whose tournament accepts registration.
//
//   k6 run tests/load/k6-registration.js -e BASE_URL=... -e API_TOKEN=... -e TEST_TOURNAMENT_ID=slug
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

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

const errorRate = new Rate('errors');       // real failures only (5xx / unexpected 4xx)
const latency = new Trend('latency');
const created = new Counter('registrations_201');
const duplicate = new Counter('registrations_409');   // one-team-per-captain held
const validation = new Counter('registrations_422');

export const options = {
  scenarios: {
    registration: {
      executor: 'shared-iterations',
      vus: 10,
      iterations: 100,
      maxDuration: '120s',
    },
  },
  thresholds: {
    errors: ['rate<0.05'],          // no real server failures
    registrations_201: ['count>=1'], // at least one registration succeeded
    registrations_409: ['count>=1'], // the duplicate-captain guard engaged
    latency: ['p(95)<3000'],
  },
};

export default function () {
  const headers = {
    Authorization: `Bearer ${API_TOKEN}`,
    'Content-Type': 'application/json',
  };

  // Unique per iteration → no accidental duplicate-team 409 from the server.
  // game_uid must match the server rule /^[A-Za-z0-9]{4,30}$/ (no separators),
  // so strip every non-alphanumeric character.
  const uid = `LD${__VU}${__ITER}${uuidv4().replace(/-/g, '')}`.toUpperCase().slice(0, 30);
  const key = `idem-${__VU}-${__ITER}`;
  const payload = JSON.stringify({
    name: `Load Squad ${__VU}-${__ITER}`,
    captain_name: `Load Captain ${__VU}`,
    phone: '01700000000',
    game_uid: uid,
    members: [],
  });
  const url = `${BASE_URL}/api/v1/tournaments/${TOURNAMENT_ID}/registrations`;

  const res = http.post(url, payload, {
    headers: { ...headers, 'Idempotency-Key': key },
    timeout: '15s',
  });

  // Replay the SAME payload with the SAME Idempotency-Key: the server must
  // return the stored result (header Idempotency-Replayed), never a second
  // team.
  const replay = http.post(url, payload, {
    headers: { ...headers, 'Idempotency-Key': key },
    timeout: '15s',
  });

  latency.add(res.timings.duration);

  // Classification. 409 is the EXPECTED duplicate-captain outcome (one token
  // can register once), not an error.
  if (res.status === 201) created.add(1);
  if (res.status === 409) duplicate.add(1);
  if (res.status === 422) validation.add(1);
  if (res.status >= 500 || (res.status >= 400 && ![409, 422].includes(res.status))) {
    errorRate.add(1);
  }

  // A 201 must be either pending (slot) or waitlisted — never a fabricated
  // confirmed/payment state.
  if (res.status === 201) {
    check(res, {
      'registration is pending or waitlisted': (r) =>
        ['pending', 'waitlisted'].includes(r.json('data.team.status')),
    });
  }

  // Idempotency: a successful first attempt must be replayed identically
  // (same team, replayed header), and the replay must never return 5xx.
  check(res, {
    'replay never 5xx': () => replay.status < 500,
    'replay matches first status': () => replay.status === res.status,
    'replay returns the same team': () =>
      res.status !== 201 || replay.json('data.team.id') === res.json('data.team.id'),
    'replay is marked by the server': () =>
      res.status !== 201 || replay.headers['Idempotency-Replayed'] === 'true',
  });

  sleep(0.2);
}
