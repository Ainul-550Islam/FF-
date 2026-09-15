import 'push_message.dart';

/// Push-notification provider abstraction (Phase 18 §53 / Phase 19 §3).
///
/// The mobile app NEVER ships production credentials and NEVER fakes
/// delivery. A provider reports whether it is actually configured; when it
/// is not, push is disabled honestly and every method degrades to a safe
/// no-op equivalent.
abstract class PushProvider {
  /// True only when this provider has a real, configured transport.
  bool get isConfigured;

  /// OS platform reported to `POST /api/v1/me/devices` (`android` / `ios`).
  String get platform;

  /// Provider reported to `POST /api/v1/me/devices` (`fcm` / `apns` / `none`).
  String get providerName;

  /// One-time setup (e.g. Firebase.initializeApp). Safe to call when
  /// unconfigured — it simply does nothing.
  Future<void> initialize();

  /// Requests the OS push token for this installation, or returns null when
  /// unavailable / permission denied. Never fabricates a token.
  Future<String?> requestToken();

  /// Requests the user's OS-level push permission (returns true if granted).
  Future<bool> requestPermission();

  /// Token rotation events (e.g. FCM `onTokenRefresh`). Empty when the
  /// provider is unconfigured.
  Stream<String?> get onTokenRefresh;

  /// Foreground message stream (app open). Empty when unconfigured.
  Stream<PushMessage> get onMessage;

  /// Background/terminated tap stream. Empty when unconfigured.
  Stream<PushMessage> get onMessageOpenedApp;

  /// The message that launched the app, if any.
  Future<PushMessage?> getInitialMessage();
}
