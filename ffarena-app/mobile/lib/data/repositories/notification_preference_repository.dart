import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Push notification preferences (Phase 19 §10). Only ever touches the
/// caller's own preferences (owner-only endpoint). The `security` category is
/// always delivered server-side and can never be disabled.
class NotificationPreferenceRepository {
  NotificationPreferenceRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/notification-preferences.
  Future<NotificationPreference> fetch() async {
    final envelope = await _api.get('/me/notification-preferences');
    return NotificationPreference.fromJson(envelope.asMap ?? const {});
  }

  /// PATCH /me/notification-preferences with a partial set of flags.
  Future<NotificationPreference> update(Map<String, bool> flags) async {
    final envelope = await _api.patch(
      '/me/notification-preferences',
      body: flags,
    );
    return NotificationPreference.fromJson(envelope.asMap ?? const {});
  }
}
