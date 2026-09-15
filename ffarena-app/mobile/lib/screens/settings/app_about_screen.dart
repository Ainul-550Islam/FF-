import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../config/app_config.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// About (Phase 18 §66): version/build, environment, legal links (URLs come
/// from `/api/v1/app/meta`). Links open only on an explicit user tap.
class AppAboutScreen extends StatelessWidget {
  const AppAboutScreen({super.key, required this.services});

  final AppServices services;

  Future<void> _open(BuildContext context, String url) async {
    try {
      await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(url)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final config = AppConfig.instance;
    final meta = services.meta.cached;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.about)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SectionCard(
            title: l10n.about,
            child: Column(
              children: [
                KeyValueRow(label: l10n.appName, value: config.appName),
                KeyValueRow(
                    label: l10n.version,
                    value: '${config.version}+${config.buildNumber}'),
                KeyValueRow(label: 'Env', value: config.env),
                KeyValueRow(label: 'API', value: config.apiBaseUrl),
                KeyValueRow(label: 'Scheme', value: config.deepLinkScheme),
              ],
            ),
          ),
          if (meta?.urls?.privacy != null)
            ListTile(
              leading: const Icon(Icons.privacy_tip_outlined),
              title: Text(l10n.privacyPolicy),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.privacy!),
            ),
          if (meta?.urls?.terms != null)
            ListTile(
              leading: const Icon(Icons.description_outlined),
              title: Text(l10n.terms),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.terms!),
            ),
          if (meta?.urls?.support != null)
            ListTile(
              leading: const Icon(Icons.help_outline),
              title: Text(l10n.contactSupport),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => _open(context, meta!.urls!.support!),
            ),
        ],
      ),
    );
  }
}
