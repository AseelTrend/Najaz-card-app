import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import '../widgets/category_browser.dart';
import 'category_screen.dart';
import 'login_screen.dart';
import 'notifications_screen.dart';
import 'orders_screen.dart';
import 'profile_screen.dart';
import 'wallet_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tabIndex = 0;
  String _userName = '';
  String _balance = '0';
  int _unreadNotificationCount = 0;
  bool _hideBalance = false;
  bool _showSyncAlert = true;
  List<dynamic> _categories = [];
  bool _categoriesLoading = true;
  String? _categoriesError;
  List<dynamic> _orders = [];
  bool _ordersLoading = true;
  String? _ordersError;

  @override
  void initState() {
    super.initState();
    _loadUser();
    _loadCategories();
    _loadOrders();
    _loadUnreadNotifications();
  }

  Future<void> _loadUser() async {
    final user = await StorageService.getUser();
    setState(() {
      _userName = user['name'] ?? '';
      _balance = user['balance'] ?? '0';
    });
  }

  Future<void> _loadCategories() async {
    setState(() {
      _categoriesLoading = true;
      _categoriesError = null;
    });
    try {
      final categories = await ApiService.getCategories();
      if (!mounted) return;
      setState(() => _categories = categories);
    } catch (_) {
      if (!mounted) return;
      setState(() => _categoriesError = 'تعذر تحميل أقسام الموقع');
    } finally {
      if (mounted) setState(() => _categoriesLoading = false);
    }
  }

  Future<void> _loadOrders() async {
    setState(() {
      _ordersLoading = true;
      _ordersError = null;
    });
    try {
      final orders = await ApiService.getOrders();
      if (!mounted) return;
      setState(() => _orders = orders);
    } catch (_) {
      if (!mounted) return;
      setState(() => _ordersError = 'تعذر تحميل الطلبات');
    } finally {
      if (mounted) setState(() => _ordersLoading = false);
    }
  }

  Future<void> _loadUnreadNotifications() async {
    try {
      final count = await ApiService.getUnreadNotificationCount();
      if (mounted) setState(() => _unreadNotificationCount = count);
    } catch (_) {}
  }

  Future<void> _openNotifications() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen()));
    _loadUnreadNotifications();
  }

  Future<void> _logout() async {
    await ApiService.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  Future<void> _openWallet() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const WalletScreen()));
    try {
      final wallet = await ApiService.getWallet();
      final current = await StorageService.getUser();
      await StorageService.saveUser({
        'name': current['name'],
        'uid': current['uid'],
        'balance': (wallet['balance'] ?? current['balance']).toString(),
      });
    } catch (_) {}
    _loadUser();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: SafeArea(
        child: _tabIndex == 0 ? _buildHome() : _buildOtherTab(),
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.card,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: NavigationBar(
          selectedIndex: _tabIndex,
          onDestinationSelected: (i) {
            if (i == 2) {
              _openWallet();
              return;
            }
            setState(() => _tabIndex = i);
          },
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_rounded), label: 'الرئيسية'),
            NavigationDestination(icon: Icon(Icons.grid_view_rounded), label: 'الخدمات'),
            NavigationDestination(icon: Icon(Icons.add_card_rounded), label: 'شحن الرصيد'),
            NavigationDestination(icon: Icon(Icons.receipt_long_rounded), label: 'التقارير'),
            NavigationDestination(icon: Icon(Icons.person_outline_rounded), label: 'الملف'),
          ],
        ),
      ),
    );
  }

  Widget _buildHome() {
    return RefreshIndicator(
      color: AppColors.primary,
      backgroundColor: AppColors.card,
      onRefresh: () async {
        await Future.wait([_loadUser(), _loadCategories(), _loadOrders()]);
      },
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 30),
        children: [
          _buildHeader(),
          if (_showSyncAlert) _buildSyncAlert(),
          _buildBalanceHero(),
          _buildPromoBanner(),
          _buildQuickServices(),
          _buildTransactions(),
        ],
      ),
    );
  }

  Widget _buildOtherTab() {
    if (_tabIndex == 1) return const CategoryBrowser(categoryId: null);
    if (_tabIndex == 3) return const OrdersScreen();
    if (_tabIndex == 4) return ProfileScreen(onLogout: _logout);
    return const Center(child: Text('التحويلات', style: TextStyle(color: AppColors.text)));
  }

  Widget _buildHeader() {
    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              const Text('صباح الخير', style: TextStyle(color: AppColors.text, fontSize: 20, fontWeight: FontWeight.bold)),
              Text(_userName.isEmpty ? 'أصيل' : _userName, style: const TextStyle(color: AppColors.text2, fontSize: 14)),
            ],
          ),
        ),
        _roundHeaderButton(Icons.support_agent_rounded, () {}),
        const SizedBox(width: 8),
        _notificationButton(),
      ],
    );
  }

  Widget _notificationButton() {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        _roundHeaderButton(Icons.notifications_none_rounded, _openNotifications),
        if (_unreadNotificationCount > 0)
          Positioned(
            top: -4,
            left: -4,
            child: Container(
              constraints: const BoxConstraints(minWidth: 17, minHeight: 17),
              padding: const EdgeInsets.symmetric(horizontal: 4),
              alignment: Alignment.center,
              decoration: const BoxDecoration(color: AppColors.red, shape: BoxShape.circle),
              child: Text(
                _unreadNotificationCount > 99 ? '99+' : '$_unreadNotificationCount',
                style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
              ),
            ),
          ),
      ],
    );
  }

  Widget _roundHeaderButton(IconData icon, VoidCallback onTap) {
    return IconButton(
      onPressed: onTap,
      icon: Icon(icon, color: AppColors.text2, size: 21),
      style: IconButton.styleFrom(
        backgroundColor: AppColors.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: AppColors.border)),
        fixedSize: const Size(42, 42),
      ),
    );
  }

  Widget _buildSyncAlert() {
    return Container(
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(color: const Color(0xFF291529), border: Border.all(color: const Color(0xFF5D2E68)), borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: [
          IconButton(onPressed: () => setState(() => _showSyncAlert = false), icon: const Icon(Icons.close, size: 15, color: AppColors.text2), padding: EdgeInsets.zero, constraints: const BoxConstraints(minWidth: 24)),
          const Expanded(child: Text('اضغط هنا لمزامنة الاقتراحات والمفضلة بين أجهزتك', textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Color(0xFFD99BFF), fontSize: 11))),
          const Icon(Icons.sync_rounded, color: AppColors.primary, size: 18),
        ],
      ),
    );
  }

  Widget _buildBalanceHero() {
    return Container(
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 20, offset: const Offset(0, 10)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Expanded(child: Text('حساب', style: TextStyle(color: Color(0xFFE9D7FF), fontSize: 11))),
              Row(children: [Container(width: 28, height: 28, decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2)), child: const Center(child: Text('ن', style: TextStyle(fontWeight: FontWeight.bold)))), const SizedBox(width: 6), const Text('نجاز', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold))]),
            ],
          ),
          const SizedBox(height: 22),
          Row(
            children: [
              Text(_hideBalance ? '•••••' : _balance, style: const TextStyle(color: Colors.white, fontSize: 27, fontWeight: FontWeight.bold, letterSpacing: 2)),
              const SizedBox(width: 10),
              GestureDetector(
                onTap: () => setState(() => _hideBalance = !_hideBalance),
                child: Icon(
                  Icons.visibility_off_outlined,
                  color: Colors.white70,
                  size: 20,
                ),
              ),
              const Spacer(),
              const SizedBox.shrink(),
            ],
          ),
          const Align(alignment: Alignment.centerRight, child: Text('ريال يمني', style: TextStyle(color: Colors.white70, fontSize: 12))),
        ],
      ),
    );
  }

  Widget _buildPromoBanner() => Container(
        margin: const EdgeInsets.only(top: 14),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        decoration: BoxDecoration(color: const Color(0xFF17131B), border: Border.all(color: AppColors.border), borderRadius: BorderRadius.circular(15)),
        child: Row(children: [const Icon(Icons.router_rounded, color: AppColors.primary, size: 43), const Spacer(), Column(crossAxisAlignment: CrossAxisAlignment.end, children: const [Text('كروت الشبكات', style: TextStyle(color: AppColors.primary, fontSize: 15, fontWeight: FontWeight.bold)), Text('صارت في الجيب', style: TextStyle(color: AppColors.text, fontSize: 12))])]),
      );

  Widget _buildQuickServices() {
    if (_categoriesLoading) {
      return const Padding(
        padding: EdgeInsets.only(top: 32),
        child: Center(child: CircularProgressIndicator(color: AppColors.primary)),
      );
    }
    if (_categoriesError != null) {
      return Padding(
        padding: const EdgeInsets.only(top: 22),
        child: Center(child: Text(_categoriesError!, style: const TextStyle(color: AppColors.red))),
      );
    }
    if (_categories.isEmpty) {
      return const Padding(
        padding: EdgeInsets.only(top: 22),
        child: Center(child: Text('لا توجد أقسام متاحة حالياً', style: TextStyle(color: AppColors.text2))),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        itemCount: _categories.length,
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3,
          crossAxisSpacing: 9,
          mainAxisSpacing: 9,
          childAspectRatio: .86,
        ),
        itemBuilder: (context, index) => _buildCategoryCard(_categories[index], index),
      ),
    );
  }

  Widget _buildCategoryCard(dynamic category, int index) {
    final image = category['image']?.toString() ?? '';
    final title = category['name']?.toString() ?? 'قسم';
    final color = AppColors.iconColorFor(index);
    return InkWell(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CategoryScreen(categoryId: category['id'], categoryName: title),
      )),
      borderRadius: BorderRadius.circular(15),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.card,
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(15),
        ),
        padding: const EdgeInsets.all(8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: color.withOpacity(.12),
                border: Border.all(color: color.withOpacity(.35)),
              ),
              child: image.isEmpty
                  ? Icon(Icons.folder_rounded, color: color, size: 21)
                  : ClipOval(
                      child: Image.network(
                        'https://njaz.net/$image',
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Icon(Icons.folder_rounded, color: color, size: 21),
                      ),
                    ),
            ),
            const SizedBox(height: 8),
            Text(
              title,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.text, fontSize: 11, fontWeight: FontWeight.w500),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTransactions() {
    if (_ordersLoading) {
      return const Padding(
        padding: EdgeInsets.only(top: 28),
        child: Center(child: CircularProgressIndicator(color: AppColors.primary)),
      );
    }
    if (_ordersError != null) {
      return Padding(
        padding: const EdgeInsets.only(top: 22),
        child: Center(child: Text(_ordersError!, style: const TextStyle(color: AppColors.red))),
      );
    }
    if (_orders.isEmpty) {
      return const Padding(
        padding: EdgeInsets.only(top: 22),
        child: Center(child: Text('لا توجد طلبات بعد', style: TextStyle(color: AppColors.text2))),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        const SizedBox(height: 22),
        const Text('الطلبات', style: TextStyle(color: AppColors.text, fontSize: 18, fontWeight: FontWeight.bold)),
        const SizedBox(height: 10),
        ..._orders.take(5).map(_buildOrderCard),
      ],
    );
  }

  Widget _buildOrderCard(dynamic order) {
    final status = order['status']?.toString() ?? '';
    final statusColor = status == 'completed' ? AppColors.green : status == 'rejected' || status == 'failed' ? AppColors.red : AppColors.gold;
    final title = order['service_name']?.toString() ?? 'طلب خدمة';
    final amount = order['total_price']?.toString() ?? '0';
    final date = order['created_at']?.toString() ?? order['date']?.toString() ?? '';
    return Container(
      margin: const EdgeInsets.only(bottom: 9),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: AppColors.card, border: Border.all(color: AppColors.border), borderRadius: BorderRadius.circular(15)),
      child: Row(
        children: [
          Text('$amount ريال يمني', style: TextStyle(color: statusColor, fontSize: 13, fontWeight: FontWeight.bold)),
          const Spacer(),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600)),
              Text(date.isEmpty ? _orderStatusLabel(status) : date, style: TextStyle(color: AppColors.text2, fontSize: 10)),
            ],
          ),
          const SizedBox(width: 10),
          CircleAvatar(radius: 20, backgroundColor: AppColors.card3, child: Icon(Icons.receipt_long_rounded, color: statusColor, size: 20)),
        ],
      ),
    );
  }

  String _orderStatusLabel(String status) {
    switch (status) {
      case 'completed':
        return 'مكتمل';
      case 'pending':
        return 'قيد الانتظار';
      case 'processing':
        return 'قيد التنفيذ';
      case 'rejected':
        return 'مرفوض';
      case 'failed':
        return 'فشل';
      default:
        return status.isEmpty ? 'طلب جديد' : status;
    }
  }
}
