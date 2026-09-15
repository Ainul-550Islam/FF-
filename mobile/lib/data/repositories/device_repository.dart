import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Push-device registry client (Phase 18 §54). Only ever touches the
/// authenticated user's OWN devices (owner-only policy, enforced server-side
/// and mirrored here). The raw token is sent once; the server stores only
/// its sha256 hash and never echoes it back.
class DeviceRepository {
  DeviceRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<MobileDevice>> list() async {
    final envelope = await _api.get('/me/devices');
    return envelope.asList
        .map((m) => MobileDevice.fromJson(m))
        .toList(growable: false);
  }

  Future<Map<String, dynamic>> register({
    required String platform,
    required String provider,
    required String token,
    String? label,
    String? appVersion,
    String? environment,
  }) async {
    final envelope = await _api.post('/me/devices', body: {
      'platform': platform,
      'provider': provider,
      'token': token,
      if (label != null && label.isNotEmpty) 'device_label': label,
      if (appVersion != null && appVersion.isNotEmpty)
        'app_version': appVersion,
      if (environment != null && environment.isNotEmpty)
        'environment': environment,
    });
    return envelope.asMap ?? const {};
  }

  Future<void> delete(int deviceId) async {
    await _api.delete('/me/devices/$deviceId');
  }
}
