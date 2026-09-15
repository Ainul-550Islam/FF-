import 'dart:convert';

import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/data/repositories/wallet_repository.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Payment-return hardening (Phase 19 §22): the client NEVER marks a payment
/// successful locally, never trusts the provider redirect, and polls
/// `GET /payments/{id}` until the SERVER reports a terminal status.
void main() {
  test('createPayment POSTs the intent with an Idempotency-Key', () async {
    late http.Request captured;
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        captured = request;
        return http.Response(
          jsonEncode({
            'data': {
              'payment': {
                'id': 10,
                'status': 'PENDING',
                'provider': 'bkash',
              },
              'redirect_url': 'https://pay.example.test/10',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final repo = WalletRepository(api: api);

    final intent = await repo.createPayment(teamId: 5, provider: 'bkash');

    expect(captured.method, 'POST');
    expect(captured.url.path, '/api/v1/payments');
    expect(jsonDecode(captured.body), {'team_id': 5, 'provider': 'bkash'});
    // Non-idempotent mutation guarded by an Idempotency-Key.
    expect(captured.headers['Idempotency-Key'], isNotEmpty);
    expect(intent.payment.id, 10);
    expect(intent.redirectUrl, 'https://pay.example.test/10');
  });

  test('awaitTerminal polls the server and stops at a terminal status',
      () async {
    var pollCount = 0;
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/payments/10');
        pollCount += 1;
        // PENDING twice, then the server confirms PAID — only the server
        // status (never the redirect) decides completion.
        final status = pollCount >= 3 ? 'PAID' : 'PENDING';
        return http.Response(
          jsonEncode({
            'data': {'id': 10, 'status': status},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final repo = WalletRepository(api: api);

    final terminal = await repo.awaitTerminal(
      10,
      timeout: const Duration(seconds: 10),
    );

    expect(terminal.status, 'PAID');
    expect(pollCount, 3);
  });

  test('awaitTerminal honours FAILED as terminal without waiting', () async {
    var pollCount = 0;
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        pollCount += 1;
        return http.Response(
          jsonEncode({
            'data': {'id': 11, 'status': 'FAILED'},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final repo = WalletRepository(api: api);

    final terminal = await repo.awaitTerminal(11);

    expect(terminal.status, 'FAILED');
    expect(pollCount, 1);
  });

  test('createPayment never fabricates a success from a redirect', () async {
    // Even when the server hands back a redirect_url, the client stores it as
    // an opaque value and exposes the server's own payment status verbatim —
    // it never marks the payment paid.
    final api = ApiClient(
      baseUrl: 'https://api.example.test/api/v1',
      tokenProvider: () => 'tok',
      httpClient: MockClient((request) async {
        return http.Response(
          jsonEncode({
            'data': {
              'payment': {'id': 12, 'status': 'PENDING'},
              'redirect_url': 'https://pay.example.test/12',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final repo = WalletRepository(api: api);

    final intent = await repo.createPayment(teamId: 6, provider: 'nagad');

    expect(intent.payment.status, 'PENDING');
    expect(intent.redirectUrl, isNotNull);
  });
}
