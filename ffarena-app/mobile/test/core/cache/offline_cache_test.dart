import 'dart:io';

import 'package:ffarena_mobile/core/cache/offline_cache.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late Directory tempDir;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('ffarena_cache_test');
  });

  tearDown(() async {
    await tempDir.delete(recursive: true);
  });

  test('put and get round-trips a payload', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('tournaments', {
      'items': [1, 2, 3]
    });

    final hit = await cache.get('tournaments');
    expect(hit, isNotNull);
    expect(hit!.payload['items'], [1, 2, 3]);
    expect(hit.cachedAt, isNotNull);
    expect(hit.isStale, isFalse);
  });

  test('missing key returns null (miss, not crash)', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    expect(await cache.get('nope'), isNull);
  });

  test('corrupt entry is treated as a miss', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    final file =
        File('${tempDir.path}/api_cache/${'bad'.hashCode & 0x7FFFFFFF}.json');
    await file.create(recursive: true);
    await file.writeAsString('not-json{{{');

    expect(await cache.get('bad'), isNull);
  });

  test('remove deletes an entry', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('k', {'v': 1});
    expect((await cache.get('k'))?.payload['v'], 1);

    await cache.remove('k');
    expect(await cache.get('k'), isNull);
  });

  test('clear empties the cache', () async {
    final cache = OfflineCache.instance;
    await cache.init(tempDir);

    await cache.put('a', {'v': 1});
    await cache.put('b', {'v': 2});
    await cache.clear();

    expect(await cache.get('a'), isNull);
    expect(await cache.get('b'), isNull);
  });
}
