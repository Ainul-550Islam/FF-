import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'team_detail_screen.dart';

/// My teams (Phase 18 §21).
class TeamListScreen extends StatefulWidget {
  const TeamListScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<TeamListScreen> createState() => _TeamListScreenState();
}

class _TeamListScreenState extends State<TeamListScreen> {
  late Future<List<Team>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.mine();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.myTeams)),
      body: FutureBuilder<List<Team>>(
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
                  setState(() => _future = widget.services.teams.mine()),
              child: const SizedBox(),
            );
          }
          final teams = snapshot.data ?? const <Team>[];
          if (teams.isEmpty) {
            return Center(child: Text(l10n.noTeams));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: teams.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = teams[i];
              return Card(
                child: ListTile(
                  title: Text(t.name ?? '—'),
                  subtitle: t.tournamentId != null
                      ? Text('Tournament #${t.tournamentId}')
                      : null,
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => TeamDetailScreen(
                        services: widget.services,
                        team: t,
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
