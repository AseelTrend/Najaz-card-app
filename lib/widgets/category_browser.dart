import 'package:flutter/material.dart';
import '../services/api_service.dart';
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
        // ما فيه أقسام فرعية → إذن هذا قسم نهائي، نعرض خدماته
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
      return const Center(child: CircularProgressIndicator(color: Color(0xFF3B82F6)));
    }
    if (_error != null) {
      return Center(child: Text(_error!, style: const TextStyle(color: Color(0xFFF87171))));
    }

    if (_showServices) {
      if (_services.isEmpty) {
        return RefreshIndicator(
          onRefresh: _load,
          color: const Color(0xFF3B82F6),
          child: ListView(children: const [
            SizedBox(height: 100),
            Center(child: Text('لا توجد خدمات هنا حالياً', style: TextStyle(color: Color(0xFF7C93B5)))),
          ]),
        );
      }
      return RefreshIndicator(
        onRefresh: _load,
        color: const Color(0xFF3B82F6),
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
      return RefreshIndicator(
        onRefresh: _load,
        color: const Color(0xFF3B82F6),
        child: ListView(children: const [
          SizedBox(height: 100),
          Center(child: Text('لا توجد أقسام هنا حالياً', style: TextStyle(color: Color(0xFF7C93B5)))),
        ]),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: const Color(0xFF3B82F6),
      child: GridView.builder(
        padding: const EdgeInsets.all(16),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3,
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 0.9,
        ),
        itemCount: _categories.length,
        itemBuilder: (context, i) => _categoryCard(_categories[i]),
      ),
    );
  }

  Widget _categoryCard(dynamic cat) {
    final hasImage = cat['image'] != null && cat['image'].toString().isNotEmpty;
    return GestureDetector(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CategoryScreen(categoryId: cat['id'], categoryName: cat['name'] ?? ''),
      )),
      child: Container(
        decoration: BoxDecoration(
          color: const Color(0xFF0E1525),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.white.withOpacity(0.07)),
        ),
        padding: const EdgeInsets.all(8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            hasImage
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: Image.network(
                      'https://njaz.net/${cat['image']}',
                      height: 40,
                      width: 40,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) =>
                          const Icon(Icons.folder_rounded, color: Color(0xFF3B82F6), size: 36),
                    ),
                  )
                : const Icon(Icons.folder_rounded, color: Color(0xFF3B82F6), size: 36),
            const SizedBox(height: 8),
            Text(
              cat['name'] ?? '',
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
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
          color: const Color(0xFF0E1525),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.white.withOpacity(0.07)),
        ),
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: (service['image'] != null && service['image'].toString().isNotEmpty)
                    ? Image.network(
                        'https://njaz.net/${service['image']}',
                        fit: BoxFit.cover,
                        width: double.infinity,
                        errorBuilder: (_, __, ___) =>
                            const Icon(Icons.image_not_supported, color: Color(0xFF7C93B5)),
                      )
                    : const Center(child: Icon(Icons.widgets_outlined, color: Color(0xFF7C93B5), size: 32)),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              service['name'] ?? '',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            Text('\$${service['price']}',
                style: const TextStyle(color: Color(0xFF3B82F6), fontSize: 13, fontWeight: FontWeight.bold)),
          ],
        ),
      ),
    );
  }
}
