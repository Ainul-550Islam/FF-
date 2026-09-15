/// A provider-neutral push message delivered to the app (Phase 19 §14).
///
/// The server sends a safe payload:
///
///   {
///     "notification_id": "123",   // dedup key
///     "type": "match.completed",
///     "category": "match",
///     "entity_type": "match",     // optional
///     "entity_id": "9",           // optional
///     "deep_link": "ffarena://match/9"  // optional
///   }
///
/// The client NEVER trusts these fields for authorization — every target is
/// re-fetched from the authoritative server before it renders. The message
/// body may be generic/redacted for sensitive categories.
class PushMessage {
  const PushMessage({
    required this.title,
    required this.body,
    required this.data,
  });

  /// The alert title (may be empty for data-only messages).
  final String title;

  /// The alert body (redacted server-side for sensitive categories).
  final String body;

  /// The structured data payload (string values, as FCM requires).
  final Map<String, dynamic> data;

  String? get notificationId => _string(data['notification_id']);
  String? get type => _string(data['type']);
  String? get category => _string(data['category']);
  String? get entityType => _string(data['entity_type']);
  String? get entityId => _string(data['entity_id']);
  String? get deepLink => _string(data['deep_link']);

  static String? _string(Object? value) =>
      value is String && value.isNotEmpty ? value : null;
}
