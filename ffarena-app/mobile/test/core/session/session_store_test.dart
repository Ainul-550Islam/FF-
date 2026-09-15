import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/session/auth_session.dart';
import 'package:ffarena_mobile/core/session/session_store.dart';
import 'package:ffarena_mobile/core/storage/secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('save/load round-trips a session', () async {
    final storage = InMemorySecureStorage();
    final store = SessionStore(storage: storage);

    await store.save(UserSession(
      token: 'tok-9',
      tokenExpiresAt: '2030-01-01T00:00:00Z',
      user: Me.fromJson(const {'id': 3, 'username': 'rahat'}),
    ));

    final loaded = await store.load();

    expect(loaded, isNotNull);
    expect(loaded!.token, 'tok-9');
    expect(loaded.user.username, 'rahat');
    expect(loaded.tokenExpiresAt, '2030-01-01T00:00:00Z');
  });

  test('load returns null when nothing is stored', () async {
    final store = SessionStore(storage: InMemorySecureStorage());
    expect(await store.load(), isNull);
  });

  test('clear removes both keys', () async {
    final storage = InMemorySecureStorage();
    final store = SessionStore(storage: storage);
    await store.save(UserSession(
      token: 'tok-1',
      user: Me.fromJson(const {'id': 3}),
    ));

    await store.clear();

    expect(await storage.read(SecureKeys.accessToken), isNull);
    expect(await store.load(), isNull);
  });
}
