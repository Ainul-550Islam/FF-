import 'dart:convert';

import 'package:ffarena_mobile/app.dart';
import 'package:ffarena_mobile/core/api/api_client.dart';
import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/l10n/app_localizations.dart';
import 'package:ffarena_mobile/data/repositories/wallet_repository.dart';
import 'package:ffarena_mobile/screens/registration/payment_checkout_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Minimal AppServices stand-in: the checkout screen only touches `wallet`.
/// Anything else throws loudly instead of silently misbehaving.
class _CheckoutServices implements AppServices {
  _CheckoutServices(this.wallet);

  @override
  final WalletRepository wallet;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

MockClient _mockApi({required String terminalStatus}) {
  return MockClient((request) async {
    http.Response ok(Object body) => http.Response(
          jsonEncode(body),
          200,
          headers: {'content-type': 'application/json'},
        );
    final path = request.url.path;
    if (request.method == 'GET' && path == '/api/v1/payments/methods') {
      return ok({
        'data': {
          'providers': [
            {'id': 'manual', 'enabled': true, 'configured': true},
          ],
        },
      });
    }
    if (request.method == 'POST' && path == '/api/v1/payments') {
      return ok({
        'data': {
          'payment': {'id': 7, 'status': 'pending'},
          'redirect_url': null,
        },
      });
    }
    if (request.method == 'GET' && path == '/api/v1/payments/7') {
      return ok({
        'data': {'id': 7, 'status': terminalStatus},
      });
    }
    return http.Response('not found', 404);
  });
}

Future<void> _pumpCheckout(
  WidgetTester tester, {
  required String terminalStatus,
}) async {
  final api = ApiClient(
    baseUrl: 'https://api.example.test/api/v1',
    tokenProvider: () => 'tok',
    httpClient: _mockApi(terminalStatus: terminalStatus),
  );
  final services = _CheckoutServices(WalletRepository(api: api));
  await tester.pumpWidget(MaterialApp(
    supportedLocales: AppLocalizations.supportedLocales,
    localizationsDelegates: AppLocalizations.localizationsDelegates,
    home: PaymentCheckoutScreen(
      services: services,
      team: const Team(id: 3),
    ),
  ));
  await tester.pumpAndSettle();
}

/// The checkout screen polls `awaitTerminal` and renders the server's own
/// verdict — it must recognise the server's LOWERCASE statuses, not only
/// the UPPERCASE strings used in fixtures.
void main() {
  group('PaymentCheckoutScreen', () {
    testWidgets('shows confirmation when the server reports lowercase paid',
        (tester) async {
      await _pumpCheckout(tester, terminalStatus: 'paid');

      expect(find.text('Payment confirmed.'), findsOneWidget);
    });

    testWidgets('still recognises uppercase PAID', (tester) async {
      await _pumpCheckout(tester, terminalStatus: 'PAID');

      expect(find.text('Payment confirmed.'), findsOneWidget);
    });

    testWidgets('shows failure when the server reports failed',
        (tester) async {
      await _pumpCheckout(tester, terminalStatus: 'failed');

      expect(find.text('Payment was not completed.'), findsOneWidget);
    });
  });
}
