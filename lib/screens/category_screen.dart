import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../widgets/category_browser.dart';
import 'telecom_topup_screen.dart';

/// شاشة قابلة لإعادة الاستخدام لأي مستوى داخل شجرة الأقسام.
class CategoryScreen extends StatelessWidget {
  final int categoryId;
  final String categoryName;
  const CategoryScreen({super.key, required this.categoryId, required this.categoryName});

  @override
  Widget build(BuildContext context) {
    // كبينة السداد لها واجهة اتصالات مستقلة، بينما بقية الأقسام تبقى كما هي.
    if (categoryName.trim() == 'كبينة السداد') {
      return TelecomTopupScreen(categoryId: categoryId, categoryName: categoryName);
    }
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
