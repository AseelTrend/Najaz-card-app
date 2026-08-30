import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import 'login_screen.dart';
import 'service_detail_screen.dart';
import 'orders_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tabIndex = 0;
  List<dynamic> _categories = [];
  List<dynamic> _services = [];
  int? _selectedCategoryId;
  bool _loading = true;
  String? _error;
  String _userName = '';
  String _balance = '0';

  @override
  void initState() {
    super.initState();
    _loadAll();
  }

  Future<void> _loadAll() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final user = await StorageService.getUser();
      final cats = await ApiService.getCategories();
      final services = await ApiService.getServices();
      setState(() {
        _userName = user['name'] ?? '';
        _balance = user['balance'] ?? '0';
        _categories = cats;
        _services = services;
      });
    } catch (e) {
      setState(() => _error = 'تعذر تحميل البيانات، تحقق من الإنترنت');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _filterByCategory(int? categoryId) async {
    setState(() {
      _selectedCategoryId = categoryId;
      _loading = true;
    });
    try {
      final services = await ApiService.getServices(categoryId: categoryId);
      setState(() => _services = services);
    } catch (e) {
      setState(() => _error = 'تعذر تحميل الخدمات');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _logout() async {
    await ApiService.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF090D1A),
      body: SafeArea(
        child: _tabIndex == 0 ? _buildHome() : const OrdersScreen(),
      ),
      bottomNavigationBar: BottomNavigationBar(
        backgroundColor: const Color(0xFF0E1525),
        selectedItemColor: const Color(0xFF3B82F6),
        unselectedItemColor: const Color(0xFF7C93B5),
        currentIndex: _tabIndex,
        onTap: (i) => setState(() => _tabIndex = i),
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.home_outlined), label: 'الرئيسية'),
          BottomNavigationBarItem(icon: Icon(Icons.receipt_long_outlined), label: 'طلباتي'),
        ],
      ),
    );
  }

  Widget _buildHome() {
    return RefreshIndicator(
      onRefresh: _loadAll,
      color: const Color(0xFF3B82F6),
      backgroundColor: const Color(0xFF0E1525),
      child: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(child: _buildHeader()),
          if (_loading)
            const SliverFillRemaining(
              child: Center(child: CircularProgressIndicator(color: Color(0xFF3B82F6))),
            )
          else if (_error != null)
            SliverFillRemaining(
              child: Center(
                child: Text(_error!, style: const TextStyle(color: Color(0xFFF87171))),
              ),
            )
          else ...[
            SliverToBoxAdapter(child: _buildCategoriesRow()),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverGrid(
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: 0.85,
                ),
                delegate: SliverChildBuilderDelegate(
                  (context, i) => _serviceCard(_services[i]),
                  childCount: _services.length,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildHeader() {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('مرحباً، $_userName',
                    style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                Text('رصيدك: \$$_balance',
                    style: const TextStyle(color: Color(0xFF34D399), fontSize: 14)),
              ],
            ),
          ),
          IconButton(
            onPressed: _logout,
            icon: const Icon(Icons.logout, color: Color(0xFF7C93B5)),
          ),
        ],
      ),
    );
  }

  Widget _buildCategoriesRow() {
    return SizedBox(
      height: 90,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        itemCount: _categories.length + 1,
        itemBuilder: (context, i) {
          if (i == 0) {
            return _categoryChip('الكل', null, _selectedCategoryId == null);
          }
          final cat = _categories[i - 1];
          return _categoryChip(cat['name'] ?? '', cat['id'], _selectedCategoryId == cat['id']);
        },
      ),
    );
  }

  Widget _categoryChip(String name, int? id, bool selected) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: GestureDetector(
        onTap: () => _filterByCategory(id),
        child: Container(
          width: 74,
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: selected ? const Color(0xFF3B82F6) : const Color(0xFF151F35),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.category_outlined, color: selected ? Colors.white : const Color(0xFF7C93B5), size: 20),
              const SizedBox(height: 6),
              Text(name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: selected ? Colors.white : const Color(0xFF7C93B5), fontSize: 11)),
            ],
          ),
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
                        errorBuilder: (_, __, ___) => const Icon(Icons.image_not_supported, color: Color(0xFF7C93B5)),
                      )
                    : const Center(child: Icon(Icons.widgets_outlined, color: Color(0xFF7C93B5), size: 32)),
              ),
            ),
            const SizedBox(height: 8),
            Text(service['name'] ?? '',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 4),
            Text('\$${service['price']}',
                style: const TextStyle(color: Color(0xFF3B82F6), fontSize: 13, fontWeight: FontWeight.bold)),
          ],
        ),
      ),
    );
  }
}
