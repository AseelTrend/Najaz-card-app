import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import '../widgets/category_browser.dart';
import 'login_screen.dart';
import 'orders_screen.dart';
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
  bool _hideBalance = false;
  bool _showSyncAlert = true;

  @override
  void initState() {
    super.initState();
    _loadUser();
  }

  Future<void> _loadUser() async {
    final user = await StorageService.getUser();
    setState(() {
      _userName = user['name'] ?? '';
      _balance = user['balance'] ?? '0';
    });
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
          onDestinationSelected: (i) => setState(() => _tabIndex = i),
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_rounded), label: 'الرئيسية'),
            NavigationDestination(icon: Icon(Icons.grid_view_rounded), label: 'الخدمات'),
            NavigationDestination(icon: Icon(Icons.swap_vert_rounded), label: 'تحويل'),
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
      onRefresh: _loadUser,
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
    return Center(child: Text(_tabIndex == 2 ? 'التحويلات' : 'الملف', style: const TextStyle(color: AppColors.text)));
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
        _roundHeaderButton(Icons.notifications_none_rounded, () {}),
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
    const services = [('تحويلات مالية', Icons.swap_vert_rounded), ('حوالات محلية', Icons.receipt_long_outlined), ('الشحن والسداد', Icons.description_outlined), ('شراء اونلاين', Icons.phone_android_rounded), ('دفع المشتريات', Icons.shopping_bag_outlined), ('سحب نقدي', Icons.account_balance_wallet_outlined), ('المدفوعات', Icons.credit_card_outlined), ('خدمات ترفيه', Icons.sports_esports_outlined), ('جيبي', Icons.verified_user_outlined)];
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: GridView.builder(shrinkWrap: true, physics: const NeverScrollableScrollPhysics(), itemCount: services.length, gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 3, crossAxisSpacing: 9, mainAxisSpacing: 9, childAspectRatio: .86), itemBuilder: (context, index) {
        final item = services[index];
        return InkWell(onTap: () => setState(() => _tabIndex = 1), borderRadius: BorderRadius.circular(15), child: Container(decoration: BoxDecoration(color: AppColors.card, border: Border.all(color: AppColors.border), borderRadius: BorderRadius.circular(15)), child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [if (index == 8) Align(alignment: Alignment.topRight, child: Container(margin: const EdgeInsets.only(right: 7), padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2), decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(4)), child: const Text('جديد', style: TextStyle(color: Colors.white, fontSize: 8, fontWeight: FontWeight.bold)))), Container(width: 40, height: 40, decoration: BoxDecoration(shape: BoxShape.circle, color: AppColors.primary.withOpacity(.12), border: Border.all(color: AppColors.primary.withOpacity(.35))), child: Icon(item.$2, color: AppColors.primary, size: 21)), const SizedBox(height: 8), Text(item.$1, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.text, fontSize: 11, fontWeight: FontWeight.w500))])));
      }),
    );
  }

  Widget _buildTransactions() => Column(crossAxisAlignment: CrossAxisAlignment.end, children: [const SizedBox(height: 22), const Text('العمليات', style: TextStyle(color: AppColors.text, fontSize: 18, fontWeight: FontWeight.bold)), const SizedBox(height: 10), ...['447', '442', '440'].map((amount) => Container(margin: const EdgeInsets.only(bottom: 9), padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: AppColors.card, border: Border.all(color: AppColors.border), borderRadius: BorderRadius.circular(15)), child: Row(children: [Text('$amount ريال يمني', style: const TextStyle(color: AppColors.green, fontSize: 14, fontWeight: FontWeight.bold)), const Spacer(), Column(crossAxisAlignment: CrossAxisAlignment.end, children: [const Text('تحويل مشترك', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600)), Text('(05:17) 09/09/2026', style: TextStyle(color: AppColors.text2, fontSize: 10))]), const SizedBox(width: 10), const CircleAvatar(radius: 20, backgroundColor: AppColors.card3, child: Icon(Icons.swap_vert_rounded, color: AppColors.text2, size: 20))])))]);
}
