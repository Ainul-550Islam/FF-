import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/cache/offline_cache.dart';
import '../../core/l10n/app_localizations.dart';
import '../auth/login_screen.dart';

/// First-run onboarding (Phase 18 §5). Three value-prop pages, then Login.
/// Nothing here requires a network call or a session.
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.services});

  final AppServices services;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  static const _seenKey = 'onboarding.seen';

  final _controller = PageController();
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _finish() async {
    await OfflineCache.instance.put(_seenKey, {'seen': true});
    if (!mounted) {
      return;
    }
    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(
        builder: (_) => LoginScreen(services: widget.services),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final pages = [
      (
        Icons.emoji_events_outlined,
        l10n.onboardingWelcomeTitle,
        l10n.onboardingWelcomeBody
      ),
      (
        Icons.groups_outlined,
        l10n.onboardingPlayTitle,
        l10n.onboardingPlayBody
      ),
      (Icons.trending_up, l10n.onboardingWinTitle, l10n.onboardingWinBody),
    ];

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: PageView.builder(
                controller: _controller,
                itemCount: pages.length,
                onPageChanged: (i) => setState(() => _page = i),
                itemBuilder: (context, i) {
                  final (icon, title, body) = pages[i];
                  return Padding(
                    padding: const EdgeInsets.all(32),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(icon,
                            size: 96,
                            color: Theme.of(context).colorScheme.primary),
                        const SizedBox(height: 32),
                        Text(
                          title,
                          textAlign: TextAlign.center,
                          style: Theme.of(context)
                              .textTheme
                              .headlineSmall
                              ?.copyWith(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 12),
                        Text(
                          body,
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodyLarge,
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(pages.length, (i) {
                final active = i == _page;
                return AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  width: active ? 24 : 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: active
                        ? Theme.of(context).colorScheme.primary
                        : Theme.of(context).colorScheme.outlineVariant,
                    borderRadius: BorderRadius.circular(4),
                  ),
                );
              }),
            ),
            const SizedBox(height: 24),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              child: FilledButton(
                onPressed: _finish,
                child: Text(l10n.getStarted),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
