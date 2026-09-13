import 'package:flutter/material.dart';

/// نظام ألوان موحّد للتطبيق — مبني على نفس هوية موقعكم البصرية
/// (الأزرق والبنفسجي والذهبي الفعليين المستخدمين بالموقع نفسه)
/// بس بترتيب وتدرجات جديدة تعطي شكل أكثر تميّزاً وحداثة.
class AppColors {
  AppColors._();

  // [FEATURE] وضع فعلي (ليلي/نهاري) قابل للتبديل وقت التشغيل. يبدأ داكناً
  // افتراضياً (نفس شكل التطبيق قبل هذه الميزة). main.dart يستمع لهذه القيمة
  // ويعيد بناء التطبيق بالكامل عند تغييرها، وشاشة الإعدادات هي من تُغيّرها.
  static final ValueNotifier<bool> mode = ValueNotifier<bool>(true);
  static bool get isDark => mode.value;
  static void setDark(bool value) => mode.value = value;

  // طبقات الخلفية — تتبدّل فعلياً بين الوضعين
  static Color get bg => isDark ? const Color(0xFF111016) : const Color(0xFFF7F5FA);
  static Color get bg2 => isDark ? const Color(0xFF17131E) : const Color(0xFFFFFFFF);
  static Color get card => isDark ? const Color(0xFF1D1924) : const Color(0xFFFFFFFF);
  static Color get card2 => isDark ? const Color(0xFF26202F) : const Color(0xFFF0EDF7);
  static Color get card3 => isDark ? const Color(0xFF332A40) : const Color(0xFFE7E1F0);
  static Color get border => isDark ? Colors.white.withOpacity(0.07) : Colors.black.withOpacity(0.08);

  // الألوان الأساسية (نفس هوية الموقع) — ثابتة في الوضعين
  static const primary = Color(0xFF9B5CFF);
  static const primaryDark = Color(0xFF5B2A9D);
  static const accentPurple = Color(0xFF8B45E8);
  static const cyan = Color(0xFF22D3EE);
  static const gold = Color(0xFFFBBF24);
  static const green = Color(0xFF34D399);
  static const red = Color(0xFFF87171);
  static const purple = Color(0xFFC084FC);

  // النصوص — تتبدّل فعلياً بين الوضعين
  static Color get text => isDark ? const Color(0xFFF4EFFA) : const Color(0xFF1C1726);
  static Color get text2 => isDark ? const Color(0xFFA99DB7) : const Color(0xFF645C71);
  static Color get text3 => isDark ? const Color(0xFF6F637B) : const Color(0xFF9891A0);

  // تدرج بطاقة الرصيد الرئيسية — ثابت في الوضعين (عنصر هوية بصرية)
  static const balanceGradient = LinearGradient(
    begin: Alignment.topRight,
    end: Alignment.bottomLeft,
    colors: [Color(0xFFB14BFF), Color(0xFF6825B3)],
  );

  // ألوان دائرية متنوعة للأيقونات (نفس ألوان الموقع، بالتناوب)
  static const List<Color> iconPalette = [accentPurple, purple, primary, cyan, green, red];

  static Color iconColorFor(int index) => iconPalette[index % iconPalette.length];
}
