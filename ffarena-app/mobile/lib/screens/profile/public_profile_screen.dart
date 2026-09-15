import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/avatar.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';
import 'ranking_screen.dart';

/// Public player profile (Phase 18 §59). Renders the documented UserProfile;
/// privacy/visibility are server decisions.
class PublicProfileScreen extends StatefulWidget {
  const PublicProfileScreen({
    super.key,
    required this.services,
    required this.userId,
  });

  final AppServices services;
  final int userId;

  @override
  State<PublicProfileScreen> createState() => _PublicProfileScreenState();
}

class _PublicProfileScreenState extends State<PublicProfileScreen> {
  late Future<UserProfile> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.profile.player(widget.userId);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.publicProfile)),
      body: FutureBuilder<UserProfile>(
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
              onRetry: () => setState(() =>
                  _future = widget.services.profile.player(widget.userId)),
              child: const SizedBox(),
            );
          }
          final p = snapshot.data!;
          if (p.visible == false) {
            return Center(child: Text(l10n.privacyPrivate));
          }
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Center(
                child: Avatar(
                  name: p.name ?? p.username ?? '?',
                  radius: 40,
                ),
              ),
              const SizedBox(height: 8),
              Center(
                child: Text(
                  p.name ?? '—',
                  style: Theme.of(context)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
              if (p.username != null)
                Center(
                  child: Text('@${p.username}',
                      style: Theme.of(context).textTheme.bodyMedium),
                ),
              const SizedBox(height: 16),
              SectionCard(
                title: l10n.profile,
                child: Column(
                  children: [
                    if (p.privacy != null)
                      KeyValueRow(label: l10n.privacy, value: p.privacy!),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => RankingScreen(
                        services: widget.services,
                        userId: widget.userId,
                      ),
                    ),
                  ),
                  child: Text(l10n.leaderboard),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
