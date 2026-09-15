import 'package:flutter/material.dart';

/// A small, colour-coded status chip. Uses BOTH colour and text so the
/// meaning is never conveyed by colour alone (WCAG 2.2 AA).
class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = _colors(context, status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        status,
        style: Theme.of(context)
            .textTheme
            .labelSmall
            ?.copyWith(color: fg, fontWeight: FontWeight.w600),
      ),
    );
  }

  (Color, Color) _colors(BuildContext context, String s) {
    final scheme = Theme.of(context).colorScheme;
    final lower = s.toLowerCase();
    if (lower.contains('open') ||
        lower.contains('active') ||
        lower.contains('paid')) {
      return (const Color(0xFFD7F3E5), const Color(0xFF0B6E4F));
    }
    if (lower.contains('full') ||
        lower.contains('closed') ||
        lower.contains('failed')) {
      return (const Color(0xFFFBE4E2), const Color(0xFFB3261E));
    }
    if (lower.contains('wait') ||
        lower.contains('pending') ||
        lower.contains('check')) {
      return (const Color(0xFFFFF3CD), const Color(0xFF7A5B00));
    }
    return (scheme.surfaceContainerHighest, scheme.onSurfaceVariant);
  }
}
