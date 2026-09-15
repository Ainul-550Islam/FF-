import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/format/dates.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// Active sessions (Phase 18 §61): revoke one / others / all.
class SessionsScreen extends StatefulWidget {
  const SessionsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SessionsScreen> createState() => _SessionsScreenState();
}

class _SessionsScreenState extends State<SessionsScreen> {
  late Future<List<ApiSession>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.security.sessions();
  }

  Future<void> _reload() async {
    setState(() => _future = widget.services.security.sessions());
    await _future;
  }

  Future<void> _revoke(String id) async {
    await widget.services.security.revokeSession(id);
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.sessions),
        actions: [
          TextButton(
            onPressed: () async {
              await widget.services.security.revokeOtherSessions();
              await _reload();
            },
            child: Text(l10n.revokeOthers),
          ),
        ],
      ),
      body: FutureBuilder<List<ApiSession>>(
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
              onRetry: _reload,
              child: const SizedBox(),
            );
          }
          final sessions = snapshot.data ?? const <ApiSession>[];
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: OutlinedButton(
                  onPressed: () async {
                    await widget.services.security.revokeAllSessions();
                    if (mounted) {
                      await _reload();
                    }
                  },
                  child: Text(l10n.revokeAll),
                ),
              ),
              Expanded(
                child: ListView.separated(
                  itemCount: sessions.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final s = sessions[i];
                    return ListTile(
                      title: Text(s.deviceLabel ?? l10n.sessions),
                      subtitle: Text(
                        '${l10n.lastActive}: ${Dates.formatDateTime(s.lastActivity)}',
                      ),
                      trailing: s.isCurrent == true
                          ? Text(l10n.currentSession)
                          : IconButton(
                              icon: const Icon(Icons.logout),
                              onPressed: () => _revoke(s.id!),
                            ),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
