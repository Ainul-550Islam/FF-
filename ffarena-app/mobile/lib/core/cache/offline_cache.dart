import 'dart:async';
import 'dart:convert';
import 'dart:io';

/// Read-only, filesystem-backed offline cache (Phase 18 §33).
///
/// The mobile app NEVER owns a writable source of truth for rankings,
/// wallets, payments or scores — those stay server-authoritative. This cache
/// only stores the last successful GET responses so a user can browse
/// previously loaded content while offline. Entries are marked with their
/// fetch time so the UI can show a "stale data" banner and never presents
/// cached financial/rank values as current.
class OfflineCache {
  OfflineCache._();

  static final OfflineCache instance = OfflineCache._();

  Directory? _dir;

  bool get isReady => _dir != null;

  Future<void> init(Directory baseDir) async {
    final dir = Directory('${baseDir.path}${Platform.pathSeparator}api_cache');
    if (!dir.existsSync()) {
      dir.createSync(recursive: true);
    }
    _dir = dir;
  }

  String? _pathFor(String key) {
    final dir = _dir;
    if (dir == null) {
      return null;
    }
    return '${dir.path}${Platform.pathSeparator}${_keyHash(key)}.json';
  }

  static int _keyHash(String key) => key.hashCode & 0x7FFFFFFF;

  Future<void> put(String key, Map<String, dynamic> value) async {
    final path = _pathFor(key);
    if (path == null) {
      return;
    }
    File(path).writeAsStringSync(jsonEncode({
      'cached_at': DateTime.now().toIso8601String(),
      'payload': value,
    }));
  }

  Future<CachedResponse?> get(String key) async {
    final path = _pathFor(key);
    if (path == null) {
      return null;
    }
    try {
      final file = File(path);
      if (!file.existsSync()) {
        return null;
      }
      final decoded = jsonDecode(file.readAsStringSync());
      if (decoded is! Map<String, dynamic>) {
        return null;
      }
      final cachedAtRaw = decoded['cached_at'];
      final payload = decoded['payload'];
      return CachedResponse(
        cachedAt: cachedAtRaw is String ? DateTime.parse(cachedAtRaw) : null,
        payload: payload is Map<String, dynamic> ? payload : const {},
      );
    } catch (_) {
      // Corrupt cache entries are treated as a miss, never a crash.
      return null;
    }
  }

  Future<void> remove(String key) async {
    final path = _pathFor(key);
    if (path == null) {
      return;
    }
    try {
      File(path).deleteSync();
    } catch (_) {
      // Best effort.
    }
  }

  Future<void> clear() async {
    final dir = _dir;
    if (dir == null) {
      return;
    }
    try {
      final files = dir.listSync().whereType<File>();
      for (final f in files) {
        f.deleteSync();
      }
    } catch (_) {
      // Best effort.
    }
  }
}

class CachedResponse {
  const CachedResponse({required this.cachedAt, required this.payload});

  final DateTime? cachedAt;
  final Map<String, dynamic> payload;

  bool get isStale {
    if (cachedAt == null) {
      return true;
    }
    return DateTime.now().difference(cachedAt!) > const Duration(minutes: 30);
  }
}
