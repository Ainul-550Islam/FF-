import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/phone.dart';
import '../../core/l10n/app_localizations.dart';

/// Tournament team registration (Phase 18 §19/§26).
///
/// Submits the documented `{name, captain_name, phone, game_uid, members}`
/// and trusts the server's `waitlisted` / `waitlist_position` / `next_step`
/// verdict — the client never computes slot availability or waitlist math.
class TeamRegisterScreen extends StatefulWidget {
  const TeamRegisterScreen({
    super.key,
    required this.services,
    required this.tournament,
  });

  final AppServices services;
  final Tournament tournament;

  @override
  State<TeamRegisterScreen> createState() => _TeamRegisterScreenState();
}

class _TeamRegisterScreenState extends State<TeamRegisterScreen> {
  final _name = TextEditingController();
  final _captain = TextEditingController();
  final _phone = TextEditingController();
  final _gameUid = TextEditingController();
  final _memberNames = List.generate(3, (_) => TextEditingController());
  final _memberUids = List.generate(3, (_) => TextEditingController());

  bool _busy = false;
  String? _error;
  String? _outcome;

  @override
  void dispose() {
    _name.dispose();
    _captain.dispose();
    _phone.dispose();
    _gameUid.dispose();
    for (final c in [..._memberNames, ..._memberUids]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    final phone = Phone.normalizeBd(_phone.text);
    if (phone == null) {
      setState(() => _error = l10n.phone);
      return;
    }
    final members = <Map<String, String>>[];
    for (var i = 0; i < 3; i++) {
      final playerName = _memberNames[i].text.trim();
      final gameUid = _memberUids[i].text.trim();
      if (playerName.isNotEmpty || gameUid.isNotEmpty) {
        members.add({'player_name': playerName, 'game_uid': gameUid});
      }
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.services.tournaments.register(
        tournamentId: widget.tournament.id!,
        name: _name.text.trim(),
        captainName: _captain.text.trim(),
        phone: phone,
        gameUid: _gameUid.text.trim(),
        members: members,
      );
      final waitlisted = result['waitlisted'] == true;
      final position = result['waitlist_position'];
      setState(() {
        _outcome = waitlisted
            ? '${l10n.teamIsWaitlisted}'
                '${position != null ? ' ${l10n.waitlistPositionLabel}: $position' : ''}'
            : l10n.registered;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.localized(l10n));
    } catch (_) {
      setState(() => _error = l10n.errorGeneric);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final memberFields = <Widget>[];
    for (var i = 0; i < 3; i++) {
      memberFields.addAll([
        Text('${l10n.members} ${i + 1}'),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _memberNames[i],
                decoration: InputDecoration(labelText: l10n.memberName),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _memberUids[i],
                decoration: InputDecoration(labelText: l10n.gameUid),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
      ]);
    }

    return Scaffold(
      appBar: AppBar(title: Text(l10n.register)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  controller: _name,
                  decoration: InputDecoration(labelText: l10n.teamName),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _captain,
                  decoration: InputDecoration(labelText: l10n.captainName),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: l10n.phone,
                    prefixText: '+880 ',
                  ),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _gameUid,
                  autocorrect: false,
                  decoration: InputDecoration(labelText: l10n.gameUid),
                ),
                const SizedBox(height: 24),
                ...memberFields,
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(l10n.register),
                ),
                if (_outcome != null) ...[
                  const SizedBox(height: 16),
                  Text(
                    _outcome!,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    textAlign: TextAlign.center,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
