import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../widgets/category_browser.dart';

/// شاشة قابلة لإعادة الاستخدام لأي مستوى داخل شجرة الأقسام —
/// كل ضغطة على قسم فرعي تفتح نسخة جديدة من نفس الشاشة (Navigator.push)
/// فيصير عندنا تنقّل طبيعي بالأقسام مع زر رجوع تلقائي.
class CategoryScreen extends StatelessWidget {
  final int categoryId;
  final String categoryName;
  const CategoryScreen({super.key, required this.categoryId, required this.categoryName});

  @override
  Widget build(BuildContext context) {
    // [FIX] كانت الشاشة تستخدم ألوان داكنة ثابتة (Color(0xFF090D1A) وأبيض)
    // بدل AppColors، فتبقى داكنة دائماً بغضّ النظر عن الوضع الليلي/النهاري
    // المُفعّل بباقي التطبيق. الآن تتبع نفس نظام الألوان الفعلي.
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        iconTheme: IconThemeData(color: AppColors.text),
        title: Text(categoryName, style: TextStyle(color: AppColors.text, fontSize: 16)),
      ),
      body: SafeArea(child: CategoryBrowser(categoryId: categoryId)),
    );
  }
}
