import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';
import '../../core/api/idempotency.dart';

/// Tournaments, registrations and matches (Phase 18 §17/§19/§26).
class TournamentRepository {
  TournamentRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<Tournament>> list({
    int page = 1,
    String? search,
    String? status,
    String? gameMode,
  }) async {
    final envelope = await _api.get('/tournaments', query: {
      'page': '$page',
      if (search != null && search.isNotEmpty) 'search': search,
      if (status != null && status.isNotEmpty) 'status': status,
      if (gameMode != null && gameMode.isNotEmpty) 'game_mode': gameMode,
    });
    return envelope.asList
        .map((m) => Tournament.fromJson(m))
        .toList(growable: false);
  }

  Future<Tournament> get(int id) async {
    final envelope = await _api.get('/tournaments/$id');
    return Tournament.fromJson(envelope.asMap ?? const {});
  }

  Future<List<MatchModel>> matches(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/matches');
    return envelope.asList
        .map((m) => MatchModel.fromJson(m))
        .toList(growable: false);
  }

  /// POST /tournaments/{id}/registrations — creates (or waitlists) a team.
  /// Returns the raw registration envelope; the client trusts the server's
  /// `waitlisted` / `waitlist_position` / `next_step` verdict.
  Future<Map<String, dynamic>> register({
    required int tournamentId,
    required String name,
    required String captainName,
    required String phone,
    required String gameUid,
    List<Map<String, String>> members = const [],
  }) async {
    final envelope = await _api.post(
      '/tournaments/$tournamentId/registrations',
      body: {
        'name': name,
        'captain_name': captainName,
        'phone': phone,
        'game_uid': gameUid,
        'members': members,
      },
      idempotencyKey: Idempotency.generate(),
    );
    return envelope.asMap ?? const {};
  }

  /// POST /tournaments/{id}/check-in.
  Future<Map<String, dynamic>> checkIn(int tournamentId, int teamId) async {
    final envelope = await _api.post(
      '/tournaments/$tournamentId/check-in',
      body: {'team_id': teamId},
      idempotencyKey: Idempotency.generate(),
    );
    return envelope.asMap ?? const {};
  }

  /// GET /tournaments/{id}/bracket.
  Future<dynamic> bracket(int tournamentId) async {
    final envelope = await _api.get('/tournaments/$tournamentId/bracket');
    return envelope.data;
  }
}
