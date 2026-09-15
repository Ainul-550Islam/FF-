import 'package:ffarena_mobile/features/deep_links/deep_link_router.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('parseDeepLink', () {
    test('parses tournament link', () {
      final link = parseDeepLink(Uri.parse('ffarena://tournament/42'));
      expect(link, isNotNull);
      expect(link!.target, DeepLinkTarget.tournament);
      expect(link.id, 42);
    });

    test('parses match link', () {
      final link = parseDeepLink(Uri.parse('ffarena://match/7'));
      expect(link!.target, DeepLinkTarget.match);
      expect(link.id, 7);
    });

    test('parses profile link', () {
      final link = parseDeepLink(Uri.parse('ffarena://profile/99'));
      expect(link!.target, DeepLinkTarget.profile);
    });

    test('parses the extended Phase 19 targets', () {
      expect(parseDeepLink(Uri.parse('ffarena://leaderboard/3'))!.target,
          DeepLinkTarget.leaderboard);
      expect(parseDeepLink(Uri.parse('ffarena://support/12'))!.target,
          DeepLinkTarget.support);
      expect(parseDeepLink(Uri.parse('ffarena://dispute/8'))!.target,
          DeepLinkTarget.dispute);
      expect(parseDeepLink(Uri.parse('ffarena://payment/5'))!.target,
          DeepLinkTarget.payment);
      expect(parseDeepLink(Uri.parse('ffarena://payout/2'))!.target,
          DeepLinkTarget.payout);
    });

    test('parses the id-less security target', () {
      final link = parseDeepLink(Uri.parse('ffarena://security'));
      expect(link!.target, DeepLinkTarget.security);
      expect(link.id, isNull);
    });

    test('rejects wrong scheme', () {
      expect(
          parseDeepLink(Uri.parse('https://example.com/tournament/1')), isNull);
    });

    test('rejects missing id', () {
      expect(parseDeepLink(Uri.parse('ffarena://tournament')), isNull);
      expect(parseDeepLink(Uri.parse('ffarena://tournament/abc')), isNull);
    });

    test('rejects unknown targets', () {
      expect(parseDeepLink(Uri.parse('ffarena://wallet/1')), isNull);
    });

    test('never surfaces embedded secrets as routable data', () {
      // Query parameters (e.g. an attacker-injected token) are ignored.
      final link = parseDeepLink(Uri.parse('ffarena://match/3?token=secret'));
      expect(link!.id, 3);
      expect(link.raw, isNot(contains('secret')));
      expect(link.raw, 'ffarena://match/3');
    });
  });

  group('parseWebLink', () {
    test('maps App Link / Universal Link paths to targets', () {
      expect(
        parseWebLink(Uri.parse('https://ffarena.example.com/tournaments/4'),
                webBase: 'https://ffarena.example.com')!
            .target,
        DeepLinkTarget.tournament,
      );
      expect(
        parseWebLink(Uri.parse('https://ffarena.example.com/matches/7'),
                webBase: 'https://ffarena.example.com')!
            .target,
        DeepLinkTarget.match,
      );
      expect(
        parseWebLink(Uri.parse('https://ffarena.example.com/players/99'),
                webBase: 'https://ffarena.example.com')!
            .target,
        DeepLinkTarget.profile,
      );
      expect(
        parseWebLink(Uri.parse('https://ffarena.example.com/leaderboards/3'),
                webBase: 'https://ffarena.example.com')!
            .target,
        DeepLinkTarget.leaderboard,
      );
    });

    test('rejects a different host (never trusts foreign origins)', () {
      expect(
        parseWebLink(Uri.parse('https://evil.example.com/matches/7'),
            webBase: 'https://ffarena.example.com'),
        isNull,
      );
    });

    test('rejects when no web base is configured', () {
      expect(
        parseWebLink(Uri.parse('https://ffarena.example.com/matches/7')),
        isNull,
      );
    });

    test('rejects non-numeric slugs (server uses ids in the app)', () {
      expect(
        parseWebLink(
            Uri.parse('https://ffarena.example.com/tournaments/summer-cup'),
            webBase: 'https://ffarena.example.com'),
        isNull,
      );
    });
  });

  group('DeepLinkRouter', () {
    test('routes recognized scheme links to the handler and ignores others',
        () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      router.route('ffarena://tournament/1');
      router.route('ffarena://match/2');
      router.route('ffarena://security');
      router.route('other://tournament/3');
      router.route('ffarena://nope/4');

      expect(seen.length, 3);
      expect(seen[0].target, DeepLinkTarget.tournament);
      expect(seen[1].target, DeepLinkTarget.match);
      expect(seen[2].target, DeepLinkTarget.security);
    });

    test('routes web links when the base origin matches', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      router.webBase = 'https://ffarena.example.com';
      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      router.route('https://ffarena.example.com/matches/9');
      router.route('https://evil.example.com/matches/9');

      expect(seen.length, 1);
      expect(seen[0].target, DeepLinkTarget.match);
      expect(seen[0].id, 9);
    });

    test('unknown links never crash and never call the handler', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      var called = 0;
      router.registerHandler((_) => called++);

      router.route('not a uri at all');
      router.route('ffarena://unknown/1');
      router.route('');

      expect(called, 0);
    });

    test('queues links that arrive before a handler is registered', () {
      final router = DeepLinkRouter(scheme: 'ffarena');

      // Cold-start App Link lands while the session is still restoring: no
      // handler yet. The link must be queued, not dropped.
      router.route('ffarena://tournament/42');
      router.route('ffarena://match/7');

      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      // Nothing delivered until the shell flushes.
      expect(seen, isEmpty);

      router.flushPending();

      // Latest link wins (the queue holds one pending entry).
      expect(seen.length, 1);
      expect(seen[0].target, DeepLinkTarget.match);
      expect(seen[0].id, 7);
    });

    test('flushPending is a no-op when nothing is queued', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      router.flushPending();

      expect(seen, isEmpty);
    });

    test('delivers directly once a handler is registered', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final seen = <DeepLink>[];
      router.registerHandler(seen.add);

      router.route('ffarena://tournament/1');
      router.flushPending();

      expect(seen.length, 1);
      expect(seen[0].id, 1);
    });

    test('queues links that arrive while logged out (handler unregistered)',
        () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final seen = <DeepLink>[];

      // First session: shell registers, then logs out (unregisters).
      router.registerHandler((_) {});
      router.unregisterHandler();

      // A link arrives while logged out: queued, never dropped.
      router.route('ffarena://payment/9');

      // Next login: a fresh shell registers and flushes.
      router.registerHandler(seen.add);
      router.flushPending();

      expect(seen.length, 1);
      expect(seen[0].target, DeepLinkTarget.payment);
      expect(seen[0].id, 9);
    });

    test('unregisterHandler clears a stale handler', () {
      final router = DeepLinkRouter(scheme: 'ffarena');
      final stale = <DeepLink>[];
      router.registerHandler(stale.add);
      router.unregisterHandler();

      router.route('ffarena://tournament/3');

      // The stale handler must not be called; the link is queued instead.
      expect(stale, isEmpty);

      final fresh = <DeepLink>[];
      router.registerHandler(fresh.add);
      router.flushPending();
      expect(fresh.length, 1);
    });
  });
}
