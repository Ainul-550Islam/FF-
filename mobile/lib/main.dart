import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app.dart';
import 'config/app_config.dart';
import 'core/api/api_exception.dart';
import 'core/app_lifecycle.dart';
import 'core/telemetry/crash_reporter.dart';

/// MethodChannel the native hosts (Android/iOS) forward deep-link URIs on.
/// The router logic itself is pure Dart and unit tested; this channel is the
/// thin OS boundary documented in docs/MOBILE_DEEP_LINKS.md.
const MethodChannel _deepLinkChannel =
    MethodChannel('ffarena.deeplink/channel');

/// Application entry point (Phase 18 §6, extended Phase 19 §45).
///
/// Startup order: load minimal local state, render the shell, then refresh
/// auth metadata, check the release gate, register/refresh the push token
/// and load home data. Nothing blocks on optional telemetry.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Release builds must never silently point at a placeholder API host.
  if (AppConfig.instance.isProduction && !AppConfig.instance.isApiBaseUrlSane) {
    throw StateError(
      'Production build requires FFARENA_API_BASE_URL starting with https://',
    );
  }

  await AppServices.boot();

  final services = AppServices.create();

  // Report uncaught framework/isolate errors before any widget runs.
  installGlobalErrorHandlers(services.crash);

  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
  ]);

  // Push: initialize the provider (no-op when unconfigured) and wire taps to
  // the deep-link router.
  services.push.onDeepLink = services.deepLinks.route;
  await services.push.initialize();

  runApp(FFApp(services: services));

  // Wire the deep-link router for cold-start / warm-start URIs and refresh
  // the server web-base origin (for App/Universal Link parsing).
  unawaited(_routeInitialDeepLink(services));

  // Restore the persisted session in the background; the root widget shows a
  // spinner until SessionManager.restoring is false.
  unawaited(services.session.restore());

  // Server-driven release gate (version / maintenance). Also refresh on
  // resume so a maintenance window or forced update is picked up quickly.
  unawaited(services.gate.refresh());
  final lifecycle = AppLifecycleObserver(onResume: () async {
    await services.gate.refresh();
    await services.push.sync();
  });
  lifecycle.attach();

  // Best-effort push token sync (no-op when push is unconfigured).
  unawaited(services.push.sync());
}

Future<void> _routeInitialDeepLink(AppServices services) async {
  // Load server meta first so web links (App Links / Universal Links) parse
  // against the correct origin before the cold-start URI is routed. Without
  // meta, web links cannot be verified; scheme links still work.
  try {
    final meta = await services.meta.fetch();
    services.deepLinks.webBase = meta.urls?.webBase;
  } on ApiException {
    // Meta is best-effort here; the shell will keep retrying it.
  }

  try {
    final initial = await _deepLinkChannel.invokeMethod<String>('initialLink');
    if (initial != null && initial.isNotEmpty) {
      services.deepLinks.route(initial);
    }
  } on MissingPluginException {
    // Native hosts without the channel simply have no deep links yet.
  } on PlatformException {
    // Ignore unhandled platform errors at startup.
  }

  _deepLinkChannel.setMethodCallHandler((call) async {
    if (call.method == 'onDeepLink' && call.arguments is String) {
      services.deepLinks.route(call.arguments as String);
    }
  });
}

void unawaited(Future<void> future) {
  future.ignore();
}
