import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/status_pill.dart';

/// Entry-fee payment checkout (Phase 18 §23/§24).
///
/// The client NEVER marks a payment successful locally. It POSTs the intent
/// with `{team_id, provider}`, follows the server's `redirect_url` when one
/// is provided, then polls `GET /payments/{id}` until the SERVER reports a
/// terminal status.
class PaymentCheckoutScreen extends StatefulWidget {
  const PaymentCheckoutScreen({
    super.key,
    required this.services,
    required this.team,
  });

  final AppServices services;
  final Team team;

  @override
  State<PaymentCheckoutScreen> createState() => _PaymentCheckoutScreenState();
}

class _PaymentCheckoutScreenState extends State<PaymentCheckoutScreen> {
  Payment? _payment;
  bool _busy = false;
  String? _error;
  String? _status;

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });

    // Discover which providers are actually configured (never assume).
    Map<String, dynamic> methods = const {};
    try {
      methods = await widget.services.wallet.methods();
    } on ApiException {
      methods = const {};
    }

    final provider = _chooseProvider(methods);
    if (provider == null) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = l10n.errorGeneric;
        _busy = false;
      });
      return;
    }

    try {
      final intent = await widget.services.wallet.createPayment(
        teamId: widget.team.id!,
        provider: provider,
      );
      final payment = intent.payment;
      if (!mounted) {
        return;
      }
      setState(() => _payment = payment);

      // Follow the redirect only when the server provides one; completion is
      // always confirmed by polling the server status, never the redirect.
      if (intent.redirectUrl != null) {
        await launchUrl(Uri.parse(intent.redirectUrl!),
            mode: LaunchMode.externalApplication);
      }

      if (!mounted) {
        return;
      }
      setState(() => _status = l10n.pollForStatus);
      final terminal = await widget.services.wallet.awaitTerminal(payment.id!);
      if (!mounted) {
        return;
      }
      setState(() {
        _status = switch (terminal.status ?? '') {
          'PAID' => l10n.paymentPaid,
          'FAILED' || 'CANCELLED' || 'EXPIRED' => l10n.paymentFailed,
          _ => l10n.paymentPending,
        };
      });
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      if (!mounted) {
        return;
      }
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  /// Picks the first enabled AND configured provider the server reports.
  String? _chooseProvider(Map<String, dynamic> methods) {
    final providers = methods['providers'];
    if (providers is! List) {
      return null;
    }
    for (final p in providers) {
      if (p is Map<String, dynamic> &&
          p['enabled'] == true &&
          p['configured'] == true) {
        final id = p['id'];
        if (id is String && id.isNotEmpty) {
          return id;
        }
      }
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final payment = _payment;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.entryFee)),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (_busy) ...[
                const CircularProgressIndicator(),
                const SizedBox(height: 16),
                Text(l10n.redirectingToPayment),
              ] else ...[
                if (payment != null && payment.status != null)
                  StatusPill(status: payment.status!),
                const SizedBox(height: 16),
                Text(_status ?? l10n.paymentPending,
                    textAlign: TextAlign.center),
              ],
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
