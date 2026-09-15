import 'package:flutter/material.dart';

/// Initials-based avatar with an optional remote image. Falls back to
/// initials when the URL is absent, malformed or fails to load.
class Avatar extends StatelessWidget {
  const Avatar({
    super.key,
    required this.name,
    this.url,
    this.radius = 20,
  });

  final String name;
  final String? url;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final initials = _initials(name);
    if (url != null && url!.isNotEmpty) {
      return CircleAvatar(
        radius: radius,
        backgroundColor: scheme.primaryContainer,
        foregroundImage: NetworkImage(url!),
        onForegroundImageError: (_, __) {},
        child: Text(initials),
      );
    }
    return CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primaryContainer,
      foregroundColor: scheme.onPrimaryContainer,
      child: Text(initials),
    );
  }

  static String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    if (parts.isEmpty) {
      return '?';
    }
    final buffer = StringBuffer();
    for (final part in parts.take(2)) {
      buffer.write(part.substring(0, 1).toUpperCase());
    }
    return buffer.toString();
  }
}
