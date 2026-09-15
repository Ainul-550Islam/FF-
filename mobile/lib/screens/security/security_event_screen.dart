import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/l10n/app_localizations.dart';
import '../../core/session/session_manager.dart';

/// Security / account-state screen (Phase 18 §9/§60).
///
/// Shown whenever the server ends a session — expired/revoked token or a
/// deactivated account. Explains WHY and offers re-login; for deactivated
/// accounts it points to support without assuming any ability the client
/// does not have.
class SecurityEventScreen extends StatelessWidget {
  const SecurityEventScreen({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final reason =
        services.session.endReason ?? SessionEndReason.unauthenticated;

    final (icon, title, body) = switch (reason) {
      SessionEndReason.accountInactive => (
          Icons.block,
          l10n.accountInactiveTitle,
          l10n.accountInactiveBody,
        ),
      SessionEndReason.tokenRevoked => (
          Icons.logout,
          l10n.securityEventTitle,
          l10n.tokenRevokedBody,
        ),
      _ => (
          Icons.timer_off_outlined,
          l10n.securityEventTitle,
          l10n.sessionExpiredBody,
        ),
    };

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(icon,
                      size: 64, color: Theme.of(context).colorScheme.error),
                  const SizedBox(height: 16),
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .titleLarge
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  Text(body, textAlign: TextAlign.center),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: () =>
                        services.session.acknowledgeSecurityEvent(),
                    child: Text(l10n.loginAgain),
                  ),
                  if (reason == SessionEndReason.accountInactive) ...[
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: () {
                        // A deactivated account cannot authenticate, so the
                        // in-app support surface is unavailable. Point to the
                        // configured support channel instead.
                        final supportUrl = services.meta.cached?.urls?.support;
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(
                              supportUrl ?? l10n.contactSupport,
                            ),
                          ),
                        );
                      },
                      child: Text(l10n.contactSupport),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
