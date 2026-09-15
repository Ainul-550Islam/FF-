import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Live activity (Phase 18 §36).
class LiveRepository {
  LiveRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/live — the current user's live/upcoming activity feed.
  Future<List<LiveEvent>> me() async {
    final envelope = await _api.get('/me/live');
    return envelope.asList
        .map((m) => LiveEvent.fromJson(m))
        .toList(growable: false);
  }

  /// GET /tournaments/{id}/live — a tournament's live feed.
  Future<List<LiveEvent>> tournament(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/live');
    return envelope.asList
        .map((m) => LiveEvent.fromJson(m))
        .toList(growable: false);
  }
}
