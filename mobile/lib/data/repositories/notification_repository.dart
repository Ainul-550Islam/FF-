import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// In-app notifications (Phase 18 §28).
class NotificationRepository {
  NotificationRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<NotificationModel>> list({int page = 1}) async {
    final envelope =
        await _api.get('/me/notifications', query: {'page': '$page'});
    return envelope.asList
        .map((m) => NotificationModel.fromJson(m))
        .toList(growable: false);
  }

  Future<int> unreadCount() async {
    final envelope = await _api.get('/me/notifications/unread-count');
    final value = envelope.data;
    if (value is int) {
      return value;
    }
    if (value is Map<String, dynamic>) {
      return (value['count'] as num?)?.toInt() ?? 0;
    }
    return 0;
  }

  Future<void> markRead(int id) async {
    await _api.post('/me/notifications/$id/read');
  }

  Future<void> markAllRead() async {
    await _api.post('/me/notifications/read-all');
  }
}
