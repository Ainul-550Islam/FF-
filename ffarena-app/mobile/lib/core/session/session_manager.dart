import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../api/generated/openapi_models.dart';
import '../push/push_service.dart';
import 'auth_session.dart';
import 'session_store.dart';

/// Reasons a session ended (Phase 18 §9/§60). Each maps to a distinct
/// security/account screen message.
enum SessionEndReason {
  manualLogout,
  tokenExpired,
  tokenRevoked,
  accountInactive,
  unauthenticated;

  bool get isSecurityEvent => this != SessionEndReason.manualLogout;

  static SessionEndReason fromError(ApiException error) {
    switch (error.code) {
      case ApiException.accountInactive:
        return SessionEndReason.accountInactive;
      case ApiException.tokenExpired:
        return SessionEndReason.tokenExpired;
      case ApiException.tokenRevoked:
        return SessionEndReason.tokenRevoked;
      default:
        return SessionEndReason.unauthenticated;
    }
  }
}

/// Single owner of the authenticated session (Phase 18 §9).
///
/// The session manager is authoritative for the CLIENT-side lifecycle only —
/// the server stays authoritative for everything else. It:
///   * restores the persisted session at startup,
///   * validates the token against `GET /me`,
///   * force-clears the session on `token_expired` / `token_revoked` /
///     `account_inactive` / `unauthenticated`,
///   * routes the UI to the security/account screen for security events.
class SessionManager extends ChangeNotifier {
  SessionManager({
    required ApiClient api,
    required SessionStore store,
    PushService? pushService,
  })  : _api = api,
        _store = store,
        _push = pushService {
    // The API client reports session-terminating errors back to us so a 401
    // from ANY request force-clears auth state in one place.
    _api.onSessionTerminated = _handleTermination;
  }

  final ApiClient _api;
  final SessionStore _store;
  final PushService? _push;

  UserSession? _session;
  bool _restoring = true;
  SessionEndReason? _endReason;
  String? _securityContext;

  UserSession? get session => _session;
  Me? get me => _session?.user;
  String? get token => _session?.token;
  bool get isAuthenticated => _session != null;
  bool get restoring => _restoring;
  SessionEndReason? get endReason => _endReason;
  String? get securityContext => _securityContext;

  /// Restores a persisted session and validates it against the server.
  Future<void> restore() async {
    _restoring = true;
    final stored = await _store.load();
    if (stored == null) {
      _restoring = false;
      notifyListeners();
      return;
    }

    // Client-side expiry check first (fast path); the server still gets the
    // final say via the 401 mapping.
    if (stored.isExpired) {
      await _end(SessionEndReason.tokenExpired);
      _restoring = false;
      notifyListeners();
      return;
    }

    _session = stored;
    _restoring = false;
    notifyListeners();

    try {
      final envelope = await _api.get('/me');
      final freshUser = Me.fromJson(envelope.asMap ?? const {});
      _session = UserSession(
        token: stored.token,
        user: freshUser,
        tokenExpiresAt: stored.tokenExpiresAt,
        refreshToken: stored.refreshToken,
      );
      await _store.save(_session!);
      notifyListeners();
    } on ApiException catch (e) {
      // The ApiClient already routed session-terminating codes to
      // _handleTermination; anything else is transient (offline etc.) and we
      // keep the cached session.
      if (e.isSessionTerminating) {
        await _end(SessionEndReason.fromError(e));
      }
    } catch (_) {
      // Offline at startup: keep the restored session.
    }
  }

  /// Completes a login with a freshly issued token from a documented auth
  /// endpoint. The server is authoritative; we just store what it issued.
  Future<void> establish({
    required String token,
    required Me user,
    String? tokenExpiresAt,
    String? refreshToken,
  }) async {
    final session = UserSession(
      token: token,
      user: user,
      tokenExpiresAt: tokenExpiresAt,
      refreshToken: refreshToken,
    );
    await _store.save(session);
    _session = session;
    _endReason = null;
    _securityContext = null;
    notifyListeners();
    // Push device token is registered on the next sync (post-login).
    await _push?.sync();
  }

  /// Local logout. Clears secure storage and (best-effort) unregisters the
  /// push device token.
  Future<void> logout() async {
    await _push?.unregisterOnLogout();
    await _store.clear();
    _session = null;
    _endReason = SessionEndReason.manualLogout;
    notifyListeners();
  }

  /// Force-clears auth state after a security event, routing the UI to the
  /// security/account screen.
  Future<void> _handleTermination(ApiException error) async {
    if (_session == null) {
      return;
    }
    await _end(SessionEndReason.fromError(error));
  }

  Future<void> _end(SessionEndReason reason) async {
    // Clear in-memory state synchronously so the UI (and tests) observe the
    // ended session immediately; storage cleanup happens right after.
    _session = null;
    _endReason = reason;
    _securityContext = reason == SessionEndReason.accountInactive
        ? 'account_inactive'
        : reason == SessionEndReason.tokenRevoked
            ? 'token_revoked'
            : 'session_expired';
    notifyListeners();
    await _store.clear();
  }

  /// Replaces the cached user (e.g. after a profile update) and persists it.
  Future<void> refreshUser(Me user) async {
    final current = _session;
    if (current == null) {
      return;
    }
    _session = UserSession(
      token: current.token,
      user: user,
      tokenExpiresAt: current.tokenExpiresAt,
      refreshToken: current.refreshToken,
    );
    await _store.save(_session!);
    notifyListeners();
  }

  /// Clears the end-reason flag (used after the user acknowledges the
  /// security screen and returns to the login flow).
  void acknowledgeSecurityEvent() {
    _endReason = null;
    _securityContext = null;
    notifyListeners();
  }
}
