import 'dart:ui' show FontFeature;

import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppColors {
  static const bg = Color(0xFF070B14);
  static const surface = Color(0xFF0F1522);
  static const surface2 = Color(0xFF151D2E);
  static const border = Color(0xFF1F2A3F);
  static const gold = Color(0xFFF5B942);
  static const goldDeep = Color(0xFFC98E22);
  static const buy = Color(0xFF1FD18B);
  static const sell = Color(0xFFFF5A6E);
  static const info = Color(0xFF4C8DFF);
  static const warn = Color(0xFFFFB020);
  static const text = Color(0xFFF4F6FB);
  static const text2 = Color(0xFF9AA4B8);
  static const text3 = Color(0xFF5D6882);

  static const goldGradient = LinearGradient(
    colors: [Color(0xFFFFD27A), gold, goldDeep],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const cardGradient = LinearGradient(
    colors: [Color(0xFF141C2C), Color(0xFF0E1420)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );
}

class AppText {
  static const tabular = [FontFeature.tabularFigures()];

  static TextStyle mono(double size,
          {FontWeight weight = FontWeight.w600, Color color = AppColors.text}) =>
      TextStyle(
          fontSize: size,
          fontWeight: weight,
          color: color,
          fontFeatures: tabular,
          letterSpacing: -0.2);

  static const label = TextStyle(
      fontSize: 12, color: AppColors.text3, fontWeight: FontWeight.w500);
  static const caption = TextStyle(fontSize: 12, color: AppColors.text2);
  static const body = TextStyle(fontSize: 14, color: AppColors.text2, height: 1.45);
  static const title =
      TextStyle(fontSize: 16, color: AppColors.text, fontWeight: FontWeight.w700);
  static const h1 = TextStyle(
      fontSize: 28,
      color: AppColors.text,
      fontWeight: FontWeight.w800,
      letterSpacing: -0.6);
  static const h2 = TextStyle(
      fontSize: 20,
      color: AppColors.text,
      fontWeight: FontWeight.w700,
      letterSpacing: -0.3);
}

ThemeData buildTheme() {
  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: const ColorScheme.dark(
      primary: AppColors.gold,
      onPrimary: Color(0xFF1A1204),
      secondary: AppColors.buy,
      surface: AppColors.surface,
      onSurface: AppColors.text,
      error: AppColors.sell,
    ),
    scaffoldBackgroundColor: AppColors.bg,
  );
  final textTheme = GoogleFonts.interTextTheme(base.textTheme).apply(
    bodyColor: AppColors.text,
    displayColor: AppColors.text,
  );
  return base.copyWith(
    textTheme: textTheme,
    appBarTheme: AppBarTheme(
      backgroundColor: AppColors.bg,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: textTheme.titleLarge?.copyWith(
          fontWeight: FontWeight.w700, fontSize: 20, color: AppColors.text),
    ),
    dividerTheme: const DividerThemeData(color: AppColors.border, thickness: 1),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: AppColors.surface2,
      contentTextStyle: textTheme.bodyMedium?.copyWith(color: AppColors.text),
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: const BorderSide(color: AppColors.border)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: AppColors.surface,
      hintStyle: const TextStyle(color: AppColors.text3),
      labelStyle: const TextStyle(color: AppColors.text2),
      floatingLabelStyle: const TextStyle(color: AppColors.gold),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.border)),
      enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.border)),
      focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.gold, width: 1.4)),
      errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.sell)),
      focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: AppColors.sell, width: 1.4)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      indicatorColor: AppColors.gold.withValues(alpha: 0.14),
      height: 68,
      labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: states.contains(WidgetState.selected)
                ? AppColors.gold
                : AppColors.text3,
          )),
      iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
            size: 24,
            color: states.contains(WidgetState.selected)
                ? AppColors.gold
                : AppColors.text3,
          )),
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((s) =>
          s.contains(WidgetState.selected) ? AppColors.bg : AppColors.text3),
      trackColor: WidgetStateProperty.resolveWith((s) =>
          s.contains(WidgetState.selected) ? AppColors.gold : AppColors.surface2),
      trackOutlineColor: WidgetStateProperty.all(AppColors.border),
    ),
    checkboxTheme: CheckboxThemeData(
      fillColor: WidgetStateProperty.resolveWith((s) =>
          s.contains(WidgetState.selected) ? AppColors.gold : Colors.transparent),
      checkColor: WidgetStateProperty.all(AppColors.bg),
      side: const BorderSide(color: AppColors.text3, width: 1.5),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
    ),
    progressIndicatorTheme:
        const ProgressIndicatorThemeData(color: AppColors.gold),
    pageTransitionsTheme: const PageTransitionsTheme(builders: {
      TargetPlatform.android: CupertinoPageTransitionsBuilder(),
      TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
    }),
  );
}
