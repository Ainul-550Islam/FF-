import 'package:ffarena_mobile/core/api/generated/openapi_models.dart';
import 'package:ffarena_mobile/core/version/version_gate.dart';
import 'package:flutter_test/flutter_test.dart';

AppMeta meta({
  String min = '1.0.0',
  String latest = '1.0.0',
  bool updateRequired = false,
  bool maintenance = false,
}) {
  return AppMeta.fromJson({
    'app': {
      'name': 'FF Arena',
      'min_supported_app_version': min,
      'latest_app_version': latest,
      'update_required': updateRequired,
      'deep_link_scheme': 'ffarena',
    },
    'maintenance': {'active': maintenance, 'message': ''},
    'push': {'fcm_enabled': false, 'apns_enabled': false},
    'urls': {'store': 'https://store.example.test/app'},
    'platform': {'currency': 'BDT', 'timezone': 'Asia/Dhaka', 'locale': 'en'},
  });
}

void main() {
  test('current build passes', () {
    final gate = evaluateGate(currentVersion: '1.0.0', meta: meta());
    expect(gate, AppGate.current);
  });

  test('newer latest version is update-available (non-blocking)', () {
    final gate = evaluateGate(
      currentVersion: '1.0.0',
      meta: meta(min: '1.0.0', latest: '1.2.0'),
    );
    expect(gate, AppGate.updateAvailable);
  });

  test('below the minimum version is update-required', () {
    final gate = evaluateGate(
      currentVersion: '0.9.9',
      meta: meta(min: '1.0.0', latest: '1.2.0'),
    );
    expect(gate, AppGate.updateRequired);
  });

  test('server-forced update is required even when the version matches', () {
    final gate = evaluateGate(
      currentVersion: '1.0.0',
      meta: meta(min: '1.0.0', latest: '1.0.0', updateRequired: true),
    );
    expect(gate, AppGate.updateRequired);
  });

  test('maintenance wins over everything', () {
    final gate = evaluateGate(
      currentVersion: '1.0.0',
      meta: meta(updateRequired: true, maintenance: true),
    );
    expect(gate, AppGate.maintenance);
  });

  test('version comparison is numeric, not lexicographic', () {
    expect(compareVersions('1.10.0', '1.9.0'), greaterThan(0));
    expect(compareVersions('1.2.3', '1.2.3'), 0);
    expect(compareVersions('1.2.3', '1.2.4'), lessThan(0));
    expect(compareVersions('2.0', '1.9.9'), greaterThan(0));
    expect(compareVersions('1.0', '1.0.0'), 0);
  });
}
