import 'package:ffarena_mobile/core/api/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('ApiException.fromBody', () {
    test('parses the Phase 15 error envelope', () {
      final e = ApiException.fromBody({
        'error': {
          'code': 'validation_error',
          'message': 'Invalid fields.',
          'details': {'kills': 'must be >= 0'},
        },
      }, statusCode: 422);

      expect(e.code, 'validation_error');
      expect(e.message, 'Invalid fields.');
      expect(e.statusCode, 422);
      expect(e.details['kills'], 'must be >= 0');
    });

    test('falls back to unknown code for malformed bodies', () {
      final e = ApiException.fromBody({'unexpected': true});
      expect(e.code, 'unknown');
    });

    test('handles a null body', () {
      final e = ApiException.fromBody(null, statusCode: 500);
      expect(e.code, 'unknown');
      expect(e.statusCode, 500);
    });

    test('isSessionTerminating covers every auth-failure code', () {
      for (final code in const [
        ApiException.accountInactive,
        ApiException.tokenExpired,
        ApiException.tokenRevoked,
        ApiException.unauthenticated,
      ]) {
        expect(
          const ApiException(code: '', message: '').isSessionTerminating,
          isFalse,
        );
        expect(
          ApiException(code: code, message: '').isSessionTerminating,
          isTrue,
        );
      }

      expect(
        const ApiException(code: 'rate_limited', message: '')
            .isSessionTerminating,
        isFalse,
      );
    });
  });
}
