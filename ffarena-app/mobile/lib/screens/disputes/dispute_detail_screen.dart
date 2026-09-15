import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';

/// Single dispute (Phase 18 §29).
class DisputeDetailScreen extends StatefulWidget {
  const DisputeDetailScreen({
    super.key,
    required this.services,
    required this.disputeId,
  });

  final AppServices services;
  final int disputeId;

  @override
  State<DisputeDetailScreen> createState() => _DisputeDetailScreenState();
}

class _DisputeDetailScreenState extends State<DisputeDetailScreen> {
  late Future<Dispute> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.disputes.get(widget.disputeId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.dispute)),
      body: FutureBuilder<Dispute>(
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
              onRetry: () => setState(() =>
                  _future = widget.services.disputes.get(widget.disputeId)),
              child: const SizedBox(),
            );
          }
          final d = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.status,
                trailing:
                    d.status != null ? StatusPill(status: d.status!) : null,
                child: Column(
                  children: [
                    KeyValueRow(label: l10n.category, value: d.category ?? '—'),
                    KeyValueRow(
                        label: l10n.matchNo, value: '#${d.matchId ?? '—'}'),
                    if (d.resolution != null)
                      KeyValueRow(label: l10n.status, value: d.resolution!),
                  ],
                ),
              ),
              if (d.description != null)
                SectionCard(
                  title: l10n.message,
                  child: Text(d.description!),
                ),
            ],
          );
        },
      ),
    );
  }
}
