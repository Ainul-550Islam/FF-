import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../core/version/version_gate.dart';

/// Blocks the app during maintenance or a mandatory update (Phase 19 §22/§23).
///
/// The server is authoritative: it decides when maintenance is active and
/// when an update is required. This screen only communicates that state and
/// keeps logout/security access available. It never force-updates on its own
/// and never trusts client-side version math.
class ReleaseGateScreen extends StatelessWidget {
  const ReleaseGateScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final gate = services.gate;
    final maintenance = gate.gate == AppGate.maintenance;

    final title =
        maintenance ? l10n.maintenanceTitle : l10n.updateRequiredTitle;
    final body = maintenance
        ? (gate.message.isNotEmpty ? gate.message : l10n.maintenanceTitle)
        : l10n.updateRequiredBody;

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  maintenance ? Icons.construction : Icons.system_update,
                  size: 72,
                  color: Theme.of(context).colorScheme.primary,
                ),
                const SizedBox(height: 24),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                Text(
                  body,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: 32),
                if (!maintenance)
                  FilledButton.icon(
                    onPressed: () => _openStore(context),
                    icon: const Icon(Icons.open_in_new),
                    label: Text(l10n.openStore),
                  ),
                const SizedBox(height: 12),
                OutlinedButton(
                  onPressed: () => services.gate.refresh(),
                  child: Text(l10n.checkAgain),
                ),
                const SizedBox(height: 12),
                TextButton(
                  onPressed: () => services.session.logout(),
                  child: Text(l10n.logout),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _openStore(BuildContext context) async {
    final url = services.gate.storeUrl;
    if (url == null || url.isEmpty) {
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null) {
      return;
    }
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }
}
