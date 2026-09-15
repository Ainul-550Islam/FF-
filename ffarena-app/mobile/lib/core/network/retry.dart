import 'dart:async';
import 'dart:math';

import '../api/api_exception.dart';

/// Retry policy with exponential backoff + full jitter (Phase 18 §30/§35).
///
/// Only idempotent operations may be retried automatically. Financial and
/// score mutations are never retried — instead they reuse the same
/// `Idempotency-Key` so a manual retry of the same logical action is safe.
class RetryPolicy {
  const RetryPolicy({
    required this.maxAttempts,
    this.baseDelay = const Duration(milliseconds: 300),
    this.maxDelay = const Duration(seconds: 3),
  });

  final int maxAttempts;
  final Duration baseDelay;
  final Duration maxDelay;

  /// Retries idempotent reads on transient failures only.
  static const idempotentRead = RetryPolicy(maxAttempts: 3);

  static const none = RetryPolicy(maxAttempts: 1);

  bool _isRetriable(ApiException e) =>
      e.code == ApiException.timeout ||
      e.code == ApiException.offline ||
      e.code == ApiException.serverError;

  /// Executes [action], retrying transient failures with backoff. Any
  /// non-retriable error rethrows immediately.
  Future<T> run<T>(Future<T> Function() action) async {
    var attempt = 0;
    final random = Random();
    while (true) {
      attempt += 1;
      try {
        return await action();
      } on ApiException catch (e) {
        if (!_isRetriable(e) || attempt >= maxAttempts) {
          rethrow;
        }
        // Full jitter: sleep = random(0, min(max, base * 2^(attempt-1)))
        final capMs = min(
          maxDelay.inMilliseconds,
          baseDelay.inMilliseconds * pow(2, attempt - 1).toInt(),
        );
        final sleep = Duration(milliseconds: random.nextInt(capMs + 1));
        await Future<void>.delayed(sleep);
      }
    }
  }
}
