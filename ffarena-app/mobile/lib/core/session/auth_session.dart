import '../api/generated/openapi_models.dart';

/// Immutable authenticated session (Phase 18 §9).
///
/// Holds the access token plus the documented `Me` resource. The token is
/// the ONLY secret in the session and is never serialized into logs or
/// analytics — `toJson`/`toString` deliberately exclude it.
class UserSession {
  const UserSession({
    required this.token,
    required this.user,
    this.tokenExpiresAt,
    this.refreshToken,
  });

  final String token;
  final Me user;
  final String? tokenExpiresAt;
  final String? refreshToken;

  /// True when the token is known to be expired (parse failure => false, the
  /// server remains authoritative and will reject with `token_expired`).
  bool get isExpired {
    final expiresAt = tokenExpiresAt;
    if (expiresAt == null) {
      return false;
    }
    final expiry = DateTime.tryParse(expiresAt);
    if (expiry == null) {
      return false;
    }
    return expiry.isBefore(DateTime.now());
  }

  Map<String, dynamic> toJson() => {
        // The token is intentionally NOT included here — this map is only
        // used for diagnostics and tests that must never see the raw token.
        'user': user.toJson(),
        'token_expires_at': tokenExpiresAt,
      };
}
