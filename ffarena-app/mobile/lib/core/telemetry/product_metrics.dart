import 'dart:io';

import '../../config/app_config.dart';

/// Minimal, privacy-safe product telemetry (Phase 18 §34).
///
/// Only a hard allow-list of non-identifying fields may ever be recorded:
/// app version, platform (OS), event name/category and duration. Passwords,
/// OTP codes, tokens, raw IPs, risk scores and device fingerprints are never
/// recorded — they are simply dropped by construction.
class ProductMetrics {
  ProductMetrics._();

  /// Optional sink (e.g. a local logger or a remote metrics pipeline).
  /// Nothing is sent unless a sink is attached.
  static void Function(String event, Map<String, String> fields)? sink;

  static const Set<String> _allowedFields = {
    'app_version',
    'platform',
    'event',
    'category',
    'duration_ms',
  };

  static void record(String event, {Map<String, String> fields = const {}}) {
    final filtered = <String, String>{
      'event': event,
      'app_version': AppConfig.instance.version,
      'platform': Platform.operatingSystem,
      for (final e in fields.entries)
        if (_allowedFields.contains(e.key)) e.key: e.value,
    };
    sink?.call(event, filtered);
  }

  /// Times a block and records its duration under [category].
  static Future<T> timed<T>(
    String event, {
    String? category,
    required Future<T> Function() action,
  }) async {
    final stopwatch = Stopwatch()..start();
    try {
      return await action();
    } finally {
      stopwatch.stop();
      record(
        event,
        fields: {
          if (category != null) 'category': category,
          'duration_ms': '${stopwatch.elapsedMilliseconds}',
        },
      );
    }
  }
}
