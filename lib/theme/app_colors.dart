import 'package:flutter/material.dart';

/// نظام ألوان موحّد للتطبيق — مبني على نفس هوية موقعكم البصرية
/// (الأزرق والبنفسجي والذهبي الفعليين المستخدمين بالموقع نفسه)
/// بس بترتيب وتدرجات جديدة تعطي شكل أكثر تميّزاً وحداثة.
class AppColors {
  AppColors._();

  // طبقات الخلفية
  static const bg = Color(0xFF070B14);
  static const bg2 = Color(0xFF0B1120);
  static const card = Color(0xFF111A2B);
  static const card2 = Color(0xFF17233A);
  static const card3 = Color(0xFF20304B);
  static final border = Colors.white.withOpacity(0.07);

  // الألوان الأساسية (نفس هوية الموقع)
  static const primary = Color(0xFF3B82F6); // أزرق
  static const primaryDark = Color(0xFF1D4ED8);
  static const accentPurple = Color(0xFF6C3FE0); // بنفسجي مميز بالموقع
  static const cyan = Color(0xFF22D3EE);
  static const gold = Color(0xFFFBBF24);
  static const green = Color(0xFF34D399);
  static const red = Color(0xFFF87171);
  static const purple = Color(0xFFA78BFA);

  // النصوص
  static const text = Color(0xFFE2E8F5);
  static const text2 = Color(0xFF7C93B5);
  static const text3 = Color(0xFF3D526E);

  // تدرج بطاقة الرصيد الرئيسية
  static const balanceGradient = LinearGradient(
    begin: Alignment.topRight,
    end: Alignment.bottomLeft,
    colors: [accentPurple, primaryDark],
  );

  // ألوان دائرية متنوعة للأيقونات (نفس ألوان الموقع، بالتناوب)
  static const List<Color> iconPalette = [gold, green, cyan, purple, red, primary];

  static Color iconColorFor(int index) => iconPalette[index % iconPalette.length];
}
