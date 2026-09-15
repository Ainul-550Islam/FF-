import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/avatar.dart';
import '../disputes/disputes_screen.dart';
import '../notifications/notifications_screen.dart';
import '../settings/settings_screen.dart';
import '../support/support_tickets_screen.dart';
import '../teams/team_list_screen.dart';
import '../wallet/wallet_screen.dart';
import 'profile_edit_screen.dart';

/// Profile tab (Phase 18 §59): renders the documented, privacy-redacted Me
/// resource and links to the account surfaces.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final me = services.session.me;

    void push(Widget screen) => Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => screen));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.profile)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Avatar(
                  name: me?.name ?? me?.username ?? '?',
                  url: me?.avatar,
                  radius: 32),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      me?.name ?? '—',
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
                    if (me?.username != null)
                      Text(
                        '@${me!.username}',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color:
                                Theme.of(context).colorScheme.onSurfaceVariant),
                      ),
                  ],
                ),
              ),
              IconButton(
                tooltip: l10n.editProfile,
                icon: const Icon(Icons.edit_outlined),
                onPressed: () => push(ProfileEditScreen(services: services)),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.groups_outlined),
                  title: Text(l10n.myTeams),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(TeamListScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.account_balance_wallet_outlined),
                  title: Text(l10n.wallet),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(WalletScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.notifications_outlined),
                  title: Text(l10n.notifications),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(NotificationsScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.help_outline),
                  title: Text(l10n.support),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(SupportTicketsScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.gavel_outlined),
                  title: Text(l10n.dispute),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(DisputesScreen(services: services)),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.settings_outlined),
                  title: Text(l10n.settings),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => push(SettingsScreen(services: services)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
