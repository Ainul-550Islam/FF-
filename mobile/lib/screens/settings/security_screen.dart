import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/app_localizations.dart';
import '../../widgets/async_view.dart';
import '../../widgets/key_value_row.dart';
import '../../widgets/section_card.dart';

/// Account security (Phase 18 §61): sign-in methods and account status from
/// GET /me/security — the server never exposes internal signals here.
class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.services.security.security();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.accountSecurity)),
      body: FutureBuilder<Map<String, dynamic>>(
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
                  setState(() => _future = widget.services.security.security()),
              child: const SizedBox(),
            );
          }
          final s = snapshot.data ?? const {};
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: l10n.securityStatus,
                child: Column(
                  children: [
                    KeyValueRow(
                      label: l10n.email,
                      value: (s['email'] as String?) ?? '—',
                    ),
                    KeyValueRow(
                      label: l10n.accountStatus,
                      value: (s['account_status'] as String?) ?? '—',
                    ),
                    KeyValueRow(
                      label: l10n.email,
                      value: s['email_verified'] == true
                          ? l10n.emailVerified
                          : l10n.emailNotVerified,
                    ),
                  ],
                ),
              ),
              SectionCard(
                title: l10n.signInMethods,
                child: Column(
                  children: [
                    KeyValueRow(
                      label: l10n.google,
                      value: _methodLabel(s, 'google', l10n),
                    ),
                    KeyValueRow(
                      label: l10n.phoneMethod,
                      value: _methodLabel(s, 'phone', l10n),
                    ),
                    KeyValueRow(
                      label: l10n.emailMethod,
                      value: s['has_password'] == true
                          ? l10n.hasPassword
                          : l10n.noPassword,
                    ),
                  ],
                ),
              ),
              SectionCard(
                title: l10n.changePasswordWebOnly,
                child: Text(l10n.changePasswordWebBody),
              ),
              SectionCard(
                title: l10n.deactivationWebOnly,
                child: Text(l10n.deactivationWebBody),
              ),
            ],
          );
        },
      ),
    );
  }

  String _methodLabel(
      Map<String, dynamic> s, String method, AppLocalizations l10n) {
    final methods = s['sign_in_methods'];
    if (methods is Map<String, dynamic>) {
      final count = methods[method];
      return count == null || count == 0 ? '—' : l10n.active;
    }
    return '—';
  }
}
