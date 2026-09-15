import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/avatar.dart';

/// Team roster (Phase 18 §21). Add/remove are authorized server-side.
class RosterScreen extends StatefulWidget {
  const RosterScreen({super.key, required this.services, required this.teamId});

  final AppServices services;
  final int teamId;

  @override
  State<RosterScreen> createState() => _RosterScreenState();
}

class _RosterScreenState extends State<RosterScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.roster(widget.teamId);
  }

  Future<void> _add() async {
    final l10n = AppLocalizations.of(context);
    final name = TextEditingController();
    final uid = TextEditingController();
    final result = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.addMember),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: name,
              decoration: InputDecoration(labelText: l10n.memberName),
            ),
            TextField(
              controller: uid,
              decoration: InputDecoration(labelText: l10n.gameUid),
            ),
          ],
        ),
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
    if (result != true) {
      return;
    }
    try {
      await widget.services.teams
          .addMember(widget.teamId, name.text.trim(), uid.text.trim());
      if (mounted) {
        setState(() => _future = widget.services.teams.roster(widget.teamId));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    }
  }

  Future<void> _remove(int memberId) async {
    final l10n = AppLocalizations.of(context);
    try {
      await widget.services.teams.removeMember(widget.teamId, memberId);
      if (mounted) {
        setState(() => _future = widget.services.teams.roster(widget.teamId));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.localized(l10n))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.roster)),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.person_add),
        label: Text(l10n.addMember),
        onPressed: _add,
      ),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final message = snapshot.error is ApiException
                ? (snapshot.error as ApiException).localized(l10n)
                : l10n.errorGeneric;
            return AsyncView(
              loading: false,
              error: message,
              empty: false,
              onRetry: () => setState(
                  () => _future = widget.services.teams.roster(widget.teamId)),
              child: const SizedBox(),
            );
          }
          final members = snapshot.data ?? const <Map<String, dynamic>>[];
          if (members.isEmpty) {
            return Center(child: Text(l10n.empty));
          }
          return ListView.separated(
            itemCount: members.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final m = members[i];
              final id = m['id'];
              return ListTile(
                leading: Avatar(name: (m['player_name'] as String?) ?? '?'),
                title: Text((m['player_name'] as String?) ?? '—'),
                subtitle: Text('UID: ${m['game_uid'] ?? '—'}'),
                trailing: id is int
                    ? IconButton(
                        icon: const Icon(Icons.person_remove_outlined),
                        onPressed: () => _remove(id),
                      )
                    : null,
              );
            },
          );
        },
      ),
    );
  }
}
