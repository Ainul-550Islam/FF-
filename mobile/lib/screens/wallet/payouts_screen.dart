import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';

/// Payouts (Phase 18 §23).
class PayoutsScreen extends StatefulWidget {
  const PayoutsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PayoutsScreen> createState() => _PayoutsScreenState();
}

class _PayoutsScreenState extends State<PayoutsScreen> {
  late Future<List<Payout>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.payouts();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.payouts)),
      body: FutureBuilder<List<Payout>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () =>
                  setState(() => _future = widget.services.wallet.payouts()),
              child: const SizedBox(),
            );
          }
          final payouts = snapshot.data ?? const <Payout>[];
          if (payouts.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: payouts.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final p = payouts[i];
              return Card(
                child: ListTile(
                  title: Text('${l10n.payout} #${p.id}'),
                  subtitle: Text('${l10n.rank}: ${p.rank ?? '—'}'),
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        l10n.formatMoney(p.amountMinor ?? 0),
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      if (p.status != null) StatusPill(status: p.status!),
                    ],
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
