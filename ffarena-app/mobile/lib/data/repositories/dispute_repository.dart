import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Disputes (Phase 18 §29).
///
/// The API is read-only for disputes: the client lists the user's own
/// disputes and shows a single dispute. Dispute FILING happens on the web
/// match page — the mobile client does not invent a create endpoint.
class DisputeRepository {
  DisputeRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<Dispute>> mine() async {
    final envelope = await _api.get('/me/disputes');
    return envelope.asList
        .map((m) => Dispute.fromJson(m))
        .toList(growable: false);
  }

  Future<Dispute> get(int id) async {
    final envelope = await _api.get('/disputes/$id');
    return Dispute.fromJson(envelope.asMap ?? const {});
  }
}
