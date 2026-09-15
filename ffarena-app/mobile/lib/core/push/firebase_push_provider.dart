import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../../config/app_config.dart';
import 'push_message.dart';
import 'push_provider.dart';

/// Firebase Cloud Messaging provider (Phase 19 §3/§4).
///
/// Production credential model (build-time only, never committed):
/// the Firebase project is described by `--dart-define` values that are
/// compiled into `FirebaseOptions` at build time. When those values are
/// absent (the default for a checkout), `isConfigured` is false and every
/// method degrades to a safe no-op — push is disabled honestly.
///
/// No Firebase service-account secret, `google-services.json` or
/// `GoogleService-Info.plist` is shipped in the repository.
class FirebasePushProvider implements PushProvider {
  FirebasePushProvider({FirebaseMessaging? messaging}) : _messaging = messaging;

  FirebaseMessaging? _messaging;
  bool _initialized = false;

  /// Builds Firebase options from build-time dart-defines. Returns null when
  /// any required identifier is absent — the provider is then unconfigured
  /// and push is disabled honestly (no secrets, no fake delivery).
  FirebaseOptions? get _options {
    final config = AppConfig.instance;
    if (config.firebaseApiKey.isEmpty ||
        config.firebaseAppId.isEmpty ||
        config.firebaseMessagingSenderId.isEmpty ||
        config.firebaseProjectId.isEmpty) {
      return null;
    }

    return FirebaseOptions(
      apiKey: config.firebaseApiKey,
      appId: config.firebaseAppId,
      messagingSenderId: config.firebaseMessagingSenderId,
      projectId: config.firebaseProjectId,
    );
  }

  @override
  bool get isConfigured => _options != null;

  @override
  String get platform =>
      defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  @override
  String get providerName => 'fcm';

  @override
  Future<void> initialize() async {
    if (!isConfigured || _initialized) {
      return;
    }

    try {
      await Firebase.initializeApp(options: _options);
      _messaging = FirebaseMessaging.instance;
      _initialized = true;
    } catch (_) {
      // Initialization failure keeps push disabled honestly — the app keeps
      // working with the in-app notification center only.
      _initialized = false;
    }
  }

  @override
  Future<String?> requestToken() async {
    final messaging = _messaging;
    if (messaging == null) {
      return null;
    }

    try {
      return await messaging.getToken();
    } catch (_) {
      return null;
    }
  }

  @override
  Future<bool> requestPermission() async {
    final messaging = _messaging;
    if (messaging == null) {
      return false;
    }

    try {
      final settings = await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );

      return settings.authorizationStatus == AuthorizationStatus.authorized ||
          settings.authorizationStatus == AuthorizationStatus.provisional;
    } catch (_) {
      return false;
    }
  }

  @override
  Stream<String?> get onTokenRefresh {
    final messaging = _messaging;
    if (messaging == null) {
      return const Stream<String?>.empty();
    }
    return messaging.onTokenRefresh;
  }

  @override
  Stream<PushMessage> get onMessage {
    if (_messaging == null) {
      return const Stream<PushMessage>.empty();
    }
    return FirebaseMessaging.onMessage.map(_convert);
  }

  @override
  Stream<PushMessage> get onMessageOpenedApp {
    if (_messaging == null) {
      return const Stream<PushMessage>.empty();
    }
    return FirebaseMessaging.onMessageOpenedApp.map(_convert);
  }

  @override
  Future<PushMessage?> getInitialMessage() async {
    final messaging = _messaging;
    if (messaging == null) {
      return null;
    }

    try {
      final initial = await messaging.getInitialMessage();
      return initial == null ? null : _convert(initial);
    } catch (_) {
      return null;
    }
  }

  PushMessage _convert(RemoteMessage message) => PushMessage(
        title: message.notification?.title ?? '',
        body: message.notification?.body ?? '',
        data: Map<String, dynamic>.from(message.data),
      );
}
