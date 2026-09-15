import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Wallet ledger (Phase 18 §23).
class LedgerScreen extends StatefulWidget {
  const LedgerScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LedgerScreen> createState() => _LedgerScreenState();
}

class _LedgerScreenState extends State<LedgerScreen> {
  late Future<List<LedgerEntry>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.ledger();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.ledger)),
      body: FutureBuilder<List<LedgerEntry>>(
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
                  setState(() => _future = widget.services.wallet.ledger()),
              child: const SizedBox(),
            );
          }
          final entries = snapshot.data ?? const <LedgerEntry>[];
          if (entries.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            itemCount: entries.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final e = entries[i];
              final credit = e.direction == 'credit';
              return ListTile(
                title: Text(e.type ?? '—'),
                trailing: Text(
                  '${credit ? '+' : '-'}${l10n.formatMoney(e.amountMinor ?? 0)}',
                  style: TextStyle(
                    color: credit
                        ? const Color(0xFF0B6E4F)
                        : Theme.of(context).colorScheme.error,
                    fontWeight: FontWeight.w600,
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
