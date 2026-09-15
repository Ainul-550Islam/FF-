import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/data/repositories/app_meta_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetch parses and caches the server metadata anonymously', () async {
    final repo = AppMetaRepository(
      api: ApiClient(
        baseUrl: 'https://api.example.test/api/v1',
        tokenProvider: () => null,
        httpClient: MockClient((request) async {
          expect(request.url.path, '/api/v1/app/meta');
          expect(request.headers['authorization'], isNull);
          return http.Response(
            jsonEncode({
              'data': {
                'app': {
                  'name': 'FF Arena',
                  'min_supported_app_version': '1.2.0',
                  'latest_app_version': '1.4.1',
                  'deep_link_scheme': 'ffarena',
                },
                'push': {'fcm_enabled': false, 'apns_enabled': false},
                'urls': {
                  'support': 'https://example.test/support',
                  'privacy': 'https://example.test/privacy',
                  'terms': 'https://example.test/terms',
                },
                'platform': {
                  'currency': 'BDT',
                  'timezone': 'Asia/Dhaka',
                  'locale': 'bn_BD',
                },
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final meta = await repo.fetch();

    expect(meta.app?.minSupportedAppVersion, '1.2.0');
    expect(meta.app?.deepLinkScheme, 'ffarena');
    expect(meta.push?.fcmEnabled, false);
    expect(meta.platform?.currency, 'BDT');
    expect(repo.cached, isNotNull);
  });
}
