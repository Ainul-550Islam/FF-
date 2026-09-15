import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'match_detail_screen.dart';

/// Match centre (Phase 18 §26): lists a tournament's matches (embedded tab)
/// or, standalone, the user's upcoming matches.
class MatchCenterScreen extends StatefulWidget {
  const MatchCenterScreen({
    super.key,
    required this.services,
    this.tournamentId,
    this.embedded = false,
  });

  final AppServices services;
  final int? tournamentId;
  final bool embedded;

  @override
  State<MatchCenterScreen> createState() => _MatchCenterScreenState();
}

class _MatchCenterScreenState extends State<MatchCenterScreen> {
  late Future<List<MatchModel>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<MatchModel>> _load() {
    final id = widget.tournamentId;
    if (id != null) {
      return widget.services.tournaments.matches(id);
    }
    // Standalone: matches across the user's tournaments.
    return widget.services.teams.mine().then((teams) async {
      final matches = <MatchModel>[];
      for (final team in teams) {
        final tournamentId = team.tournamentId;
        if (tournamentId == null) {
          continue;
        }
        try {
          final list = await widget.services.tournaments.matches(tournamentId);
          for (final m in list) {
            if (m.status != null && _isUpcoming(m.status!)) {
              matches.add(m);
            }
          }
        } on ApiException {
          // Skip unavailable tournaments.
        }
      }
      return matches;
    });
  }

  bool _isUpcoming(String status) =>
      status.toLowerCase() == 'scheduled' || status.toLowerCase() == 'open';

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final content = FutureBuilder<List<MatchModel>>(
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
            onRetry: () => setState(() => _future = _load()),
            child: const SizedBox(),
          );
        }
        final matches = snapshot.data ?? const <MatchModel>[];
        if (matches.isEmpty) {
          return Center(child: Text(l10n.noScoresYet));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: matches.length,
          separatorBuilder: (_, __) => const SizedBox(height: 12),
          itemBuilder: (context, i) {
            final m = matches[i];
            return Card(
              child: ListTile(
                title: Text('${l10n.matchNo} ${m.matchNo ?? m.id ?? '—'}'),
                subtitle: Text('${l10n.round} ${m.round ?? '—'}'),
                trailing:
                    m.status != null ? StatusPill(status: m.status!) : null,
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => MatchDetailScreen(
                      services: widget.services,
                      matchId: m.id!,
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );

    if (widget.embedded) {
      return content;
    }
    return Scaffold(
      appBar: AppBar(
          title: Text(widget.tournamentId != null
              ? l10n.matches
              : l10n.myUpcomingMatches)),
      body: content,
    );
  }
}
