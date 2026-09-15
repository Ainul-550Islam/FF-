import 'package:flutter/material.dart';

/// FF Arena theme (Phase 18 §65 — WCAG 2.2 AA).
///
/// Colour choices satisfy the WCAG 2.2 AA contrast ratio for normal text:
///   * onPrimary (#FFFFFF) on primary (#0B6E4F): ~5.7:1
///   * onSurface (#17262B) on surface (#FFFFFF): ~14:1
///   * onError (#FFFFFF) on error (#B3261E): ~6.0:1
/// Text scales with the user's OS font size by relying on default
/// [TextTheme] and avoiding fixed line heights.
class AppTheme {
  AppTheme._();

  static const Color primary = Color(0xFF0B6E4F);
  static const Color onPrimary = Color(0xFFFFFFFF);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color onSurface = Color(0xFF17262B);
  static const Color error = Color(0xFFB3261E);
  static const Color onError = Color(0xFFFFFFFF);
  static const Color outline = Color(0xFF6F797C);

  static ThemeData light() {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: const ColorScheme.light(
        primary: primary,
        onPrimary: onPrimary,
        secondary: primary,
        surface: surface,
        onSurface: onSurface,
        error: error,
        onError: onError,
        outline: outline,
      ),
      scaffoldBackgroundColor: surface,
      appBarTheme: const AppBarTheme(
        backgroundColor: surface,
        foregroundColor: onSurface,
        elevation: 0,
        centerTitle: true,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
        filled: true,
        fillColor: Color(0xFFF1F4F4),
      ),
      cardTheme: const CardThemeData(
        elevation: 0,
        color: surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(12)),
          side: BorderSide(color: Color(0xFFDDE3E3)),
        ),
      ),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
      ),
    );

    return base;
  }
}
