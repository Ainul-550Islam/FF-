import 'package:ffarena_mobile/core/l10n/app_localizations.dart';
import 'package:ffarena_mobile/widgets/async_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget _wrap(Widget child, {Locale locale = const Locale('en')}) {
  return MaterialApp(
    locale: locale,
    supportedLocales: AppLocalizations.supportedLocales,
    localizationsDelegates: AppLocalizations.localizationsDelegates,
    home: Scaffold(body: child),
  );
}

void main() {
  group('AppLocalizations', () {
    testWidgets('resolves English strings', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      })));

      expect(l10n.wallet, 'Wallet');
      expect(l10n.tournaments, 'Tournaments');
      expect(l10n.errorOffline, 'You are offline. Check your connection.');
    });

    testWidgets('resolves Bangla strings', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      }), locale: const Locale('bn')));

      expect(l10n.wallet, 'ওয়ালেট');
      expect(l10n.tournaments, 'টুর্নামেন্ট');
    });

    testWidgets('t() resolves Bangla and falls back for unknown keys',
        (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      }), locale: const Locale('bn')));

      // A known key resolves to the Bangla value.
      expect(l10n.t('appName'), 'এফএফ এরিনা');
      // An unknown key falls back to the key itself (never crashes).
      expect(l10n.t('definitely_missing_key'), 'definitely_missing_key');
    });

    testWidgets('formatMoney renders BDT in taka', (tester) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(_wrap(Builder(builder: (context) {
        l10n = AppLocalizations.of(context);
        return const SizedBox();
      })));

      expect(l10n.formatMoney(12345), contains('123.45'));
    });
  });

  group('AsyncView', () {
    testWidgets('shows the loading indicator', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: true,
        error: null,
        empty: false,
        onRetry: () {},
        child: const Text('item'),
      )));

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('shows the error state with retry', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: 'boom',
        empty: false,
        onRetry: () {},
        child: const SizedBox(),
      )));

      expect(find.text('boom'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('shows the empty state', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: null,
        empty: true,
        onRetry: () {},
        child: const SizedBox(),
      )));

      expect(find.text('Nothing here yet.'), findsOneWidget);
    });

    testWidgets('renders the child when there is content', (tester) async {
      await tester.pumpWidget(_wrap(AsyncView(
        loading: false,
        error: null,
        empty: false,
        onRetry: () {},
        child: const Text('item'),
      )));

      expect(find.text('item'), findsOneWidget);
    });
  });
}
