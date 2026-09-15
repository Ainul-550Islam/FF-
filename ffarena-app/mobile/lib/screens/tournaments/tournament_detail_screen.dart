import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import '../matches/match_center_screen.dart';
import '../registration/check_in_screen.dart';
import '../registration/team_register_screen.dart';
import '../standings/standings_screen.dart';

/// Tournament detail (Phase 18 §19): overview, matches, standings, bracket
/// and live — plus the registration / check-in entry points.
class TournamentDetailScreen extends StatefulWidget {
  const TournamentDetailScreen({
    super.key,
    required this.services,
    required this.tournamentId,
  });

  final AppServices services;
  final int? tournamentId;

  @override
  State<TournamentDetailScreen> createState() => _TournamentDetailScreenState();
}

class _TournamentDetailScreenState extends State<TournamentDetailScreen> {
  late Future<Tournament> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.tournaments.get(widget.tournamentId!);
  }

  Future<void> _refresh() async {
    setState(
        () => _future = widget.services.tournaments.get(widget.tournamentId!));
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final id = widget.tournamentId!;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tournaments)),
      body: FutureBuilder<Tournament>(
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
              onRetry: _refresh,
              child: const SizedBox(),
            );
          }
          final t = snapshot.data!;
          return DefaultTabController(
            length: 4,
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              t.name ?? '—',
                              style: Theme.of(context)
                                  .textTheme
                                  .headlineSmall
                                  ?.copyWith(fontWeight: FontWeight.bold),
                            ),
                          ),
                          if (t.status != null) StatusPill(status: t.status!),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${(t.gameMode ?? '').toUpperCase()} · ${t.map ?? ''} · ${t.format ?? ''}',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 12),
                      if (t.acceptsRegistration == true)
                        FilledButton.icon(
                          icon: const Icon(Icons.how_to_reg),
                          label: Text(l10n.register),
                          onPressed: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => TeamRegisterScreen(
                                services: widget.services,
                                tournament: t,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const TabBar(
                  tabs: [
                    Tab(text: 'Overview'),
                    Tab(text: 'Matches'),
                    Tab(text: 'Standings'),
                    Tab(text: 'Live'),
                  ],
                ),
                Expanded(
                  child: TabBarView(
                    children: [
                      _OverviewTab(tournament: t, services: widget.services),
                      MatchCenterScreen(
                        services: widget.services,
                        tournamentId: id,
                        embedded: true,
                      ),
                      StandingsScreen(
                        services: widget.services,
                        tournamentId: id,
                        embedded: true,
                      ),
                      _LiveTab(services: widget.services, tournamentId: id),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _OverviewTab extends StatelessWidget {
  const _OverviewTab({required this.tournament, required this.services});

  final Tournament tournament;
  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final t = tournament;
    return ListView(
      children: [
        SectionCard(
          title: l10n.status,
          child: Column(
            children: [
              KeyValueRow(
                  label: l10n.entryFee,
                  value: l10n.formatMoney(t.entryFeeMinor ?? 0)),
              KeyValueRow(label: l10n.prizePool, value: t.prizePool ?? '—'),
              KeyValueRow(label: l10n.slotsLeft, value: '${t.slotsLeft ?? 0}'),
              KeyValueRow(label: l10n.teamSize, value: '${t.teamSize ?? '—'}'),
              KeyValueRow(
                  label: l10n.startsAt,
                  value: Dates.formatDateTime(t.startsAt)),
              if (t.checkInStartsAt != null)
                KeyValueRow(
                    label: l10n.checkInOpens,
                    value: Dates.formatDateTime(t.checkInStartsAt)),
              if (t.checkInEndsAt != null)
                KeyValueRow(
                    label: l10n.checkInCloses,
                    value: Dates.formatDateTime(t.checkInEndsAt)),
            ],
          ),
        ),
        SectionCard(
          title: l10n.viewBracket,
          child: Text(l10n.viewBracket),
        ),
        if (t.checkInStartsAt != null)
          Padding(
            padding: const EdgeInsets.all(16),
            child: OutlinedButton.icon(
              icon: const Icon(Icons.how_to_reg),
              label: Text(l10n.checkIn),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => CheckInScreen(
                    services: services,
                    tournamentId: t.id!,
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _LiveTab extends StatefulWidget {
  const _LiveTab({required this.services, required this.tournamentId});

  final AppServices services;
  final int tournamentId;

  @override
  State<_LiveTab> createState() => _LiveTabState();
}

class _LiveTabState extends State<_LiveTab> {
  late Future<List<LiveEvent>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.live.tournament(widget.tournamentId);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<LiveEvent>>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        final events = snapshot.data ?? const <LiveEvent>[];
        return AsyncView(
          loading: false,
          error: null,
          empty: events.isEmpty,
          onRetry: () {},
          child: ListView.builder(
            itemCount: events.length,
            itemBuilder: (context, i) => ListTile(
              leading: const Icon(Icons.sports_esports),
              title: Text(events[i].type ?? 'event'),
              subtitle: events[i].createdAt != null
                  ? Text(Dates.formatDateTime(events[i].createdAt))
                  : null,
            ),
          ),
        );
      },
    );
  }
}
