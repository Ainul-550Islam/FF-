import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';
import 'roster_screen.dart';

/// Team detail (Phase 18 §21). Captain-only actions (roster edit, withdraw)
/// are authorized by the server; the client shows them and surfaces the
/// server's 403 honestly.
class TeamDetailScreen extends StatefulWidget {
  const TeamDetailScreen(
      {super.key, required this.services, required this.team});

  final AppServices services;
  final Team team;

  @override
  State<TeamDetailScreen> createState() => _TeamDetailScreenState();
}

class _TeamDetailScreenState extends State<TeamDetailScreen> {
  Team get team => widget.team;
  String? _error;

  Future<void> _withdraw() async {
    final l10n = AppLocalizations.of(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.confirmWithdraw),
        content: Text(l10n.withdrawBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(l10n.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.confirm),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      return;
    }
    try {
      await widget.services.teams.withdraw(team.id!);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(team.name ?? l10n.team)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  team.name ?? '—',
                  style: Theme.of(context)
                      .textTheme
                      .headlineSmall
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              if (team.status != null) StatusPill(status: team.status!),
            ],
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: l10n.team,
            child: Column(
              children: [
                KeyValueRow(
                    label: l10n.captainName, value: team.captainName ?? '—'),
                KeyValueRow(label: l10n.gameUid, value: team.gameUid ?? '—'),
                if (team.waitlistPosition != null)
                  KeyValueRow(
                      label: l10n.waitlistPositionLabel,
                      value: '${team.waitlistPosition}'),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: FilledButton.tonalIcon(
              icon: const Icon(Icons.groups_outlined),
              label: Text(l10n.roster),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => RosterScreen(
                    services: widget.services,
                    teamId: team.id!,
                  ),
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: OutlinedButton(
              onPressed: _withdraw,
              child: Text(l10n.withdraw),
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(8),
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
