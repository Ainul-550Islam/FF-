import 'package:flutter/foundation.dart';

import '../../config/app_config.dart';
import '../../data/repositories/app_meta_repository.dart';
import '../api/api_exception.dart';
import 'version_gate.dart';

/// Observes the server release gate (Phase 19 §21–§23).
///
/// Fetches `/api/v1/app/meta` at startup and on resume and derives the
/// [AppGate] state. Network failures never flip the gate — offline users keep
/// using the app (the server still enforces its own rules on every call).
class ReleaseGateController extends ChangeNotifier {
  ReleaseGateController({required AppMetaRepository meta}) : _meta = meta;

  final AppMetaRepository _meta;

  AppGate _gate = AppGate.current;
  String _message = '';
  String? _storeUrl;

  AppGate get gate => _gate;
  String get message => _message;
  String? get storeUrl => _storeUrl;

  bool get isBlocking =>
      _gate == AppGate.updateRequired || _gate == AppGate.maintenance;

  /// Re-fetches server metadata and re-evaluates the gate.
  Future<void> refresh() async {
    try {
      final meta = await _meta.fetch();
      final gate = evaluateGate(
        currentVersion: AppConfig.instance.version,
        meta: meta,
      );
      _gate = gate;
      _message = meta.maintenance?.message ?? '';
      _storeUrl = meta.urls?.store;
      notifyListeners();
    } on ApiException {
      // Offline or transient — keep the previous gate state.
    } catch (_) {
      // Any other failure also keeps the previous state.
    }
  }
}
