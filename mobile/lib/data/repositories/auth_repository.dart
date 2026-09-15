import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Auth over the existing `/api/v1` account engine (Phase 18 §4).
///
/// The mobile client has NO second account database and NO invented refresh
/// lifecycle — every method here is a thin client over a documented endpoint.
class AuthRepository {
  AuthRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// POST /auth/login (email + password).
  Future<AuthSession> login(String email, String password) async {
    final envelope = await _api.post(
      '/auth/login',
      body: {'email': email, 'password': password},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/register (email + password signup).
  Future<AuthSession> register({
    required String name,
    required String username,
    required String email,
    required String password,
    String? phone,
    String? gameUid,
    required String role,
  }) async {
    final envelope = await _api.post(
      '/auth/register',
      body: {
        'name': name,
        'username': username,
        'email': email,
        'password': password,
        'password_confirmation': password,
        'role': role,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (gameUid != null && gameUid.isNotEmpty) 'game_uid': gameUid,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/otp/request.
  Future<void> requestOtp(String phone, {String purpose = 'login'}) async {
    await _api.post(
      '/auth/otp/request',
      body: {'phone': phone, 'purpose': purpose},
      idempotencyKey: Idempotency.generate(),
    );
  }

  /// POST /auth/otp/verify (phone login / signup-linking).
  Future<AuthSession> verifyOtp(
    String phone,
    String code, {
    String purpose = 'login',
  }) async {
    final envelope = await _api.post(
      '/auth/otp/verify',
      body: {'phone': phone, 'purpose': purpose, 'code': code},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }

  /// POST /auth/google (id_token -> platform session).
  Future<AuthSession> google(String idToken) async {
    final envelope = await _api.post(
      '/auth/google',
      body: {'id_token': idToken},
      idempotencyKey: Idempotency.generate(),
    );
    return AuthSession.fromJson(envelope.asMap ?? const {});
  }
}
