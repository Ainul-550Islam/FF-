import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// A player's ranking summary (Phase 18 §59). Server-computed.
class RankingScreen extends StatefulWidget {
  const RankingScreen({
    super.key,
    required this.services,
    required this.userId,
  });

  final AppServices services;
  final int userId;

  @override
  State<RankingScreen> createState() => _RankingScreenState();
}

class _RankingScreenState extends State<RankingScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.playerRanking(widget.userId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.leaderboard)),
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
              onRetry: () => setState(() => _future =
                  widget.services.leaderboard.playerRanking(widget.userId)),
              child: const SizedBox(),
            );
          }
          final data = snapshot.data ?? const {};
          final entries = data.entries
              .where((e) => e.value is num || e.value is String)
              .toList();
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.leaderboard,
                child: Column(
                  children: [
                    for (final e in entries)
                      KeyValueRow(label: e.key, value: '${e.value}'),
                    if (entries.isEmpty) Text(l10n.empty),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
