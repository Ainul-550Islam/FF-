import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/push/noop_push_provider.dart';
import 'package:ffarena_mobile/core/push/push_service.dart';
import 'package:ffarena_mobile/core/session/session_manager.dart';
import 'package:ffarena_mobile/core/session/session_store.dart';
import 'package:ffarena_mobile/core/storage/secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _me({String username = 'rahat'}) => http.Response(
      jsonEncode({
        'data': {'id': 7, 'username': username, 'name': 'Rahat'},
      }),
      200,
      headers: {'content-type': 'application/json'},
    );

http.Response _unauthorized(String code) => http.Response(
      jsonEncode({
        'error': {'code': code, 'message': 'nope'},
      }),
      401,
      headers: {'content-type': 'application/json'},
    );

ApiClient _api(Future<http.Response> Function(http.Request) handler) {
  final api = ApiClient(
    baseUrl: 'https://api.example.test/api/v1',
    tokenProvider: () => null,
    httpClient: MockClient(handler),
  );
  return api;
}

void main() {
  late InMemorySecureStorage storage;
  late SessionStore store;
  late ApiClient api;
  late SessionManager session;

  setUp(() {
    storage = InMemorySecureStorage();
    store = SessionStore(storage: storage);
    api = _api((_) async => _me());
    session = SessionManager(
      api: api,
      store: store,
      pushService: PushService(api: api, provider: const NoopPushProvider()),
    );
  });

  test('establish stores the token and exposes the user', () async {
    await session.establish(
      token: 'tok-123',
      user: Me.fromJson(const {'id': 7, 'username': 'rahat'}),
    );

    expect(session.isAuthenticated, isTrue);
    expect(session.me?.username, 'rahat');
    expect(session.token, 'tok-123');
    expect(await storage.read(SecureKeys.accessToken), 'tok-123');
  });

  test('restore with a valid token loads the user', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7, 'username': 'old'}),
    );
    // A fresh session manager shares the same store.
    final restored = SessionManager(
      api: api,
      store: store,
      pushService: PushService(api: api, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isTrue);
    expect(restored.me?.username, 'rahat'); // refreshed from GET /me
  });

  test('restore with a revoked token ends the session', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final revokingApi = _api((_) async => _unauthorized('token_revoked'));
    final restored = SessionManager(
      api: revokingApi,
      store: store,
      pushService:
          PushService(api: revokingApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isFalse);
    expect(restored.endReason, SessionEndReason.tokenRevoked);
    expect(await storage.read(SecureKeys.accessToken), isNull);
  });

  test('restore with a deactivated account routes to account-inactive',
      () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final deactApi = _api((_) async => _unauthorized('account_inactive'));
    final restored = SessionManager(
      api: deactApi,
      store: store,
      pushService:
          PushService(api: deactApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.endReason, SessionEndReason.accountInactive);
    expect(restored.isAuthenticated, isFalse);
  });

  test('restore offline keeps the cached session', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );
    final offlineApi = _api((_) async => throw http.ClientException('offline'));
    final restored = SessionManager(
      api: offlineApi,
      store: store,
      pushService:
          PushService(api: offlineApi, provider: const NoopPushProvider()),
    );

    await restored.restore();

    expect(restored.isAuthenticated, isTrue);
    expect(restored.endReason, isNull);
  });

  test('logout clears storage and is not a security event', () async {
    await session.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );

    await session.logout();

    expect(session.isAuthenticated, isFalse);
    expect(session.endReason, SessionEndReason.manualLogout);
    expect(session.endReason!.isSecurityEvent, isFalse);
    expect(await storage.read(SecureKeys.accessToken), isNull);
  });

  test('a session-terminating API response force-ends the session', () async {
    final terminatingApi = _api((_) async => _unauthorized('token_expired'));
    final manager = SessionManager(
      api: terminatingApi,
      store: store,
      pushService:
          PushService(api: terminatingApi, provider: const NoopPushProvider()),
    );
    await manager.establish(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 7}),
    );

    try {
      await terminatingApi.get('/me');
    } on ApiException {
      // expected
    }

    expect(manager.endReason, SessionEndReason.tokenExpired);
    expect(manager.isAuthenticated, isFalse);
  });
}
