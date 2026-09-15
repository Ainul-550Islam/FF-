import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import '../standings/standings_screen.dart';

/// Leaderboard hub (Phase 18 §22): lists ranked tournaments, then opens the
/// server-computed standings.
class LeaderboardScreen extends StatefulWidget {
  const LeaderboardScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<LeaderboardScreen> createState() => _LeaderboardScreenState();
}

class _LeaderboardScreenState extends State<LeaderboardScreen> {
  late Future<List<Tournament>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.leaderboard.rankedTournaments();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.leaderboard)),
      body: FutureBuilder<List<Tournament>>(
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
                  _future = widget.services.leaderboard.rankedTournaments()),
              child: const SizedBox(),
            );
          }
          final tournaments = snapshot.data ?? const <Tournament>[];
          if (tournaments.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: tournaments.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = tournaments[i];
              return Card(
                child: ListTile(
                  title: Text(t.name ?? '—'),
                  subtitle: Text('${l10n.prizePool}: ${t.prizePool ?? '—'}'),
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => StandingsScreen(
                        services: widget.services,
                        tournamentId: t.id!,
                      ),
                    ),
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
