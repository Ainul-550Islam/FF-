import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import '../notifications/notifications_screen.dart';
import '../tournaments/tournament_detail_screen.dart';
import '../tournaments/tournament_list_screen.dart';
import '../wallet/wallet_screen.dart';

/// Home tab (Phase 18 §11): live feed, my tournaments, upcoming matches and
/// a wallet summary — all read from server-authoritative endpoints.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late Future<_HomeData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_HomeData> _load() async {
    final live = await widget.services.live.me();
    final teams = await widget.services.teams.mine();
    final wallet = await widget.services.wallet.wallet();
    return _HomeData(live: live, teams: teams, wallet: wallet);
  }

  Future<void> _refresh() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final session = widget.services.session;

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.home),
        actions: [
          IconButton(
            tooltip: l10n.notifications,
            icon: const Icon(Icons.notifications_outlined),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => NotificationsScreen(services: widget.services),
              ),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<_HomeData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return ListView(
                children: const [
                  SizedBox(height: 200),
                  Center(child: CircularProgressIndicator()),
                ],
              );
            }
            final error = _errorOf(snapshot.error, l10n);
            if (error != null) {
              return AsyncView(
                loading: false,
                error: error,
                empty: false,
                onRetry: _refresh,
                child: const SizedBox(),
              );
            }
            final data = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.only(bottom: 24),
              children: [
                // Greeting
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Text(
                    '${l10n.appName} — ${session.me?.name ?? ''}',
                    style: Theme.of(context)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                // Live feed
                if (data.live.isNotEmpty)
                  SectionCard(
                    title: l10n.live,
                    child: Column(
                      children: [
                        for (final event in data.live.take(5))
                          _LiveRow(event: event, services: widget.services),
                      ],
                    ),
                  ),
                // My teams
                if (data.teams.isNotEmpty)
                  SectionCard(
                    title: l10n.myTeams,
                    trailing: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) =>
                              TournamentListScreen(services: widget.services),
                        ),
                      ),
                      child: Text(l10n.viewAll),
                    ),
                    child: Column(
                      children: [
                        for (final team in data.teams.take(5))
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: CircleAvatar(
                              child: Text(
                                (team.name ?? '?')
                                    .substring(0, 1)
                                    .toUpperCase(),
                              ),
                            ),
                            title: Text(team.name ?? '—'),
                            subtitle: team.tournamentId != null
                                ? Text('Tournament #${team.tournamentId}')
                                : null,
                            trailing: team.status != null
                                ? StatusPill(status: team.status!)
                                : null,
                          ),
                      ],
                    ),
                  ),
                // Wallet summary
                SectionCard(
                  title: l10n.wallet,
                  trailing: TextButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => WalletScreen(services: widget.services),
                      ),
                    ),
                    child: Text(l10n.viewAll),
                  ),
                  child: Text(
                    l10n.formatMoney(data.wallet.balanceMinor ?? 0),
                    style: Theme.of(context)
                        .textTheme
                        .headlineSmall
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  String? _errorOf(Object? error, AppLocalizations l10n) {
    if (error == null) {
      return null;
    }
    if (error is ApiException) {
      return error.localized(l10n);
    }
    return l10n.errorGeneric;
  }
}

class _HomeData {
  const _HomeData({
    required this.live,
    required this.teams,
    required this.wallet,
  });

  final List<LiveEvent> live;
  final List<Team> teams;
  final Wallet wallet;
}

class _LiveRow extends StatelessWidget {
  const _LiveRow({required this.event, required this.services});

  final LiveEvent event;
  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final tournamentId = event.tournamentId;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.sports_esports),
      title: Text(event.type ?? 'event'),
      trailing: tournamentId != null
          ? IconButton(
              icon: const Icon(Icons.chevron_right),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => TournamentDetailScreen(
                      services: services, tournamentId: tournamentId),
                ),
              ),
            )
          : null,
    );
  }
}
