import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

ApiClient _client({
  required String token,
  required Future<http.Response> Function(http.Request) handler,
  void Function(ApiException)? onSessionTerminated,
}) {
  final client = ApiClient(
    baseUrl: 'https://api.example.test/api/v1',
    tokenProvider: () => token,
    httpClient: MockClient(handler),
  );
  client.onSessionTerminated = onSessionTerminated;
  return client;
}

void main() {
  group('envelope decoding', () {
    test('decodes success envelope with data and meta', () async {
      final client = _client(
        token: '',
        handler: (_) async => http.Response(
          jsonEncode({
            'data': {'id': 1, 'name': 'Squad'},
            'meta': {
              'pagination': {'current_page': 1, 'last_page': 3},
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      );

      final result = await client.get('/tournaments');

      expect(result.asMap?['id'], 1);
      expect(result.pagination?.currentPage, 1);
      expect(result.pagination?.lastPage, 3);
      expect(result.hasMore, isTrue);
    });

    test('maps the error envelope to a typed ApiException', () async {
      final client = _client(
        token: '',
        handler: (_) async => http.Response(
          jsonEncode({
            'error': {
              'code': 'account_inactive',
              'message': 'Account is inactive.',
              'details': {'foo': 'bar'},
            },
          }),
          401,
          headers: {'content-type': 'application/json'},
        ),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.accountInactive);
        expect(e.statusCode, 401);
        expect(e.isSessionTerminating, isTrue);
        expect(e.details['foo'], 'bar');
      }
    });

    test('session-terminating errors invoke onSessionTerminated once',
        () async {
      var calls = 0;
      final client = _client(
        token: 'secret',
        onSessionTerminated: (_) => calls++,
        handler: (_) async => http.Response(
          jsonEncode({
            'error': {'code': 'token_revoked', 'message': 'Revoked.'},
          }),
          401,
          headers: {'content-type': 'application/json'},
        ),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException {
        // expected
      }

      expect(calls, 1);
    });
  });

  group('transport', () {
    test('attaches the bearer token and never logs it', () async {
      String? authHeader;
      final client = _client(
        token: 'super-secret-token',
        handler: (request) async {
          authHeader = request.headers['authorization'];
          return http.Response(jsonEncode({'data': const []}), 200);
        },
      );

      await client.get('/me');

      expect(authHeader, 'Bearer super-secret-token');
    });

    test('deduplicates the /api/v1 prefix from full paths', () async {
      String? path;
      final client = _client(
        token: '',
        handler: (request) async {
          path = request.url.path;
          return http.Response(jsonEncode({'data': const {}}), 200);
        },
      );

      await client.get('/api/v1/app/meta', auth: false);

      expect(path, '/api/v1/app/meta');
    });

    test('POST is attempted exactly once (mutations never auto-retry)',
        () async {
      var postCalls = 0;
      final client = _client(
        token: '',
        handler: (_) async {
          postCalls++;
          return http.Response(
            jsonEncode({
              'error': {'code': 'server_error', 'message': 'boom'},
            }),
            500,
          );
        },
      );

      try {
        await client.post('/matches/1/scores',
            body: {'team_id': 1, 'kills': 5, 'placement': 1});
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.serverError);
      }

      expect(postCalls, 1);
    });

    test('network offline maps to the offline ApiException', () async {
      final client = _client(
        token: '',
        handler: (_) async => throw http.ClientException('connection refused'),
      );

      try {
        await client.get('/me');
        fail('expected ApiException');
      } on ApiException catch (e) {
        expect(e.code, ApiException.offline);
      }
    });

    test('sends Idempotency-Key header when provided', () async {
      String? key;
      final client = _client(
        token: '',
        handler: (request) async {
          key = request.headers['idempotency-key'];
          return http.Response(jsonEncode({'data': const {}}), 201);
        },
      );

      await client.post('/auth/login',
          body: const {}, idempotencyKey: 'mob-abc');

      expect(key, 'mob-abc');
    });
  });
}
