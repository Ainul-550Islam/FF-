import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../core/version/version_gate.dart';
import '../../features/deep_links/deep_link_router.dart';
import '../disputes/dispute_detail_screen.dart';
import '../home/home_screen.dart';
import '../leaderboard/leaderboard_screen.dart';
import '../matches/match_center_screen.dart';
import '../matches/match_detail_screen.dart';
import '../profile/profile_screen.dart';
import '../profile/public_profile_screen.dart';
import '../settings/security_screen.dart';
import '../standings/standings_screen.dart';
import '../support/support_chat_screen.dart';
import '../tournaments/tournament_detail_screen.dart';
import '../tournaments/tournament_list_screen.dart';
import '../wallet/ledger_screen.dart';
import '../wallet/payouts_screen.dart';

/// Root authenticated shell (Phase 18 §16): bottom navigation + deep-link
/// routing. Every deep link target requires an authenticated, authorized
/// server response before it renders.
class AppShell extends StatefulWidget {
  const AppShell({super.key, required this.services});

  final AppServices services;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    widget.services.deepLinks.registerHandler(_handleDeepLink);
    // Deliver any deep link that arrived before this authenticated shell
    // mounted (cold-start App/Universal link, or a link opened while the
    // session was restoring / before login). Post-frame so navigation has a
    // fully built context.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        widget.services.deepLinks.flushPending();
      }
    });
  }

  @override
  void dispose() {
    // A link that arrives while logged out must be queued (and delivered
    // after the next login), not dropped by a stale handler bound to this
    // now-disposed shell.
    widget.services.deepLinks.unregisterHandler();
    super.dispose();
  }

  void _handleDeepLink(DeepLink link) {
    if (!mounted) {
      return;
    }
    // Every target screen fetches an authorized server resource before it
    // renders; an id is required for entity targets.
    switch (link.target) {
      case DeepLinkTarget.tournament:
        final id = link.id;
        if (id != null) {
          _push(TournamentDetailScreen(
              services: widget.services, tournamentId: id));
        }
      case DeepLinkTarget.match:
        final id = link.id;
        if (id != null) {
          _push(MatchDetailScreen(services: widget.services, matchId: id));
        }
      case DeepLinkTarget.profile:
        final id = link.id;
        if (id != null) {
          _push(PublicProfileScreen(services: widget.services, userId: id));
        }
      case DeepLinkTarget.leaderboard:
        final id = link.id;
        if (id != null) {
          _push(StandingsScreen(services: widget.services, tournamentId: id));
        }
      case DeepLinkTarget.support:
        final id = link.id;
        if (id != null) {
          _push(SupportChatScreen(services: widget.services, ticketId: id));
        }
      case DeepLinkTarget.dispute:
        final id = link.id;
        if (id != null) {
          _push(DisputeDetailScreen(services: widget.services, disputeId: id));
        }
      case DeepLinkTarget.payment:
        // Payment state lives in the wallet ledger (server-authoritative).
        _push(LedgerScreen(services: widget.services));
      case DeepLinkTarget.payout:
        _push(PayoutsScreen(services: widget.services));
      case DeepLinkTarget.security:
        _push(SecurityScreen(services: widget.services));
    }
  }

  void _push(Widget screen) {
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => screen));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    final tabs = <Widget>[
      HomeScreen(services: widget.services),
      TournamentListScreen(services: widget.services),
      MatchCenterScreen(services: widget.services),
      LeaderboardScreen(services: widget.services),
      ProfileScreen(services: widget.services),
    ];

    return Scaffold(
      body: Column(
        children: [
          if (widget.services.gate.gate == AppGate.updateAvailable)
            _UpdateAvailableBanner(services: widget.services),
          Expanded(
            child: IndexedStack(index: _index, children: tabs),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home),
            label: l10n.home,
          ),
          NavigationDestination(
            icon: const Icon(Icons.emoji_events_outlined),
            selectedIcon: const Icon(Icons.emoji_events),
            label: l10n.tournaments,
          ),
          NavigationDestination(
            icon: const Icon(Icons.sports_esports_outlined),
            selectedIcon: const Icon(Icons.sports_esports),
            label: l10n.matches,
          ),
          NavigationDestination(
            icon: const Icon(Icons.leaderboard_outlined),
            selectedIcon: const Icon(Icons.leaderboard),
            label: l10n.leaderboard,
          ),
          NavigationDestination(
            icon: const Icon(Icons.person_outline),
            selectedIcon: const Icon(Icons.person),
            label: l10n.profile,
          ),
        ],
      ),
    );
  }
}

/// Non-blocking banner shown when a newer (but not required) app version is
/// available. The user can keep using the app.
class _UpdateAvailableBanner extends StatelessWidget {
  const _UpdateAvailableBanner({required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return Material(
      color: Theme.of(context).colorScheme.tertiaryContainer,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                l10n.updateAvailableBody,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            const SizedBox(width: 8),
            Icon(
              Icons.system_update_alt,
              size: 18,
              color: Theme.of(context).colorScheme.onTertiaryContainer,
            ),
          ],
        ),
      ),
    );
  }
}
