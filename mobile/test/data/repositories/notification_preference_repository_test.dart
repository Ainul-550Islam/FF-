import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/data/repositories/notification_preference_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetch parses the preference flags', () async {
    final repo = NotificationPreferenceRepository(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => 'tok',
        httpClient: MockClient((request) async {
          expect(request.method, 'GET');
          expect(request.url.path, '/api/v1/me/notification-preferences');
          return http.Response(
            jsonEncode({
              'data': {
                'tournament': true,
                'match': false,
                'team': true,
                'payment': true,
                'payout': true,
                'dispute': true,
                'security': true,
                'support': true,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final prefs = await repo.fetch();

    expect(prefs.tournament, true);
    expect(prefs.match, false);
    expect(prefs.security, true);
  });

  test('update PATCHes a partial flag set', () async {
    final repo = NotificationPreferenceRepository(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => 'tok',
        httpClient: MockClient((request) async {
          expect(request.method, 'PATCH');
          expect(request.url.path, '/api/v1/me/notification-preferences');
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body, {'payment': false});
          return http.Response(
            jsonEncode({
              'data': {
                'tournament': true,
                'match': true,
                'team': true,
                'payment': false,
                'payout': true,
                'dispute': true,
                'security': true,
                'support': true,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final prefs = await repo.update({'payment': false});

    expect(prefs.payment, false);
    expect(prefs.tournament, true);
  });
}
