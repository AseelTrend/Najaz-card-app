import 'dart:async';
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

  // [UI PORT] بحث فوري عبر كل الخدمات — مطابق لخاصية البحث في
  // CategoryBrowser.tsx المرجعي (كانت غائبة تماماً عن نسخة Flutter).
  final _searchController = TextEditingController();
  String _searchQuery = '';
  List<dynamic> _searchResults = [];
  bool _searchLoading = false;
  List<dynamic>? _allServicesCache;
  Timer? _debounce;

  bool get _isSearching => _searchQuery.trim().isNotEmpty;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    setState(() => _searchQuery = value);
    _debounce?.cancel();
    if (value.trim().isEmpty) {
      setState(() => _searchResults = []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), _performSearch);
  }

  Future<void> _performSearch() async {
    final q = _searchQuery.trim().toLowerCase();
    if (q.isEmpty) return;
    setState(() => _searchLoading = true);
    try {
      final pool = _allServicesCache ?? await ApiService.getServices();
      _allServicesCache ??= pool;
      final matches = pool.where((s) {
        final name = (s['name']?.toString() ?? '').toLowerCase();
        final desc = (s['description']?.toString() ?? '').toLowerCase();
        return name.contains(q) || desc.contains(q);
      }).toList();
      if (!mounted) return;
      setState(() => _searchResults = matches);
    } catch (_) {
      if (mounted) setState(() => _searchResults = []);
    } finally {
      if (mounted) setState(() => _searchLoading = false);
    }
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

  Widget _searchBar() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
      child: TextField(
        controller: _searchController,
        onChanged: _onSearchChanged,
        style: const TextStyle(color: AppColors.text, fontSize: 13),
        decoration: InputDecoration(
          hintText: 'ابحث في آلاف الخدمات والباقات الفورية...',
          hintStyle: const TextStyle(color: AppColors.text3, fontSize: 12),
          prefixIcon: const Icon(Icons.search_rounded, color: AppColors.text3, size: 20),
          suffixIcon: _isSearching
              ? IconButton(
                  icon: const Icon(Icons.close_rounded, color: AppColors.text2, size: 18),
                  onPressed: () {
                    _searchController.clear();
                    _onSearchChanged('');
                  },
                )
              : null,
        ),
      ),
    );
  }

  Widget _searchResultsView() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(child: Text('نتائج البحث عن: "$_searchQuery"', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold))),
              Text(_searchLoading ? 'جاري البحث...' : '${_searchResults.length} نتيجة', style: const TextStyle(color: AppColors.text2, fontSize: 11)),
            ],
          ),
          const SizedBox(height: 14),
          if (_searchLoading)
            const Padding(padding: EdgeInsets.only(top: 40), child: Center(child: CircularProgressIndicator(color: AppColors.primary)))
          else if (_searchResults.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 30),
              child: Column(children: const [
                Icon(Icons.search_off_rounded, color: AppColors.text3, size: 34),
                SizedBox(height: 10),
                Text('لم نتمكن من العثور على خدمات مطابقة', style: TextStyle(color: AppColors.text2, fontSize: 12, fontWeight: FontWeight.bold)),
                SizedBox(height: 4),
                Text('جرب كتابة اسم اللعبة أو الخدمة بشكل مختصر', style: TextStyle(color: AppColors.text3, fontSize: 11)),
              ]),
            )
          else
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 0.82,
              ),
              itemCount: _searchResults.length > 60 ? 60 : _searchResults.length,
              itemBuilder: (context, i) => _serviceCard(_searchResults[i]),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isSearching) {
      return Column(children: [_searchBar(), Expanded(child: SingleChildScrollView(child: _searchResultsView()))]);
    }

    if (_loading) {
      return Column(children: [_searchBar(), const Expanded(child: Center(child: CircularProgressIndicator(color: AppColors.primary)))]);
    }
    if (_error != null) {
      return Column(children: [_searchBar(), Expanded(child: Center(child: Text(_error!, style: const TextStyle(color: AppColors.red))))]);
    }

    if (_categories.isEmpty && _services.isEmpty) {
      return Column(children: [_searchBar(), Expanded(child: _emptyState('لا توجد أقسام هنا حالياً'))]);
    }

    return Column(children: [
      _searchBar(),
      Expanded(
        child: RefreshIndicator(
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
        ),
      ),
    ]);
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
