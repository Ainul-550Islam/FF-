import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// Saved payment methods + provider availability (Phase 18 §23).
class PaymentMethodsScreen extends StatefulWidget {
  const PaymentMethodsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PaymentMethodsScreen> createState() => _PaymentMethodsScreenState();
}

class _PaymentMethodsScreenState extends State<PaymentMethodsScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.wallet.methods();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.savedMethods)),
      body: FutureBuilder<Map<String, dynamic>>(
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
                  setState(() => _future = widget.services.wallet.methods()),
              child: const SizedBox(),
            );
          }
          final data = snapshot.data ?? const {};
          final providers = data['providers'];
          final saved = data['saved_methods'];
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.provider,
                child: providers is List
                    ? Column(
                        children: [
                          for (final p in providers)
                            if (p is Map<String, dynamic>)
                              KeyValueRow(
                                label: '${p['label'] ?? p['id'] ?? '—'}',
                                value: p['enabled'] == true &&
                                        p['configured'] == true
                                    ? l10n.active
                                    : l10n.pushDisabled,
                              ),
                        ],
                      )
                    : Text(l10n.empty),
              ),
              SectionCard(
                title: l10n.savedMethods,
                child: saved is List && saved.isNotEmpty
                    ? Column(
                        children: [
                          for (final m in saved)
                            if (m is Map<String, dynamic>)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: const Icon(Icons.credit_card),
                                title: Text('${m['provider'] ?? ''}'),
                                subtitle: Text('${m['label'] ?? ''}'),
                              ),
                        ],
                      )
                    : Text(l10n.noSavedMethods),
              ),
            ],
          );
        },
      ),
    );
  }
}
