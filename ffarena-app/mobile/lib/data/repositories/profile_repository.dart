import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Current-user profile (Phase 18 §59).
class ProfileRepository {
  ProfileRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Me> me() async {
    final envelope = await _api.get('/me');
    return Me.fromJson(envelope.asMap ?? const {});
  }

  /// PUT/PATCH /me/profile. Only documented, non-sensitive fields.
  Future<Me> update({
    String? name,
    String? bio,
    String? country,
    String? region,
    String? avatar,
    String? privacy,
  }) async {
    final envelope = await _api.patch('/me/profile', body: {
      if (name != null) 'name': name,
      if (bio != null) 'bio': bio,
      if (country != null) 'country': country,
      if (region != null) 'region': region,
      if (avatar != null) 'avatar': avatar,
      if (privacy != null) 'privacy': privacy,
    });
    return Me.fromJson(envelope.asMap ?? const {});
  }

  /// Public player profile (GET /players/{id}).
  Future<UserProfile> player(int userId) async {
    final envelope = await _api.get('/players/$userId');
    return UserProfile.fromJson(envelope.asMap ?? const {});
  }
}
