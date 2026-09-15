import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'dispute_detail_screen.dart';

/// Disputes (Phase 18 §29). Read-only on mobile — filing happens on the web
/// match page; the client never invents a create endpoint.
class DisputesScreen extends StatefulWidget {
  const DisputesScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<DisputesScreen> createState() => _DisputesScreenState();
}

class _DisputesScreenState extends State<DisputesScreen> {
  late Future<List<Dispute>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.disputes.mine();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.dispute)),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            color: const Color(0xFFFFF3CD),
            padding: const EdgeInsets.all(12),
            child: Text(l10n.disputeWebOnly,
                style: const TextStyle(color: Colors.black87)),
          ),
          Expanded(
            child: FutureBuilder<List<Dispute>>(
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
                    onRetry: () => setState(
                        () => _future = widget.services.disputes.mine()),
                    child: const SizedBox(),
                  );
                }
                final disputes = snapshot.data ?? const <Dispute>[];
                if (disputes.isEmpty) {
                  return Center(child: Text(l10n.noDisputes));
                }
                return ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: disputes.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (context, i) {
                    final d = disputes[i];
                    return Card(
                      child: ListTile(
                        title: Text(d.category ?? l10n.dispute),
                        subtitle: Text('Match #${d.matchId ?? '—'}'),
                        trailing: d.status != null
                            ? StatusPill(status: d.status!)
                            : null,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => DisputeDetailScreen(
                              services: widget.services,
                              disputeId: d.id!,
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
