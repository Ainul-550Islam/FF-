import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/cache/offline_cache.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'tournament_detail_screen.dart';

/// Tournament list (Phase 18 §17): search, game-mode filter, pagination and
/// pull-to-refresh. The last successful page is cached read-only for offline
/// browsing, with an honest stale-data banner.
class TournamentListScreen extends StatefulWidget {
  const TournamentListScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<TournamentListScreen> createState() => _TournamentListScreenState();
}

class _TournamentListScreenState extends State<TournamentListScreen> {
  static const _cacheKey = 'tournaments.list.v1';

  List<Tournament> _items = const [];
  bool _loading = true;
  bool _offline = false;
  bool _stale = false;
  String? _error;
  final int _page = 1;
  String _query = '';
  String? _gameMode;

  @override
  void initState() {
    super.initState();
    _load(useCache: true);
  }

  Future<void> _load({bool useCache = false}) async {
    setState(() {
      _loading = true;
      _error = null;
    });

    if (useCache) {
      final cached = await OfflineCache.instance.get(_cacheKey);
      if (cached != null && cached.payload['items'] is List) {
        setState(() {
          _items = (cached.payload['items'] as List)
              .whereType<Map<String, dynamic>>()
              .map((m) => Tournament.fromJson(m))
              .toList(growable: false);
          _stale = cached.isStale;
          _loading = false;
        });
      }
    }

    try {
      final items = await widget.services.tournaments.list(
        page: _page,
        search: _query,
        gameMode: _gameMode,
      );
      await OfflineCache.instance.put(_cacheKey, {
        'items': items.map((t) => t.toJson()).toList(growable: false),
      });
      if (mounted) {
        setState(() {
          _items = items;
          _loading = false;
          _offline = false;
          _stale = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          if (e.code == ApiException.offline) {
            _offline = true;
            _stale = _items.isNotEmpty;
          } else {
            _error = e.localized(AppLocalizations.of(context));
          }
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = AppLocalizations.of(context).errorGeneric;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.tournaments)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    decoration: InputDecoration(
                      hintText: l10n.search,
                      prefixIcon: const Icon(Icons.search),
                      isDense: true,
                    ),
                    onSubmitted: (q) {
                      _query = q.trim();
                      _load();
                    },
                  ),
                ),
                const SizedBox(width: 8),
                DropdownButton<String?>(
                  value: _gameMode,
                  items: [
                    DropdownMenuItem(value: null, child: Text(l10n.all)),
                    const DropdownMenuItem(
                        value: 'squad', child: Text('Squad')),
                    const DropdownMenuItem(value: 'duo', child: Text('Duo')),
                    const DropdownMenuItem(value: 'solo', child: Text('Solo')),
                  ],
                  onChanged: (v) {
                    setState(() => _gameMode = v);
                    _load();
                  },
                ),
              ],
            ),
          ),
          if (_stale)
            Container(
              width: double.infinity,
              color: const Color(0xFFFFF3CD),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Text(
                _offline ? l10n.offlineBanner : l10n.staleBanner,
                style: const TextStyle(color: Colors.black87),
              ),
            ),
          Expanded(
            child: AsyncView(
              loading: _loading && _items.isEmpty,
              error: _error,
              empty: _items.isEmpty && !_loading,
              onRetry: () => _load(),
              child: RefreshIndicator(
                onRefresh: () => _load(),
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: _items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (context, i) => _TournamentCard(
                    tournament: _items[i],
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => TournamentDetailScreen(
                          services: widget.services,
                          tournamentId: _items[i].id,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TournamentCard extends StatelessWidget {
  const _TournamentCard({required this.tournament, required this.onTap});

  final Tournament tournament;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      tournament.name ?? '—',
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  if (tournament.status != null)
                    StatusPill(status: tournament.status!),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                '${(tournament.gameMode ?? '').toUpperCase()}'
                '${tournament.map != null ? ' · ${tournament.map}' : ''}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _Meta(
                    label: l10n.entryFee,
                    value: l10n.formatMoney(tournament.entryFeeMinor ?? 0),
                  ),
                  const SizedBox(width: 24),
                  _Meta(
                      label: l10n.prizePool,
                      value: tournament.prizePool ?? '—'),
                  const SizedBox(width: 24),
                  _Meta(
                      label: l10n.slotsLeft,
                      value: '${tournament.slotsLeft ?? 0}'),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant)),
        const SizedBox(height: 2),
        Text(value, style: Theme.of(context).textTheme.bodyMedium),
      ],
    );
  }
}
