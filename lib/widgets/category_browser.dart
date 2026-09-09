import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../screens/service_detail_screen.dart';
import '../screens/category_screen.dart';

/// يعرض الأقسام الفرعية والخدمات المباشرة للقسم نفسه معًا.
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
      List<dynamic> services;
      if (widget.categoryId == null && cats.isNotEmpty) {
        final servicesByCategory = await Future.wait(
          cats.map((category) => ApiService.getServices(categoryId: category['id'] as int)),
        );
        services = servicesByCategory.expand((items) => items).toList();
      } else {
        services = await ApiService.getServices(categoryId: widget.categoryId);
      }
      if (!mounted) return;
      setState(() {
        _categories = cats;
        _services = services;
      });
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

    if (_categories.isEmpty && _services.isEmpty) {
      return _emptyState('لا توجد أقسام هنا حالياً');
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: AppColors.primary,
      backgroundColor: AppColors.card,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
        children: [
          if (_categories.isNotEmpty) ...[
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: Text('الأقسام الفرعية', style: TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)),
            ),
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 14,
                crossAxisSpacing: 12,
                childAspectRatio: 0.80,
              ),
              itemCount: _categories.length,
              itemBuilder: (context, i) => _categoryCard(_categories[i], i),
            ),
          ],
          if (_services.isNotEmpty) ...[
            Padding(
              padding: EdgeInsets.only(top: _categories.isEmpty ? 0 : 22, bottom: 10),
              child: Text(_categories.isEmpty ? 'الخدمات' : 'الخدمات المتاحة', style: const TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)),
            ),
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 0.82,
              ),
              itemCount: _services.length,
              itemBuilder: (context, i) => _serviceCard(_services[i]),
            ),
          ],
        ],
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
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.border),
          boxShadow: const [BoxShadow(color: Color(0x66000000), blurRadius: 10, offset: Offset(0, 4))],
        ),
        child: Column(
          children: [
            Expanded(
              child: SizedBox.expand(
                child: Container(
                  color: badgeColor.withOpacity(0.15),
                  child: hasImage
                        ? CachedNetworkImage(
                          imageUrl: 'https://njaz.net/${cat['image']}',
                          fit: BoxFit.cover,
                          errorWidget: (_, __, ___) => Icon(Icons.folder_rounded, color: badgeColor, size: 34),
                        )
                      : Icon(Icons.folder_rounded, color: badgeColor, size: 34),
                ),
              ),
            ),
            Container(
              width: double.infinity,
              color: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 7),
              child: Text(
                cat['name'] ?? '',
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Color(0xFF0F172A), fontSize: 10, height: 1.3, fontWeight: FontWeight.w800),
              ),
            ),
          ],
      ),
    );
  }

  Widget _serviceCard(dynamic service) {
    final serviceImage = service['image']?.toString() ?? '';
    final categoryImage = service['category_image']?.toString() ?? '';
    final fallbackImage = serviceImage.isNotEmpty ? serviceImage : categoryImage;
    final image = fallbackImage.isEmpty
        ? _servicePlaceholder()
        : CachedNetworkImage(
            imageUrl: 'https://njaz.net/$fallbackImage',
            fit: BoxFit.cover,
            width: double.infinity,
            height: double.infinity,
            errorWidget: (_, __, ___) => _servicePlaceholder(),
          );
    return GestureDetector(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ServiceDetailScreen(serviceId: service['id'])),
      ),
      child: Column(
        children: [
          Expanded(
            child: AspectRatio(
              aspectRatio: 1,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Container(color: AppColors.card2, child: image),
              ),
            ),
          ),
          const SizedBox(height: 5),
          Text(
            service['name'] ?? '',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: const TextStyle(color: AppColors.text2, fontSize: 10, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }

  Widget _servicePlaceholder() => const Center(child: Icon(Icons.widgets_rounded, color: AppColors.text2, size: 30));
}
