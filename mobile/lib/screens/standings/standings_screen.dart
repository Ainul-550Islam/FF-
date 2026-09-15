import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Standings (Phase 18 §22). Renders server-computed rank/points; the client
/// never recomputes them.
class StandingsScreen extends StatefulWidget {
  const StandingsScreen({
    super.key,
    required this.services,
    required this.tournamentId,
    this.embedded = false,
  });

  final AppServices services;
  final int tournamentId;
  final bool embedded;

  @override
  State<StandingsScreen> createState() => _StandingsScreenState();
}

class _StandingsScreenState extends State<StandingsScreen> {
  late Future<List<StandingRow>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.standings(widget.tournamentId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final content = FutureBuilder<List<StandingRow>>(
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
                widget.services.leaderboard.standings(widget.tournamentId)),
            child: const SizedBox(),
          );
        }
        final rows = snapshot.data ?? const <StandingRow>[];
        if (rows.isEmpty) {
          return Center(child: Text(l10n.empty));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: rows.length,
          separatorBuilder: (_, __) => const Divider(height: 1),
          itemBuilder: (context, i) {
            final r = rows[i];
            return ListTile(
              leading: CircleAvatar(child: Text('${r.rank ?? i + 1}')),
              title: Text(r.teamName ?? '—'),
              subtitle: Text(
                  '${l10n.kills}: ${r.kills ?? 0} · ${l10n.points}: ${r.points ?? 0}'),
            );
          },
        );
      },
    );

    if (widget.embedded) {
      return content;
    }
    return Scaffold(
      appBar: AppBar(title: Text(l10n.standings)),
      body: content,
    );
  }
}
