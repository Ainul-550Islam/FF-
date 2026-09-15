import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Teams and rosters (Phase 18 §21).
class TeamRepository {
  TeamRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// GET /me/teams — teams the current user captains or belongs to.
  Future<List<Team>> mine() async {
    final envelope = await _api.get('/me/teams');
    return envelope.asList.map((m) => Team.fromJson(m)).toList(growable: false);
  }

  Future<Team> get(int id) async {
    final envelope = await _api.get('/teams/$id');
    return Team.fromJson(envelope.asMap ?? const {});
  }

  /// PATCH /teams/{id}.
  Future<Team> update({
    required int teamId,
    required String name,
    required String captainName,
    required String phone,
    required String gameUid,
  }) async {
    final envelope = await _api.patch('/teams/$teamId', body: {
      'name': name,
      'captain_name': captainName,
      'phone': phone,
      'game_uid': gameUid,
    });
    return Team.fromJson(envelope.asMap ?? const {});
  }

  /// GET /teams/{id}/roster.
  Future<List<Map<String, dynamic>>> roster(int teamId) async {
    final envelope = await _api.get('/teams/$teamId/roster');
    return envelope.asList;
  }

  /// POST /teams/{id}/roster.
  Future<void> addMember(int teamId, String playerName, String gameUid) async {
    await _api.post('/teams/$teamId/roster', body: {
      'player_name': playerName,
      'game_uid': gameUid,
    });
  }

  /// DELETE /teams/{id}/roster/{memberId}.
  Future<void> removeMember(int teamId, int memberId) async {
    await _api.delete('/teams/$teamId/roster/$memberId');
  }

  /// POST /teams/{id}/withdraw.
  Future<void> withdraw(int teamId) async {
    await _api.post('/teams/$teamId/withdraw');
  }
}
