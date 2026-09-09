import 'package:flutter/material.dart';

/// نظام ألوان موحّد للتطبيق — مبني على نفس هوية موقعكم البصرية
/// (الأزرق والبنفسجي والذهبي الفعليين المستخدمين بالموقع نفسه)
/// بس بترتيب وتدرجات جديدة تعطي شكل أكثر تميّزاً وحداثة.
class AppColors {
  AppColors._();

  // طبقات الخلفية
  static const bg = Color(0xFF111016);
  static const bg2 = Color(0xFF17131E);
  static const card = Color(0xFF1D1924);
  static const card2 = Color(0xFF26202F);
  static const card3 = Color(0xFF332A40);
  static final border = Colors.white.withOpacity(0.07);

  // الألوان الأساسية (نفس هوية الموقع)
  static const primary = Color(0xFF9B5CFF);
  static const primaryDark = Color(0xFF5B2A9D);
  static const accentPurple = Color(0xFF8B45E8);
  static const cyan = Color(0xFF22D3EE);
  static const gold = Color(0xFFFBBF24);
  static const green = Color(0xFF34D399);
  static const red = Color(0xFFF87171);
  static const purple = Color(0xFFC084FC);

  // النصوص
  static const text = Color(0xFFF4EFFA);
  static const text2 = Color(0xFFA99DB7);
  static const text3 = Color(0xFF6F637B);

  // تدرج بطاقة الرصيد الرئيسية
  static const balanceGradient = LinearGradient(
    begin: Alignment.topRight,
    end: Alignment.bottomLeft,
    colors: [Color(0xFFB14BFF), Color(0xFF6825B3)],
  );

  // ألوان دائرية متنوعة للأيقونات (نفس ألوان الموقع، بالتناوب)
  static const List<Color> iconPalette = [accentPurple, purple, primary, cyan, green, red];

  static Color iconColorFor(int index) => iconPalette[index % iconPalette.length];
}
