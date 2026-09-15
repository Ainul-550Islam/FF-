import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Secure-storage contract (Phase 18 §52).
///
/// The ONLY data permitted in secure storage is the access token (and the
/// minimal session envelope that contains it). Nothing else — no PII cache,
/// no payment data, no push tokens — belongs here.
abstract class SecureStorage {
  Future<void> write(String key, String value);

  Future<String?> read(String key);

  Future<void> delete(String key);

  Future<bool> containsKey(String key);
}

/// Storage keys. Kept in one place so the audit of "what lives in secure
/// storage" is trivial.
abstract class SecureKeys {
  static const accessToken = 'auth.access_token';
  static const sessionJson = 'auth.session_json';
}

/// Production implementation backed by the platform keystore/keychain.
class PlatformSecureStorage implements SecureStorage {
  PlatformSecureStorage([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<bool> containsKey(String key) => _storage.containsKey(key: key);
}

/// In-memory implementation used by tests and the local preview harness.
class InMemorySecureStorage implements SecureStorage {
  InMemorySecureStorage([Map<String, String>? seed]) : _map = {...?seed};

  final Map<String, String> _map;

  @override
  Future<void> write(String key, String value) async => _map[key] = value;

  @override
  Future<String?> read(String key) async => _map[key];

  @override
  Future<void> delete(String key) async => _map.remove(key);

  @override
  Future<bool> containsKey(String key) async => _map.containsKey(key);
}
