import '../../core/api/api_client.dart';
import '../../core/api/generated/openapi_models.dart';

/// Server-driven app metadata (Phase 18 §55).
///
/// `GET /api/v1/app/meta` is anonymous and tells the client the minimum /
/// latest supported app versions, the deep-link scheme, push capability
/// flags, and the BDT / Asia/Dhaka platform contract.
class AppMetaRepository {
  AppMetaRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  AppMeta? _cached;

  AppMeta? get cached => _cached;

  Future<AppMeta> fetch() async {
    final envelope = await _api.get('/app/meta', auth: false);
    final meta = AppMeta.fromJson(envelope.asMap ?? const {});
    _cached = meta;
    return meta;
  }
}
