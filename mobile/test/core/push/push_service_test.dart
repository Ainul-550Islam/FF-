import 'dart:async';
import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/push/noop_push_provider.dart';
import 'package:ffarena_mobile/core/push/push_message.dart';
import 'package:ffarena_mobile/core/push/push_provider.dart';
import 'package:ffarena_mobile/core/push/push_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class FakePushProvider implements PushProvider {
  FakePushProvider({this.token, this.configured = true});

  final String? token;
  final bool configured;

  bool initialized = false;
  final StreamController<String?> _tokenRefresh =
      StreamController<String?>.broadcast();
  final StreamController<PushMessage> _message =
      StreamController<PushMessage>.broadcast();
  final StreamController<PushMessage> _opened =
      StreamController<PushMessage>.broadcast();

  @override
  bool get isConfigured => configured;

  @override
  String get platform => 'android';

  @override
  String get providerName => 'fcm';

  @override
  Future<void> initialize() async {
    initialized = true;
  }

  @override
  Future<String?> requestToken() async => token;

  @override
  Future<bool> requestPermission() async => true;

  @override
  Stream<String?> get onTokenRefresh => _tokenRefresh.stream;

  @override
  Stream<PushMessage> get onMessage => _message.stream;

  @override
  Stream<PushMessage> get onMessageOpenedApp => _opened.stream;

  @override
  Future<PushMessage?> getInitialMessage() async => null;

  void dispose() {
    _tokenRefresh.close();
    _message.close();
    _opened.close();
  }
}

void main() {
  test('sync registers the token with release metadata', () async {
    final requests = <http.Request>[];
    final provider = FakePushProvider(token: 'fcm-token-abc');
    addTearDown(provider.dispose);

    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        requests.add(request);
        return http.Response(
            jsonEncode({
              'data': {'id': 5}
            }),
            201,
            headers: {'content-type': 'application/json'});
      }),
    );

    final push = PushService(api: api, provider: provider);
    await push.sync();

    expect(requests, hasLength(1));
    final body = jsonDecode(requests.single.body) as Map<String, dynamic>;
    expect(body['platform'], 'android');
    expect(body['provider'], 'fcm');
    expect(body['token'], 'fcm-token-abc');
    expect(body.containsKey('app_version'), isTrue);
    expect(body.containsKey('environment'), isTrue);
  });

  test('sync is a no-op when the provider is unconfigured', () async {
    var called = false;
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => null,
      httpClient: MockClient((request) async {
        called = true;
        return http.Response('{}', 200);
      }),
    );

    final push = PushService(api: api, provider: const NoopPushProvider());
    await push.initialize();
    await push.sync();

    expect(called, isFalse);
    expect(push.isConfigured, isFalse);
  });

  test('token rotation re-registers the device', () async {
    var posts = 0;
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        if (request.method == 'POST') {
          posts++;
        }
        return http.Response(
            jsonEncode({
              'data': {'id': 5}
            }),
            201,
            headers: {'content-type': 'application/json'});
      }),
    );

    final provider = FakePushProvider(token: 'first-token');
    addTearDown(provider.dispose);
    final push = PushService(api: api, provider: provider);
    await push.initialize();
    await push.sync();
    expect(posts, 1);

    provider._tokenRefresh.add('rotated-token');
    await Future<void>.delayed(Duration.zero);

    expect(posts, 2);
  });

  test('foreground messages are deduplicated by notification id', () async {
    final push = PushService(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => null,
      ),
      provider: const NoopPushProvider(),
    );

    final received = <PushMessage>[];
    final sub = push.foregroundMessages.listen(received.add);

    // Simulate the two payloads the provider would surface.
    push.handleForeground(const PushMessage(
        title: 'a', body: 'b', data: {'notification_id': '1'}));
    push.handleForeground(const PushMessage(
        title: 'a', body: 'b', data: {'notification_id': '1'}));

    await Future<void>.delayed(Duration.zero);
    await sub.cancel();

    expect(received, hasLength(1));
  });

  test('background tap routes the deep link', () async {
    final push = PushService(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => null,
      ),
      provider: const NoopPushProvider(),
    );

    final opened = <String>[];
    push.onDeepLink = opened.add;

    push.handleOpened(const PushMessage(
      title: 'x',
      body: 'y',
      data: {'notification_id': '1', 'deep_link': 'ffarena://match/9'},
    ));

    expect(opened, ['ffarena://match/9']);
  });

  test('unregisterOnLogout deletes only the registered device', () async {
    final deletes = <String>[];
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        if (request.method == 'DELETE') {
          deletes.add(request.url.path);
          return http.Response('', 204);
        }
        return http.Response(
            jsonEncode({
              'data': {'id': 7}
            }),
            201,
            headers: {'content-type': 'application/json'});
      }),
    );

    final provider = FakePushProvider(token: 'token');
    addTearDown(provider.dispose);

    final push = PushService(api: api, provider: provider);
    await push.sync();
    await push.unregisterOnLogout();

    expect(deletes, ['/api/v1/me/devices/7']);
  });
}
