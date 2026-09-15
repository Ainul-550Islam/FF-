import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';

/// In-app notifications (Phase 18 §28).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late Future<List<NotificationModel>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.notifications.list();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.notifications),
        actions: [
          TextButton(
            onPressed: () async {
              await widget.services.notifications.markAllRead();
              if (mounted) {
                setState(() => _future = widget.services.notifications.list());
              }
            },
            child: Text(l10n.markAllRead),
          ),
        ],
      ),
      body: FutureBuilder<List<NotificationModel>>(
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
                  () => _future = widget.services.notifications.list()),
              child: const SizedBox(),
            );
          }
          final items = snapshot.data ?? const <NotificationModel>[];
          if (items.isEmpty) {
            return Center(child: Text(l10n.noNotifications));
          }
          return ListView.separated(
            itemCount: items.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final n = items[i];
              return ListTile(
                leading: Icon(
                  n.read == true
                      ? Icons.notifications_none
                      : Icons.notifications,
                ),
                title: Text(n.title ?? ''),
                subtitle: n.body != null ? Text(n.body!) : null,
                onTap: () async {
                  if (n.read != true) {
                    await widget.services.notifications.markRead(n.id!);
                    if (mounted) {
                      setState(
                          () => _future = widget.services.notifications.list());
                    }
                  }
                },
              );
            },
          );
        },
      ),
    );
  }
}
