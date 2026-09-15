import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';

/// Privacy preset (Phase 18 §61). The server validates the preset and is
/// authoritative for what other users can see.
class PrivacyScreen extends StatefulWidget {
  const PrivacyScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends State<PrivacyScreen> {
  bool _busy = false;
  String? _error;

  Future<void> _set(String preset) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final me = await widget.services.profile.update(privacy: preset);
      await widget.services.session.refreshUser(me);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(l10n.saved)));
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final current = widget.services.session.me?.privacy ?? 'public';
    return Scaffold(
      appBar: AppBar(title: Text(l10n.privacy)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          RadioGroup<String>(
            groupValue: current,
            onChanged: (v) {
              if (!_busy && v != null) {
                _set(v);
              }
            },
            child: Column(
              children: [
                for (final (value, label) in [
                  ('public', l10n.privacyPublic),
                  ('registered', l10n.privacyRegistered),
                  ('private', l10n.privacyPrivate),
                ])
                  RadioListTile<String>(
                    title: Text(label),
                    value: value,
                  ),
              ],
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                _error!,
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }
}
