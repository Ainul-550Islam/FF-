import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Result of `POST /payments`: the payment plus an optional external
/// redirect URL (present only for non-manual providers).
class PaymentIntent {
  const PaymentIntent({required this.payment, this.redirectUrl});

  final Payment payment;
  final String? redirectUrl;
}

/// Wallet, ledger, payouts and payments (Phase 18 §23/§24).
///
/// The client NEVER marks a payment successful locally and never computes a
/// balance. Payments are entry-fee payments for a team: `POST /payments`
/// takes `{team_id, provider}` and returns a `redirect_url` only for
/// non-manual providers. Completion is always determined by polling
/// `GET /payments/{id}` until the SERVER reports a terminal status.
class WalletRepository {
  WalletRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Wallet> wallet() async {
    final envelope = await _api.get('/me/wallet');
    return Wallet.fromJson(envelope.asMap ?? const {});
  }

  Future<List<LedgerEntry>> ledger({int page = 1}) async {
    final envelope =
        await _api.get('/me/wallet/ledger', query: {'page': '$page'});
    return envelope.asList
        .map((m) => LedgerEntry.fromJson(m))
        .toList(growable: false);
  }

  Future<List<Payout>> payouts() async {
    final envelope = await _api.get('/me/payouts');
    return envelope.asList
        .map((m) => Payout.fromJson(m))
        .toList(growable: false);
  }

  /// POST /payments — creates an entry-fee payment for a team.
  ///
  /// The server responds `{payment, redirect_url}`; `redirect_url` is present
  /// only for providers that require an external redirect (never for the
  /// Bangladesh manual providers).
  Future<PaymentIntent> createPayment({
    required int teamId,
    required String provider,
  }) async {
    final envelope = await _api.post(
      '/payments',
      body: {'team_id': teamId, 'provider': provider},
      idempotencyKey: Idempotency.generate(),
    );
    final map = envelope.asMap ?? const {};
    final paymentJson = map['payment'];
    final payment = paymentJson is Map<String, dynamic>
        ? Payment.fromJson(paymentJson)
        : Payment.fromJson(map);
    return PaymentIntent(
      payment: payment,
      redirectUrl: map['redirect_url'] as String?,
    );
  }

  /// GET /payments/{id} — server-authoritative payment state.
  Future<Payment> payment(int id) async {
    final envelope = await _api.get('/payments/$id');
    return Payment.fromJson(envelope.asMap ?? const {});
  }

  /// GET /payments/methods — saved methods + provider availability.
  Future<Map<String, dynamic>> methods() async {
    final envelope = await _api.get('/payments/methods');
    return envelope.asMap ?? const {};
  }

  /// Polls until the server reports a terminal status (or timeout).
  Future<Payment> awaitTerminal(
    int id, {
    Duration timeout = const Duration(minutes: 2),
  }) async {
    final deadline = DateTime.now().add(timeout);
    while (DateTime.now().isBefore(deadline)) {
      final p = await payment(id);
      final status = p.status ?? '';
      if (status == 'PAID' ||
          status == 'FAILED' ||
          status == 'CANCELLED' ||
          status == 'EXPIRED') {
        return p;
      }
      await Future<void>.delayed(const Duration(seconds: 2));
    }
    return payment(id);
  }
}
