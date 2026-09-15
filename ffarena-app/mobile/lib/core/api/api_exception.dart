import '../l10n/app_localizations.dart';

/// Typed API error (Phase 18 §31).
///
/// Maps the Phase 15 error envelope `{error:{code,message,details}}` into a
/// single exception the UI can switch on. Codes are server-authoritative;
/// the client only ADDS transport codes (`offline`, `timeout`) and a
/// defensive `unknown` for unparseable bodies.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.details = const {},
  });

  /// Builds from a decoded error envelope (or any decoded body).
  factory ApiException.fromBody(Map<String, dynamic>? body, {int? statusCode}) {
    final error = body?['error'];
    if (error is Map<String, dynamic>) {
      final details = error['details'];
      return ApiException(
        code: (error['code'] as String?) ?? unknown,
        message: (error['message'] as String?) ?? 'Request failed.',
        statusCode: statusCode,
        details: details is Map<String, dynamic> ? details : const {},
      );
    }
    return ApiException(
      code: unknown,
      message: 'Unexpected response.',
      statusCode: statusCode,
    );
  }

  /// Server error codes (Phase 15 — see app/Exceptions/Handler).
  static const unauthenticated = 'unauthenticated';
  static const accountInactive = 'account_inactive';
  static const tokenExpired = 'token_expired';
  static const tokenRevoked = 'token_revoked';
  static const forbidden = 'forbidden';
  static const notFound = 'not_found';
  static const conflict = 'conflict';
  static const validationError = 'validation_error';
  static const rateLimited = 'rate_limited';
  static const serverError = 'server_error';
  static const invalidCredentials = 'invalid_credentials';
  static const invalidCode = 'invalid_code';
  static const noAccount = 'no_account';
  static const notConfigured = 'not_configured';
  static const invalidIdToken = 'invalid_id_token';
  static const duplicateScore = 'duplicate_score';
  static const placementTaken = 'placement_taken';
  static const registrationClosed = 'registration_closed';
  static const teamNotConfirmed = 'team_not_confirmed';

  /// Client-side transport codes (never produced by the server).
  static const offline = 'network_offline';
  static const timeout = 'network_timeout';
  static const unknown = 'unknown';

  final String code;
  final String message;
  final int? statusCode;
  final Map<String, dynamic> details;

  /// True when the session must be cleared and the user routed to the
  /// security/account screen.
  bool get isSessionTerminating =>
      code == unauthenticated ||
      code == accountInactive ||
      code == tokenExpired ||
      code == tokenRevoked;

  /// A localized, end-user-safe message. Never echoes server internals.
  String localized(AppLocalizations l10n) {
    switch (code) {
      case offline:
        return l10n.errorOffline;
      case timeout:
        return l10n.errorTimeout;
      case rateLimited:
        return l10n.errorRateLimited;
      case invalidCredentials:
        return l10n.errorInvalidCredentials;
      case invalidCode:
        return l10n.errorInvalidCode;
      case noAccount:
        return l10n.errorNoAccount;
      case invalidIdToken:
        return l10n.errorGoogleSignIn;
      case accountInactive:
        return l10n.errorAccountInactive;
      case tokenExpired:
      case tokenRevoked:
      case unauthenticated:
        return l10n.errorSessionExpired;
      case serverError:
        return l10n.errorServer;
      default:
        return message.isNotEmpty ? message : l10n.errorGeneric;
    }
  }

  @override
  String toString() => 'ApiException($code, $statusCode): $message';
}
