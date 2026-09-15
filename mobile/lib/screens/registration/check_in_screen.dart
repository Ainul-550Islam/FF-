import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';

/// Tournament check-in (Phase 18 §19). The user picks one of their own
/// teams; the server is authoritative for check-in eligibility and result.
class CheckInScreen extends StatefulWidget {
  const CheckInScreen({
    super.key,
    required this.services,
    required this.tournamentId,
  });

  final AppServices services;
  final int tournamentId;

  @override
  State<CheckInScreen> createState() => _CheckInScreenState();
}

class _CheckInScreenState extends State<CheckInScreen> {
  late Future<List<Team>> _future;
  bool _busy = false;
  String? _result;
  String? _error;

  @override
  void initState() {
    super.initState();
    _future = widget.services.teams.mine();
  }

  Future<void> _checkIn(int teamId) async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.tournaments.checkIn(widget.tournamentId, teamId);
      setState(() => _result = l10n.checkedIn);
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
    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkIn)),
      body: FutureBuilder<List<Team>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final teams = snapshot.data ?? const <Team>[];
          if (teams.isEmpty) {
            return Center(child: Text(l10n.noTeams));
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              for (final team in teams)
                Card(
                  child: ListTile(
                    title: Text(team.name ?? '—'),
                    subtitle: Text('${l10n.team} #${team.id}'),
                    trailing: FilledButton(
                      onPressed: _busy ? null : () => _checkIn(team.id!),
                      child: Text(l10n.checkIn),
                    ),
                  ),
                ),
              if (_result != null)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    _result!,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
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
          );
        },
      ),
    );
  }
}
