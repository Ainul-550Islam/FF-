import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Account security / sessions (Phase 18 §9/§61).
class SecurityRepository {
  SecurityRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/security — sign-in methods and account status (never internal
  /// signals like phone numbers, fraud flags, device or IP data).
  Future<Map<String, dynamic>> security() async {
    final envelope = await _api.get('/me/security');
    return envelope.asMap ?? const {};
  }

  /// GET /me/sessions.
  Future<List<ApiSession>> sessions() async {
    final envelope = await _api.get('/me/sessions');
    return envelope.asList
        .map((m) => ApiSession.fromJson(m))
        .toList(growable: false);
  }

  /// DELETE /me/sessions/{id}.
  Future<void> revokeSession(String id) async {
    await _api.delete('/me/sessions/$id');
  }

  /// POST /me/sessions/revoke-all.
  Future<void> revokeAllSessions() async {
    await _api.post('/me/sessions/revoke-all');
  }

  /// POST /me/sessions/revoke-others.
  Future<void> revokeOtherSessions() async {
    await _api.post('/me/sessions/revoke-others');
  }
}
