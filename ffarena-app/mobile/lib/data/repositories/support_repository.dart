import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Support tickets (Phase 18 §25).
class SupportRepository {
  SupportRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<SupportTicket>> tickets() async {
    final envelope = await _api.get('/me/support');
    return envelope.asList
        .map((m) => SupportTicket.fromJson(m))
        .toList(growable: false);
  }

  Future<SupportTicket> get(int id) async {
    final envelope = await _api.get('/me/support/$id');
    return SupportTicket.fromJson(envelope.asMap ?? const {});
  }

  /// Returns `{messages, latest_message_id}` from the server.
  Future<Map<String, dynamic>> messages(int ticketId, {int afterId = 0}) async {
    final envelope = await _api.get(
      '/me/support/$ticketId/messages',
      query: {'after': '$afterId'},
    );
    return envelope.asMap ?? const {};
  }

  Future<SupportTicket> create({
    required String subject,
    required String category,
    required String message,
    String? priority,
  }) async {
    final envelope = await _api.post(
      '/me/support',
      body: {
        'subject': subject,
        'category': category,
        'message': message,
        if (priority != null) 'priority': priority,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return SupportTicket.fromJson(envelope.asMap ?? const {});
  }

  Future<SupportMessage> reply(int ticketId, String body) async {
    final envelope = await _api.post(
      '/me/support/$ticketId/messages',
      body: {'body': body},
      idempotencyKey: Idempotency.generate(),
    );
    return SupportMessage.fromJson(envelope.asMap ?? const {});
  }
}
