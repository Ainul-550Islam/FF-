import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Leaderboards / rankings (Phase 18 §22). Rank and points are computed
/// server-side; the client only renders them.
class LeaderboardRepository {
  LeaderboardRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /leaderboards — ranked tournaments.
  Future<List<Tournament>> rankedTournaments() async {
    final envelope = await _api.get('/leaderboards');
    return envelope.asList
        .map((m) => Tournament.fromJson(m))
        .toList(growable: false);
  }

  /// GET /leaderboards/{tournament} — standings.
  Future<List<StandingRow>> standings(int tournamentId) async {
    final envelope = await _api.get('/leaderboards/$tournamentId');
    return envelope.asList
        .map((m) => StandingRow.fromJson(m))
        .toList(growable: false);
  }

  /// GET /players/{id}/ranking.
  Future<Map<String, dynamic>> playerRanking(int userId) async {
    final envelope = await _api.get('/players/$userId/ranking');
    return envelope.asMap ?? const {};
  }
}
