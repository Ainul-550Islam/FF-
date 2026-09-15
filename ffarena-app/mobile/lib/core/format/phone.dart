/// Bangladesh phone-number presentation (Phase 18 §60).
///
/// Normalizes local `01XXXXXXXXX` numbers into E.164 (`+8801XXXXXXXXX`) for
/// storage/submission and renders them consistently. Digits are never logged
/// by the app's redacting telemetry.
class Phone {
  Phone._();

  /// Normalizes a user-typed Bangladesh number to E.164, or returns null if
  /// it cannot be a valid BD mobile number.
  static String? normalizeBd(String input) {
    var digits = input.replaceAll(RegExp(r'\D'), '');
    if (digits.startsWith('880')) {
      digits = digits.substring(3);
    } else if (digits.startsWith('00880')) {
      digits = digits.substring(5);
    }
    if (digits.startsWith('0')) {
      digits = digits.substring(1);
    }
    if (!RegExp(r'^1[3-9]\d{8}$').hasMatch(digits)) {
      return null;
    }
    return '+880$digits';
  }

  /// Renders E.164 as `01XXXXXXXXX`.
  static String display(String e164) {
    if (e164.startsWith('+880')) {
      return '0${e164.substring(4)}';
    }
    return e164;
  }
}
