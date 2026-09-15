import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';

/// Push notification preferences (Phase 19 §10). Governs the push channel
/// only. Security alerts are always on (enforced server-side too).
class NotificationPreferencesScreen extends StatefulWidget {
  const NotificationPreferencesScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<NotificationPreferencesScreen> createState() =>
      _NotificationPreferencesScreenState();
}

class _NotificationPreferencesScreenState
    extends State<NotificationPreferencesScreen> {
  NotificationPreference? _prefs;
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final prefs = await widget.services.preferences.fetch();
      if (mounted) {
        setState(() => _prefs = prefs);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.localized(l10n));
      }
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _toggle(String category, bool value) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final updated =
          await widget.services.preferences.update({category: value});
      if (mounted) {
        setState(() => _prefs = updated);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.localized(l10n));
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final prefs = _prefs;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.notifPreferences)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(l10n.pushPrefsIntro,
                    style: Theme.of(context).textTheme.bodyMedium),
                const SizedBox(height: 16),
                if (prefs != null) ...[
                  _toggleTile(
                    l10n.prefTournament,
                    prefs.tournament ?? true,
                    (v) => _toggle('tournament', v),
                  ),
                  _toggleTile(
                    l10n.prefMatch,
                    prefs.match ?? true,
                    (v) => _toggle('match', v),
                  ),
                  _toggleTile(
                    l10n.prefTeam,
                    prefs.team ?? true,
                    (v) => _toggle('team', v),
                  ),
                  _toggleTile(
                    l10n.prefPayment,
                    prefs.payment ?? true,
                    (v) => _toggle('payment', v),
                  ),
                  _toggleTile(
                    l10n.prefPayout,
                    prefs.payout ?? true,
                    (v) => _toggle('payout', v),
                  ),
                  _toggleTile(
                    l10n.prefDispute,
                    prefs.dispute ?? true,
                    (v) => _toggle('dispute', v),
                  ),
                  _toggleTile(
                    l10n.prefSupport,
                    prefs.support ?? true,
                    (v) => _toggle('support', v),
                  ),
                  const Divider(),
                  SwitchListTile(
                    value: true,
                    onChanged: null, // security is always on
                    title: Text(l10n.prefSecurity),
                    subtitle: Text(l10n.prefSecurityLocked),
                    secondary: const Icon(Icons.shield_outlined),
                  ),
                ],
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      _error!,
                      textAlign: TextAlign.center,
                      style:
                          TextStyle(color: Theme.of(context).colorScheme.error),
                    ),
                  ),
              ],
            ),
    );
  }

  Widget _toggleTile(
    String title,
    bool value,
    ValueChanged<bool> onChanged,
  ) {
    return SwitchListTile(
      value: value,
      onChanged: _busy ? null : onChanged,
      title: Text(title),
    );
  }
}
