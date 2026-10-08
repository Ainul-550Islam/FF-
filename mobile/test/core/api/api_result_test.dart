import 'package:ffarena_mobile/core/api/api_result.dart';
import 'package:flutter_test/flutter_test.dart';

/// ApiEnvelopeData unwrap rules (Phase 18 + AUDIT FIX-11b).
void main() {
  group('asList', () {
    test('passes a List of maps through, dropping non-maps', () {
      const envelope = ApiEnvelopeData(data: [
        {'id': 1},
        'junk',
        {'id': 2},
        42,
      ]);

      expect(envelope.asList, [
        {'id': 1},
        {'id': 2},
      ]);
    });

    test('unwraps one nested data level (FIX-11b back-compat)', () {
      const envelope = ApiEnvelopeData(data: {
        'data': [
          {'id': 7},
        ],
        'links': {},
        'meta': {},
      });

      expect(envelope.asList, [
        {'id': 7},
      ]);
    });

    test('returns empty for scalars and maps without a list node', () {
      expect(const ApiEnvelopeData(data: 'nope').asList, isEmpty);
      expect(const ApiEnvelopeData(data: 42).asList, isEmpty);
      expect(const ApiEnvelopeData(data: {'data': 'nope'}).asList, isEmpty);
      expect(const ApiEnvelopeData(data: null).asList, isEmpty);
    });
  });

  group('asMap', () {
    test('returns the map node verbatim', () {
      const envelope = ApiEnvelopeData(data: {'id': 3});

      expect(envelope.asMap, {'id': 3});
    });

    test('returns null for non-map nodes', () {
      expect(const ApiEnvelopeData(data: [1]).asMap, isNull);
      expect(const ApiEnvelopeData(data: 'x').asMap, isNull);
    });
  });

  group('pagination', () {
    test('decodes the meta pagination block', () {
      const envelope = ApiEnvelopeData(data: [], meta: {
        'pagination': {'current_page': 1, 'last_page': 3},
      });

      expect(envelope.hasMore, isTrue);
      expect(envelope.nextPage, 2);
    });

    test('hasMore is false on the last page or without meta', () {
      const last = ApiEnvelopeData(data: [], meta: {
        'pagination': {'current_page': 3, 'last_page': 3},
      });

      expect(last.hasMore, isFalse);
      expect(last.nextPage, 4);

      const bare = ApiEnvelopeData(data: []);

      expect(bare.hasMore, isFalse);
      expect(bare.nextPage, isNull);
    });
  });
}
