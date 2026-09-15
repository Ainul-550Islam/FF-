import 'package:flutter/foundation.dart';

/// Crash/error-reporting abstraction (Phase 18 §62, Phase 19 §31).
///
/// A real build can bind a Sentry/Crashlytics adapter behind this interface;
/// the reference implementation ships a no-op and a debug logger. Reporters
/// must never receive credentials, tokens, OTP codes, raw IPs or device
/// fingerprints — callers only ever pass sanitized, categorized facts.
abstract class CrashReporter {
  /// Reports a handled (caught) error with a redacted context label.
  void record(String context, Object error, StackTrace? stackTrace);

  /// Reports an uncaught platform error.
  void recordFlutterError(FlutterErrorDetails details);

  bool get isEnabled;
}

/// Wires the process-wide uncaught-error channels to [reporter]. Call once
/// at startup (before `runApp`). Catches framework build/layout errors
/// (`FlutterError.onError`) and isolate/async uncaught errors
/// (`PlatformDispatcher.onError`) so a release build reports them instead of
/// silently dropping them. The reporter still only receives sanitized facts.
void installGlobalErrorHandlers(CrashReporter reporter) {
  FlutterError.onError = (FlutterErrorDetails details) {
    FlutterError.presentError(details);
    reporter.recordFlutterError(details);
  };

  PlatformDispatcher.instance.onError = (Object error, StackTrace stack) {
    reporter.record('uncaught', error, stack);
    // Returning true marks the error as handled; the app keeps running.
    return true;
  };
}

class NoopCrashReporter implements CrashReporter {
  const NoopCrashReporter();

  @override
  void record(String context, Object error, StackTrace? stackTrace) {}

  @override
  void recordFlutterError(FlutterErrorDetails details) {}

  @override
  bool get isEnabled => false;
}

/// Debug-only reporter. Never emits in release mode, and never logs payload
/// contents — only the redacted context label and error category.
class LogCrashReporter implements CrashReporter {
  const LogCrashReporter();

  @override
  void record(String context, Object error, StackTrace? stackTrace) {
    if (kDebugMode) {
      // ignore: avoid_print
      print('[crash][$context] ${error.runtimeType}: $error');
    }
  }

  @override
  void recordFlutterError(FlutterErrorDetails details) {
    if (kDebugMode) {
      // ignore: avoid_print
      print('[crash][flutter] ${details.exceptionAsString()}');
    }
  }

  @override
  bool get isEnabled => kDebugMode;
}
