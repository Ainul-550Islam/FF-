import 'dart:convert';

import '../api/generated/openapi_models.dart';
import '../storage/secure_storage.dart';
import 'auth_session.dart';

/// Persists the authenticated session into secure storage (Phase 18 §9/§52).
///
/// The token is stored under its own key so the API client can read it
/// without deserializing the whole envelope; the full session (token + user)
/// is stored as one JSON blob under a second key. Both are cleared on logout
/// or on any session-terminating server response.
class SessionStore {
  SessionStore({required SecureStorage storage}) : _storage = storage;

  final SecureStorage _storage;

  Future<void> save(UserSession session) async {
    await _storage.write(SecureKeys.accessToken, session.token);
    await _storage.write(
      SecureKeys.sessionJson,
      jsonEncode({
        'token': session.token,
        'token_expires_at': session.tokenExpiresAt,
        'refresh_token': session.refreshToken,
        'user': session.user.toJson(),
      }),
    );
  }

  Future<UserSession?> load() async {
    final raw = await _storage.read(SecureKeys.sessionJson);
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      final token = json['token'] as String?;
      final userJson = json['user'];
      if (token == null || userJson is! Map<String, dynamic>) {
        return null;
      }
      return UserSession(
        token: token,
        tokenExpiresAt: json['token_expires_at'] as String?,
        refreshToken: json['refresh_token'] as String?,
        user: Me.fromJson(userJson),
      );
    } catch (_) {
      // A corrupt blob is treated as "no session" — never a crash, and never
      // an attempt to salvage a half-written token.
      await clear();
      return null;
    }
  }

  Future<void> clear() async {
    await _storage.delete(SecureKeys.accessToken);
    await _storage.delete(SecureKeys.sessionJson);
  }
}
