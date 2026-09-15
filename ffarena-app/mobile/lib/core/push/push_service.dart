import 'dart:async';

import '../../config/app_config.dart';
import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../cache/offline_cache.dart';
import 'noop_push_provider.dart';
import 'notification_dedup.dart';
import 'push_message.dart';
import 'push_provider.dart';

/// Coordinates push-token registration with the backend (Phase 18 §53/§54,
/// extended in Phase 19).
///
/// Flow (server-authoritative):
///   1. if the provider is not configured, push is disabled — nothing runs;
///   2. initialize the provider and subscribe to token-refresh and message
///      streams;
///   3. request the OS token and register it via `POST /api/v1/me/devices`
///      (the server stores only the encrypted token + sha256 hash and never
///      echoes it back);
///   4. on token rotation, re-register;
///   5. foreground messages are deduplicated by `notification_id` and
///      forwarded to the UI; taps are routed via [onDeepLink];
///   6. on logout, unregister the device by id (never all devices).
class PushService {
  PushService({
    required ApiClient api,
    required PushProvider provider,
    OfflineCache? cache,
  })  : _api = api,
        _provider = provider,
        _cache = cache ?? OfflineCache.instance;

  static const _deviceIdKey = 'push.device_id';

  final ApiClient _api;
  final PushProvider _provider;
  final OfflineCache _cache;
  final NotificationDedup _dedup = NotificationDedup();

  final StreamController<PushMessage> _foreground =
      StreamController<PushMessage>.broadcast();

  StreamSubscription<String?>? _tokenRefreshSub;
  StreamSubscription<PushMessage>? _messageSub;
  StreamSubscription<PushMessage>? _openedSub;

  int? _registeredDeviceId;

  /// Set by the app shell so background taps can navigate without a widget
  /// dependency (kept pure for unit testing).
  void Function(String deepLink)? onDeepLink;

  PushProvider get provider => _provider;
  bool get isConfigured => _provider.isConfigured;

  /// Foreground push messages (already deduplicated). The UI renders these
  /// as an in-app banner instead of relying on the OS tray.
  Stream<PushMessage> get foregroundMessages => _foreground.stream;

  /// Initializes the provider and subscribes to rotation/message streams.
  /// Safe to call when unconfigured (no-op).
  Future<void> initialize() async {
    await _provider.initialize();

    if (!_provider.isConfigured) {
      return;
    }

    _tokenRefreshSub ??= _provider.onTokenRefresh.listen((token) {
      if (token != null && token.isNotEmpty) {
        unawaited(_register(token));
      }
    });

    _messageSub ??= _provider.onMessage.listen(handleForeground);
    _openedSub ??= _provider.onMessageOpenedApp.listen(handleOpened);

    final initial = await _provider.getInitialMessage();
    if (initial != null) {
      handleOpened(initial);
    }
  }

  /// Handles a foreground message: deduplicates by notification id and
  /// forwards the first occurrence to the UI (Phase 19 §12/§40). Public so it
  /// can be unit tested without a live provider.
  void handleForeground(PushMessage message) {
    if (_dedup.isDuplicate(message)) {
      return;
    }
    _foreground.add(message);
  }

  /// Handles a background tap: routes the server-authored deep link (if any)
  /// through [onDeepLink]. Public so it can be unit tested.
  void handleOpened(PushMessage message) {
    final link = message.deepLink;
    if (link != null && link.isNotEmpty) {
      onDeepLink?.call(link);
    }
  }

  /// Registers (or refreshes) the device token with the current release
  /// metadata. No-op when unconfigured or when no token is available.
  Future<void> sync() async {
    if (!_provider.isConfigured) {
      return;
    }
    final token = await _provider.requestToken();
    if (token == null || token.isEmpty) {
      return;
    }
    await _register(token);
  }

  Future<void> _register(String token) async {
    try {
      final envelope = await _api.post(
        '/me/devices',
        body: {
          'platform': _provider.platform,
          'provider': _provider.providerName,
          'token': token,
          'app_version': AppConfig.instance.version,
          'environment': AppConfig.instance.env,
        },
      );
      final id = envelope.asMap?['id'];
      if (id is int) {
        _registeredDeviceId = id;
        await _cache.put(_deviceIdKey, {'id': id});
      }
    } on ApiException {
      // Registration is best-effort; a failure keeps push disabled until the
      // next successful sync.
    }
  }

  /// Requests the OS push permission. Returns false when the provider is
  /// unconfigured or the user denied.
  Future<bool> requestPermission() async {
    if (!_provider.isConfigured) {
      return false;
    }
    return _provider.requestPermission();
  }

  /// Best-effort unregister on logout. MUST run before the session token is
  /// cleared (the endpoint requires authentication). Never blocks logout and
  /// never deletes other devices.
  Future<void> unregisterOnLogout() async {
    final id = await _registeredDeviceIdOrCached();
    if (id == null) {
      return;
    }
    try {
      await _api.delete('/me/devices/$id');
    } on ApiException {
      // Best effort.
    }
    _registeredDeviceId = null;
    await _cache.remove(_deviceIdKey);
  }

  Future<int?> _registeredDeviceIdOrCached() async {
    if (_registeredDeviceId != null) {
      return _registeredDeviceId;
    }
    final cached = await _cache.get(_deviceIdKey);
    final id = cached?.payload['id'];
    return id is int ? id : null;
  }

  /// Cancels stream subscriptions (listener-leak prevention, Phase 19 §47).
  Future<void> dispose() async {
    await _tokenRefreshSub?.cancel();
    await _messageSub?.cancel();
    await _openedSub?.cancel();
    _tokenRefreshSub = null;
    _messageSub = null;
    _openedSub = null;
    await _foreground.close();
  }
}

/// Convenience for wiring a disabled push stack.
PushService noopPushService(ApiClient api) =>
    PushService(api: api, provider: const NoopPushProvider());
