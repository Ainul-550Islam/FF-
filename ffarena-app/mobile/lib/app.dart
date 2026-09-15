import 'package:flutter/material.dart';

import 'config/app_config.dart';
import 'core/api/api_client.dart';
import 'core/cache/offline_cache.dart';
import 'core/l10n/app_localizations.dart';
import 'core/push/firebase_push_provider.dart';
import 'core/push/noop_push_provider.dart';
import 'core/push/push_provider.dart';
import 'core/push/push_service.dart';
import 'core/session/session_manager.dart';
import 'core/session/session_store.dart';
import 'core/storage/secure_storage.dart';
import 'core/telemetry/crash_reporter.dart';
import 'core/version/release_gate_controller.dart';
import 'core/version/version_gate.dart';
import 'data/repositories/app_meta_repository.dart';
import 'data/repositories/auth_repository.dart';
import 'data/repositories/device_repository.dart';
import 'data/repositories/dispute_repository.dart';
import 'data/repositories/leaderboard_repository.dart';
import 'data/repositories/live_repository.dart';
import 'data/repositories/match_repository.dart';
import 'data/repositories/notification_preference_repository.dart';
import 'data/repositories/notification_repository.dart';
import 'data/repositories/profile_repository.dart';
import 'data/repositories/security_repository.dart';
import 'data/repositories/support_repository.dart';
import 'data/repositories/team_repository.dart';
import 'data/repositories/tournament_repository.dart';
import 'data/repositories/wallet_repository.dart';
import 'features/deep_links/deep_link_router.dart';
import 'screens/auth/login_screen.dart';
import 'screens/gating/release_gate_screen.dart';
import 'screens/security/security_event_screen.dart';
import 'screens/shell/app_shell.dart';
import 'theme/app_theme.dart';

/// Composition root (Phase 18 §7, extended Phase 19). Wires the ApiClient,
/// SessionManager, push stack, release gate and all repositories together.
/// There is exactly one SessionManager and one ApiClient per process — every
/// repository shares them.
class AppServices {
  AppServices({
    required this.session,
    required this.api,
    required this.crash,
    required this.meta,
    required this.auth,
    required this.profile,
    required this.security,
    required this.tournaments,
    required this.teams,
    required this.matches,
    required this.leaderboard,
    required this.wallet,
    required this.notifications,
    required this.support,
    required this.disputes,
    required this.devices,
    required this.live,
    required this.push,
    required this.deepLinks,
    required this.preferences,
    required this.gate,
  });

  final SessionManager session;
  final ApiClient api;
  final CrashReporter crash;
  final AppMetaRepository meta;
  final AuthRepository auth;
  final ProfileRepository profile;
  final SecurityRepository security;
  final TournamentRepository tournaments;
  final TeamRepository teams;
  final MatchRepository matches;
  final LeaderboardRepository leaderboard;
  final WalletRepository wallet;
  final NotificationRepository notifications;
  final SupportRepository support;
  final DisputeRepository disputes;
  final DeviceRepository devices;
  final LiveRepository live;
  final PushService push;
  final DeepLinkRouter deepLinks;
  final NotificationPreferenceRepository preferences;
  final ReleaseGateController gate;

  /// Builds the full production graph. [secureStorage], [crashReporter] and
  /// [pushProvider] are injectable for tests; the defaults are the platform
  /// keystore, a debug-only reporter, and the Firebase provider (or an
  /// honest no-op when push is disabled/unconfigured).
  static AppServices create({
    SecureStorage? secureStorage,
    CrashReporter? crashReporter,
    PushProvider? pushProvider,
  }) {
    final config = AppConfig.instance;
    final crash = crashReporter ??
        (config.crashReportingEnabled
            ? const LogCrashReporter()
            : const NoopCrashReporter());
    final storage = secureStorage ?? PlatformSecureStorage();

    final api = ApiClient(
      baseUrl: config.apiBaseUrl,
      tokenProvider: () => _tokenHolder,
      crashReporter: crash,
    );

    final store = SessionStore(storage: storage);
    final push = PushService(
      api: api,
      provider: pushProvider ??
          (config.pushEnabled
              ? FirebasePushProvider()
              : const NoopPushProvider()),
    );
    final session = SessionManager(api: api, store: store, pushService: push);

    // Bind the token source after the session manager exists.
    _tokenHolder = session.token;

    final meta = AppMetaRepository(api: api);

    return AppServices(
      session: session,
      api: api,
      crash: crash,
      meta: meta,
      auth: AuthRepository(api: api),
      profile: ProfileRepository(api: api),
      security: SecurityRepository(api: api),
      tournaments: TournamentRepository(api: api),
      teams: TeamRepository(api: api),
      matches: MatchRepository(api: api),
      leaderboard: LeaderboardRepository(api: api),
      wallet: WalletRepository(api: api),
      notifications: NotificationRepository(api: api),
      support: SupportRepository(api: api),
      disputes: DisputeRepository(api: api),
      devices: DeviceRepository(api: api),
      live: LiveRepository(api: api),
      push: push,
      deepLinks: DeepLinkRouter(scheme: config.deepLinkScheme),
      preferences: NotificationPreferenceRepository(api: api),
      gate: ReleaseGateController(meta: meta),
    );
  }

  // Holds the current token for the ApiClient's token provider. Set right
  // after the SessionManager is constructed.
  static String? _tokenHolder;

  /// Boots shared state (offline cache). Call once before runApp.
  static Future<void> boot() async {
    await OfflineCache.instance.init(await AppConfig.cacheDirectory());
  }
}

/// Root widget. Routes between Login, the authenticated shell, the
/// security-event screen and the release gate (maintenance / mandatory
/// update) based on SessionManager and ReleaseGateController state.
class FFApp extends StatelessWidget {
  const FFApp({super.key, required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    final session = services.session;
    final gate = services.gate;

    return AnimatedBuilder(
      animation: session,
      builder: (context, _) {
        return AnimatedBuilder(
          animation: gate,
          builder: (context, _) {
            Widget home;
            if (session.restoring) {
              home = const Scaffold(
                body: Center(child: CircularProgressIndicator()),
              );
            } else if (session.endReason != null &&
                session.endReason!.isSecurityEvent) {
              home = SecurityEventScreen(services: services);
            } else if (!session.isAuthenticated) {
              home = LoginScreen(services: services);
            } else if (gate.gate == AppGate.maintenance ||
                gate.gate == AppGate.updateRequired) {
              home = ReleaseGateScreen(services: services);
            } else {
              home = AppShell(services: services);
            }

            return ValueListenableBuilder<Locale>(
              valueListenable: LocaleController.instance.locale,
              builder: (context, locale, _) {
                return MaterialApp(
                  title: AppConfig.instance.appName,
                  debugShowCheckedModeBanner: false,
                  theme: AppTheme.light(),
                  locale: locale,
                  supportedLocales: AppLocalizations.supportedLocales,
                  localizationsDelegates:
                      AppLocalizations.localizationsDelegates,
                  home: home,
                );
              },
            );
          },
        );
      },
    );
  }
}

/// Tiny locale holder so widgets outside the tree can flip locale.
class LocaleController {
  LocaleController._();

  static final LocaleController instance = LocaleController._();

  final ValueNotifier<Locale> locale = ValueNotifier(const Locale('en'));

  void set(String code) => locale.value = Locale(code);
}
