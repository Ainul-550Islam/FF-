import 'dart:math';

/// Idempotency-Key generation (Phase 18 §30).
///
/// Critical mutations (registration, payments, score submission, support
/// tickets) accept an `Idempotency-Key` header; a replay within the server
/// TTL returns the stored response instead of executing twice. The client
/// generates a fresh key per logical action and REUSES the same key when
/// retrying the same action, so a retried financial/score mutation can never
/// double-execute.
class Idempotency {
  const Idempotency._();

  static final Random _random = Random.secure();

  /// A fresh, collision-resistant key.
  static String generate() {
    final millis = DateTime.now().millisecondsSinceEpoch.toRadixString(16);
    final rand = _random.nextInt(0x7FFFFFFF).toRadixString(16).padLeft(8, '0');
    return 'mob-$millis-$rand';
  }
}
