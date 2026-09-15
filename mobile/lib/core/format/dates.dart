import 'package:intl/intl.dart';

/// Date/time presentation (Phase 18 §60). The platform timezone is
/// Asia/Dhaka (announced by `/api/v1/app/meta`), so timestamps are rendered
/// in the Asia/Dhaka wall clock by default. Pure presentation — the server
/// remains authoritative for all schedules.
class Dates {
  Dates._();

  static const String platformTimezone = 'Asia/Dhaka';

  static DateTime? parse(String? iso) {
    if (iso == null || iso.isEmpty) {
      return null;
    }
    return DateTime.tryParse(iso);
  }

  static DateTime toPlatform(DateTime value) =>
      value.toUtc().add(const Duration(hours: 6));

  /// "12 Sep 2026, 8:30 PM".
  static String formatDateTime(String? iso, {String? locale}) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    final local = toPlatform(parsed);
    return DateFormat('d MMM y, h:mm a', locale).format(local);
  }

  /// "12 Sep 2026".
  static String formatDate(String? iso, {String? locale}) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    return DateFormat('d MMM y', locale).format(toPlatform(parsed));
  }

  /// "in 3h 20m" / "5m ago" style relative time.
  static String relative(String? iso) {
    final parsed = parse(iso);
    if (parsed == null) {
      return '—';
    }
    final diff = toPlatform(parsed)
        .difference(DateTime.now().toUtc().add(const Duration(hours: 6)));
    final abs = diff.abs();
    if (abs.inDays >= 1) {
      return '${abs.inDays}d';
    }
    if (abs.inHours >= 1) {
      return '${abs.inHours}h';
    }
    return '${abs.inMinutes.abs()}m';
  }
}
