import 'push_message.dart';

/// Deduplicates push notifications by server notification id (Phase 19 §40).
///
/// The same notification can arrive more than once — FCM retries, a
/// foreground message that was already rendered in-app, or a token refresh
/// re-delivering the last message. The server always includes a unique
/// `notification_id`; the first occurrence is kept and any later occurrence
/// of the same id is treated as a duplicate.
class NotificationDedup {
  NotificationDedup({int capacity = 500}) : _capacity = capacity;

  final int _capacity;
  final Set<String> _seen = <String>{};

  /// True when [message] has already been seen (and therefore should not be
  /// rendered again). The first sighting records the id.
  bool isDuplicate(PushMessage message) {
    final id = message.notificationId;
    if (id == null) {
      // Messages without a dedup key cannot be deduplicated safely.
      return false;
    }

    if (_seen.contains(id)) {
      return true;
    }

    _remember(id);
    return false;
  }

  void _remember(String id) {
    if (_seen.length >= _capacity) {
      _seen.remove(_seen.first);
    }
    _seen.add(id);
  }
}
