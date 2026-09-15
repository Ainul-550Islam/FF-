import 'package:intl/intl.dart';

/// BDT money formatting (Phase 18 §58).
///
/// The API carries amounts in minor units (poisha, int) and occasionally a
/// server-formatted display string. Formatting is presentation-only — the
/// client never changes precision and never uses a formatted string for
/// arithmetic or payment math.
class Money {
  Money._();

  static const String currency = 'BDT';

  /// Minor units (poisha) -> "1,234.56" (server-authoritative formatting).
  static String formatMinor(int minor, {String? locale}) {
    final taka = minor / 100;
    final nf = NumberFormat.currency(
      locale: locale,
      symbol: '৳',
      decimalDigits: 2,
    );
    return nf.format(taka);
  }

  /// Prefers the server's display string when present, else formats minor.
  static String display({int? minor, String? formatted, String? locale}) {
    if (formatted != null && formatted.isNotEmpty) {
      return formatted;
    }
    return formatMinor(minor ?? 0, locale: locale);
  }
}
