import '../api/generated/openapi_models.dart';

/// Release gate states (Phase 19 §21–§23).
///
///   current          — this build is fine to use.
///   updateAvailable  — a newer build exists; warn but do not block.
///   updateRequired   — this build is below the server's minimum supported
///                      version (or the server forced updates); block.
///   maintenance      — the server advertises mobile maintenance; block
///                      mutations but keep logout/security available.
enum AppGate { current, updateAvailable, updateRequired, maintenance }

/// Evaluates the release gate from the current app version and the
/// server-authoritative `/api/v1/app/meta` payload. The server is the only
/// source of truth for the minimum version, forced updates and maintenance;
/// nothing here is decided client-side.
AppGate evaluateGate({required String currentVersion, required AppMeta meta}) {
  if (meta.maintenance?.active == true) {
    return AppGate.maintenance;
  }

  if (meta.app?.updateRequired == true) {
    return AppGate.updateRequired;
  }

  final min = meta.app?.minSupportedAppVersion;
  if (min != null &&
      min.isNotEmpty &&
      compareVersions(currentVersion, min) < 0) {
    return AppGate.updateRequired;
  }

  final latest = meta.app?.latestAppVersion;
  if (latest != null &&
      latest.isNotEmpty &&
      compareVersions(currentVersion, latest) < 0) {
    return AppGate.updateAvailable;
  }

  return AppGate.current;
}

/// Compares two dotted semantic versions (`1.2.3`). Returns a negative value
/// when [a] < [b], zero when equal, and a positive value when [a] > [b].
/// Missing or non-numeric segments are treated as 0.
int compareVersions(String a, String b) {
  final pa = a.split('.').map(_asInt).toList(growable: false);
  final pb = b.split('.').map(_asInt).toList(growable: false);

  final length = pa.length > pb.length ? pa.length : pb.length;
  for (var i = 0; i < length; i++) {
    final x = i < pa.length ? pa[i] : 0;
    final y = i < pb.length ? pb[i] : 0;
    if (x != y) {
      return x < y ? -1 : 1;
    }
  }

  return 0;
}

int _asInt(String segment) => int.tryParse(segment.trim()) ?? 0;
