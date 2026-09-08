import 'package:flutter/material.dart';
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
    return Scaffold(
      backgroundColor: const Color(0xFF090D1A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF090D1A),
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
        title: Text(categoryName, style: const TextStyle(color: Colors.white, fontSize: 16)),
      ),
      body: SafeArea(child: CategoryBrowser(categoryId: categoryId)),
    );
  }
}
