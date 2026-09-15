import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// App identity and build-time environment configuration (Phase 18 §45,
/// extended Phase 19 §24/§25).
///
/// Every environment-sensitive value is injected at build time via
/// `--dart-define` and defaults to a safe placeholder so a checkout never
/// runs against a hardcoded production environment. Nothing here holds a
/// secret — real credentials are injected via dart-define / platform config
/// and never committed.
///
///   flutter run \
///     --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
///     --dart-define=FFARENA_ENV=staging \
///     --dart-define=FFARENA_GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com \
///     --dart-define=FFARENA_FIREBASE_API_KEY=... \
///     --dart-define=FFARENA_FIREBASE_APP_ID=... \
///     --dart-define=FFARENA_FIREBASE_MESSAGING_SENDER_ID=... \
///     --dart-define=FFARENA_FIREBASE_PROJECT_ID=...
class AppConfig {
  const AppConfig({
    required this.appName,
    required this.version,
    required this.buildNumber,
    required this.env,
    required this.apiBaseUrl,
    required this.deepLinkScheme,
    required this.googleSignInClientId,
    required this.pushEnabled,
    required this.crashReportingEnabled,
    required this.firebaseApiKey,
    required this.firebaseAppId,
    required this.firebaseMessagingSenderId,
    required this.firebaseProjectId,
  });

  final String appName;
  final String version;
  final String buildNumber;
  final String env;
  final String apiBaseUrl;
  final String deepLinkScheme;
  final String googleSignInClientId;
  final bool pushEnabled;
  final bool crashReportingEnabled;

  /// Firebase web-style project identifiers (build-time only). Absent by
  /// default, which disables the FCM provider honestly.
  final String firebaseApiKey;
  final String firebaseAppId;
  final String firebaseMessagingSenderId;
  final String firebaseProjectId;

  static const String _defineApiBaseUrl =
      String.fromEnvironment('FFARENA_API_BASE_URL');
  static const String _defineEnv = String.fromEnvironment('FFARENA_ENV');
  static const String _defineGoogleClientId =
      String.fromEnvironment('FFARENA_GOOGLE_CLIENT_ID');
  static const bool _definePushEnabled =
      bool.fromEnvironment('FFARENA_PUSH_ENABLED');
  static const bool _defineCrashEnabled =
      bool.fromEnvironment('FFARENA_CRASH_REPORTING_ENABLED');
  static const String _defineFirebaseApiKey =
      String.fromEnvironment('FFARENA_FIREBASE_API_KEY');
  static const String _defineFirebaseAppId =
      String.fromEnvironment('FFARENA_FIREBASE_APP_ID');
  static const String _defineFirebaseMessagingSenderId =
      String.fromEnvironment('FFARENA_FIREBASE_MESSAGING_SENDER_ID');
  static const String _defineFirebaseProjectId =
      String.fromEnvironment('FFARENA_FIREBASE_PROJECT_ID');

  static AppConfig? _instance;

  static AppConfig get instance => _instance ??= _resolve();

  static AppConfig _resolve() {
    final env = _defineEnv.isEmpty ? 'development' : _defineEnv;
    return AppConfig(
      appName: 'FF Arena',
      version: '1.0.0',
      buildNumber: '1',
      env: env,
      apiBaseUrl: _defineApiBaseUrl.isEmpty
          ? 'http://localhost/api/v1'
          : _defineApiBaseUrl,
      deepLinkScheme: 'ffarena',
      googleSignInClientId: _defineGoogleClientId,
      pushEnabled: _definePushEnabled,
      crashReportingEnabled: _defineCrashEnabled,
      firebaseApiKey: _defineFirebaseApiKey,
      firebaseAppId: _defineFirebaseAppId,
      firebaseMessagingSenderId: _defineFirebaseMessagingSenderId,
      firebaseProjectId: _defineFirebaseProjectId,
    );
  }

  bool get isProduction => env == 'production';

  bool get isGoogleSignInConfigured => googleSignInClientId.isNotEmpty;

  /// Release builds must never silently point at a localhost API.
  bool get isApiBaseUrlSane => apiBaseUrl.startsWith('https://');

  /// The on-device directory for the offline read-only cache.
  static Future<Directory> cacheDirectory() async {
    final base = await getApplicationDocumentsDirectory();
    return base;
  }
}
