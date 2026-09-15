import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/status_pill.dart';
import 'support_chat_screen.dart';
import 'support_create_screen.dart';

/// Support tickets (Phase 18 §25).
class SupportTicketsScreen extends StatefulWidget {
  const SupportTicketsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SupportTicketsScreen> createState() => _SupportTicketsScreenState();
}

class _SupportTicketsScreenState extends State<SupportTicketsScreen> {
  late Future<List<SupportTicket>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.support.tickets();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.support)),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add),
        label: Text(l10n.createTicket),
        onPressed: () async {
          await Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => SupportCreateScreen(services: widget.services),
            ),
          );
          if (mounted) {
            setState(() => _future = widget.services.support.tickets());
          }
        },
      ),
      body: FutureBuilder<List<SupportTicket>>(
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
              onRetry: () =>
                  setState(() => _future = widget.services.support.tickets()),
              child: const SizedBox(),
            );
          }
          final tickets = snapshot.data ?? const <SupportTicket>[];
          if (tickets.isEmpty) {
            return Center(child: Text(l10n.noTickets));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: tickets.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, i) {
              final t = tickets[i];
              return Card(
                child: ListTile(
                  title: Text(t.subject ?? '—'),
                  subtitle: Text('${t.category ?? ''} · ${t.priority ?? ''}'),
                  trailing:
                      t.status != null ? StatusPill(status: t.status!) : null,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => SupportChatScreen(
                        services: widget.services,
                        ticketId: t.id!,
                      ),
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
