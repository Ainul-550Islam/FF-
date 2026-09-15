import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import '../../widgets/status_pill.dart';

/// Match detail + score submission (Phase 18 §26/§27).
///
/// Room credentials come ONLY from the server's authorized Match resource and
/// are rendered without being logged or persisted. Score submission sends
/// only `{team_id, kills, placement}`.
class MatchDetailScreen extends StatefulWidget {
  const MatchDetailScreen({
    super.key,
    required this.services,
    required this.matchId,
  });

  final AppServices services;
  final int matchId;

  @override
  State<MatchDetailScreen> createState() => _MatchDetailScreenState();
}

class _MatchDetailScreenState extends State<MatchDetailScreen> {
  late Future<MatchModel> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.matches.get(widget.matchId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('${l10n.matchNo} ${widget.matchId}')),
      body: FutureBuilder<MatchModel>(
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
                  () => _future = widget.services.matches.get(widget.matchId)),
              child: const SizedBox(),
            );
          }
          final m = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${l10n.matchNo} ${m.matchNo ?? m.id ?? '—'}',
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
                  ),
                  if (m.status != null) StatusPill(status: m.status!),
                ],
              ),
              const SizedBox(height: 8),
              SectionCard(
                title: l10n.status,
                child: Column(
                  children: [
                    KeyValueRow(label: l10n.round, value: '${m.round ?? '—'}'),
                    KeyValueRow(
                        label: l10n.scheduled,
                        value: Dates.formatDateTime(m.scheduledAt)),
                    if (m.completedAt != null)
                      KeyValueRow(
                          label: l10n.completed,
                          value: Dates.formatDateTime(m.completedAt)),
                  ],
                ),
              ),
              if (m.roomId != null)
                SectionCard(
                  title: l10n.roomId,
                  child: Column(
                    children: [
                      KeyValueRow(label: l10n.roomId, value: m.roomId!),
                      if (m.roomPass != null)
                        KeyValueRow(label: l10n.roomPass, value: m.roomPass!),
                    ],
                  ),
                ),
              _ScoreSubmit(services: widget.services, match: m),
            ],
          );
        },
      ),
    );
  }
}

class _ScoreSubmit extends StatefulWidget {
  const _ScoreSubmit({required this.services, required this.match});

  final AppServices services;
  final MatchModel match;

  @override
  State<_ScoreSubmit> createState() => _ScoreSubmitState();
}

class _ScoreSubmitState extends State<_ScoreSubmit> {
  List<Team> _teams = const [];
  int? _teamId;
  final _kills = TextEditingController();
  final _placement = TextEditingController();
  bool _busy = false;
  String? _result;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.services.teams.mine().then((teams) {
      if (mounted) {
        setState(() {
          _teams = teams;
          if (teams.isNotEmpty) {
            _teamId = teams.first.id;
          }
        });
      }
    });
  }

  @override
  void dispose() {
    _kills.dispose();
    _placement.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    final kills = int.tryParse(_kills.text.trim()) ?? 0;
    final placement = int.tryParse(_placement.text.trim()) ?? 0;
    if (_teamId == null || placement <= 0) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.services.matches.submitScore(
        matchId: widget.match.id!,
        teamId: _teamId!,
        kills: kills,
        placement: placement,
      );
      setState(() => _result = l10n.scoreSubmitted);
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
    return SectionCard(
      title: l10n.submitScore,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          DropdownButtonFormField<int>(
            initialValue: _teamId,
            decoration: InputDecoration(labelText: l10n.team),
            items: [
              for (final t in _teams)
                DropdownMenuItem(value: t.id, child: Text(t.name ?? '—')),
            ],
            onChanged: (v) => setState(() => _teamId = v),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _kills,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: l10n.kills),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  controller: _placement,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: l10n.placement),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : Text(l10n.submitScore),
          ),
          if (_result != null) ...[
            const SizedBox(height: 8),
            Text(_result!, textAlign: TextAlign.center),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
