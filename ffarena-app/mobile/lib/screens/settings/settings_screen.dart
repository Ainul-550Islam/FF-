import 'package:flutter/material.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/l10n/app_localizations.dart';
import 'app_about_screen.dart';
import 'devices_screen.dart';
import 'notification_preferences_screen.dart';
import 'payment_methods_screen.dart';
import 'phone_link_screen.dart';
import 'privacy_screen.dart';
import 'security_screen.dart';
import 'sessions_screen.dart';

/// Settings hub (Phase 18 §61).
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;

    void push(Widget screen) => Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => screen));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.settings)),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.shield_outlined),
            title: Text(l10n.accountSecurity),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(SecurityScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.devices_outlined),
            title: Text(l10n.sessions),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(SessionsScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.phone_android_outlined),
            title: Text(l10n.linkPhone),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PhoneLinkScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.credit_card_outlined),
            title: Text(l10n.savedMethods),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PaymentMethodsScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.visibility_outlined),
            title: Text(l10n.privacy),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(PrivacyScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.language),
            title: Text(l10n.language),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _chooseLanguage(context),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.notifications_outlined),
            title: Text(l10n.notifPreferences),
            subtitle: Text(services.push.isConfigured
                ? l10n.pushEnabled
                : l10n.pushDisabled),
            trailing: const Icon(Icons.chevron_right),
            onTap: () =>
                push(NotificationPreferencesScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.smartphone_outlined),
            title: Text(l10n.devices),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(DevicesScreen(services: services)),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.info_outline),
            title: Text(l10n.about),
            subtitle:
                Text('${config.version}+${config.buildNumber} · ${config.env}'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => push(AppAboutScreen(services: services)),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(16),
            child: OutlinedButton(
              onPressed: () => services.session.logout(),
              child: Text(l10n.logout),
            ),
          ),
        ],
      ),
    );
  }

  void _chooseLanguage(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    showDialog<void>(
      context: context,
      builder: (context) => SimpleDialog(
        title: Text(l10n.language),
        children: [
          SimpleDialogOption(
            onPressed: () {
              LocaleController.instance.set('en');
              Navigator.pop(context);
            },
            child: Text(l10n.english),
          ),
          SimpleDialogOption(
            onPressed: () {
              LocaleController.instance.set('bn');
              Navigator.pop(context);
            },
            child: Text(l10n.bangla),
          ),
        ],
      ),
    );
  }
}
