import 'push_message.dart';
import 'push_provider.dart';

/// The honest "push is not configured" provider (Phase 18 §53 / Phase 19).
///
/// When no push transport is configured — which is the default for this
/// foundation — the app must NOT pretend to send or receive notifications.
/// Every method degrades to a safe no-op; the UI shows push as unavailable.
class NoopPushProvider implements PushProvider {
  const NoopPushProvider();

  @override
  bool get isConfigured => false;

  @override
  String get platform => 'android';

  @override
  String get providerName => 'none';

  @override
  Future<void> initialize() async {}

  @override
  Future<String?> requestToken() async => null;

  @override
  Future<bool> requestPermission() async => false;

  @override
  Stream<String?> get onTokenRefresh => const Stream<String?>.empty();

  @override
  Stream<PushMessage> get onMessage => const Stream<PushMessage>.empty();

  @override
  Stream<PushMessage> get onMessageOpenedApp =>
      const Stream<PushMessage>.empty();

  @override
  Future<PushMessage?> getInitialMessage() async => null;
}
