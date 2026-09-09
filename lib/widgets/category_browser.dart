import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../screens/service_detail_screen.dart';
import '../screens/category_screen.dart';

/// يعرض إما الأقسام الفرعية لقسم معيّن (إذا وجدت) أو خدماته مباشرة
/// (إذا كان هذا القسم "ورقة أخيرة" بدون أقسام فرعية).
/// categoryId = null يعني الأقسام الرئيسية بالموقع.
class CategoryBrowser extends StatefulWidget {
  final int? categoryId;
  const CategoryBrowser({super.key, this.categoryId});

  @override
  State<CategoryBrowser> createState() => _CategoryBrowserState();
}

class _CategoryBrowserState extends State<CategoryBrowser> {
  List<dynamic> _categories = [];
  List<dynamic> _services = [];
  bool _loading = true;
  bool _showServices = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final cats = await ApiService.getCategories(parentId: widget.categoryId);
      if (cats.isNotEmpty) {
        setState(() {
          _categories = cats;
          _showServices = false;
        });
      } else {
        final services = await ApiService.getServices(categoryId: widget.categoryId);
        setState(() {
          _services = services;
          _showServices = true;
        });
      }
    } catch (e) {
      setState(() => _error = 'تعذر تحميل البيانات، تحقق من الإنترنت');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator(color: AppColors.primary));
    }
    if (_error != null) {
      return Center(child: Text(_error!, style: const TextStyle(color: AppColors.red)));
    }

    if (_showServices) {
      if (_services.isEmpty) {
        return _emptyState('لا توجد خدمات هنا حالياً');
      }
      return RefreshIndicator(
        onRefresh: _load,
        color: AppColors.primary,
        backgroundColor: AppColors.card,
        child: GridView.builder(
          padding: const EdgeInsets.all(16),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 0.85,
          ),
          itemCount: _services.length,
          itemBuilder: (context, i) => _serviceCard(_services[i]),
        ),
      );
    }

    if (_categories.isEmpty) {
      return _emptyState('لا توجد أقسام هنا حالياً');
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: AppColors.primary,
      backgroundColor: AppColors.card,
      child: GridView.builder(
        padding: const EdgeInsets.all(16),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3,
          mainAxisSpacing: 14,
          crossAxisSpacing: 12,
          childAspectRatio: 0.85,
        ),
        itemCount: _categories.length,
        itemBuilder: (context, i) => _categoryCard(_categories[i], i),
      ),
    );
  }

  Widget _emptyState(String msg) {
    return RefreshIndicator(
      onRefresh: _load,
      color: AppColors.primary,
      backgroundColor: AppColors.card,
      child: ListView(children: [
        const SizedBox(height: 100),
        Center(child: Text(msg, style: const TextStyle(color: AppColors.text2))),
      ]),
    );
  }

  Widget _categoryCard(dynamic cat, int index) {
    final hasImage = cat['image'] != null && cat['image'].toString().isNotEmpty;
    final badgeColor = AppColors.iconColorFor(index);
    return GestureDetector(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CategoryScreen(categoryId: cat['id'], categoryName: cat['name'] ?? ''),
      )),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.border),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                color: badgeColor.withOpacity(0.15),
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: badgeColor.withOpacity(0.3)),
              ),
              child: hasImage
                  ? ClipRRect(
                      borderRadius: BorderRadius.circular(17),
                      child: Image.network(
                        'https://njaz.net/${cat['image']}',
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Icon(Icons.folder_rounded, color: badgeColor, size: 28),
                      ),
                    )
                  : Icon(Icons.folder_rounded, color: badgeColor, size: 28),
            ),
            const SizedBox(height: 8),
            Text(
              cat['name'] ?? '',
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: AppColors.text, fontSize: 12, fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
    );
  }

  Widget _serviceCard(dynamic service) {
    return GestureDetector(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ServiceDetailScreen(serviceId: service['id'])),
      ),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.border),
        ),
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: (service['image'] != null && service['image'].toString().isNotEmpty)
                    ? Image.network(
                        'https://njaz.net/${service['image']}',
                        fit: BoxFit.cover,
                        width: double.infinity,
                        errorBuilder: (_, __, ___) =>
                            const Icon(Icons.image_not_supported, color: AppColors.text2),
                      )
                    : Container(
                        color: AppColors.card2,
                        child: const Center(child: Icon(Icons.widgets_rounded, color: AppColors.text2, size: 30)),
                      ),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              service['name'] ?? '',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.15),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text('\$${service['price']}',
                  style: const TextStyle(color: AppColors.primary, fontSize: 12, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );
  }
}
