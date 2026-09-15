import 'package:ffarena_mobile/core/api/idempotency.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('generates collision-resistant keys with the mobile prefix', () {
    final a = Idempotency.generate();
    final b = Idempotency.generate();

    expect(a, startsWith('mob-'));
    expect(b, startsWith('mob-'));
    expect(a, isNot(b));
    expect(a.length, greaterThan(12));
  });

  test('generates a large number of unique keys', () {
    final seen = <String>{};
    for (var i = 0; i < 500; i++) {
      seen.add(Idempotency.generate());
    }
    expect(seen.length, 500);
  });
}
