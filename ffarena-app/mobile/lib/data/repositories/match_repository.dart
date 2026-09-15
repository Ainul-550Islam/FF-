import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Match reads and score submission (Phase 18 §26/§27).
///
/// The client submits ONLY the documented `{team_id, kills, placement}` and
/// never attempts to send points/status (the server prohibits them).
class MatchRepository {
  MatchRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<MatchModel> get(int id) async {
    final envelope = await _api.get('/matches/$id');
    return MatchModel.fromJson(envelope.asMap ?? const {});
  }

  Future<List<Score>> scores(int matchId) async {
    final envelope = await _api.get('/matches/$matchId/scores');
    return envelope.asList
        .map((m) => Score.fromJson(m))
        .toList(growable: false);
  }

  Future<Score> submitScore({
    required int matchId,
    required int teamId,
    required int kills,
    required int placement,
  }) async {
    final envelope = await _api.post(
      '/matches/$matchId/scores',
      body: {
        'team_id': teamId,
        'kills': kills,
        'placement': placement,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return Score.fromJson(envelope.asMap ?? const {});
  }
}
