import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import '../widgets/category_browser.dart';
import 'category_screen.dart';
import 'login_screen.dart';
import 'notifications_screen.dart';
import 'orders_screen.dart';
import 'profile_screen.dart';
import 'support_chat_screen.dart';
import 'topup_screen.dart';

class _BannerData {
  final String title;
  final String subtitle;
  final String tag;
  final IconData icon;
  final List<Color> colors;
  final Color textColor;
  final Color accentColor;
  final String imageUrl;

  const _BannerData({
    required this.title,
    required this.subtitle,
    required this.tag,
    required this.icon,
    required this.colors,
    this.textColor = Colors.white,
    this.accentColor = AppColors.cyan,
    this.imageUrl = '',
  });
}

class _NavItemData {
  final int index;
  final String label;
  final IconData activeIcon;
  final IconData icon;
  const _NavItemData(this.index, this.label, this.activeIcon, this.icon);
}

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
  final PageController _bannerController = PageController();
  Timer? _bannerTimer;
  int _bannerIndex = 0;
  List<_BannerData> _banners = const [];

  @override
  void initState() {
    super.initState();
    _loadUser();
    _loadCategories();
    _loadOrders();
    _loadUnreadNotifications();
    _loadBanners();
    _startBannerTimer();
  }

  @override
  void dispose() {
    _bannerTimer?.cancel();
    _bannerController.dispose();
    super.dispose();
  }

  void _startBannerTimer() {
    _bannerTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!_bannerController.hasClients) return;
      final bannerCount = _banners.isEmpty ? 3 : _banners.length;
      final next = (_bannerIndex + 1) % bannerCount;
      _bannerController.animateToPage(next, duration: const Duration(milliseconds: 450), curve: Curves.easeOutCubic);
    });
  }

  Future<void> _loadBanners() async {
    try {
      final banners = await ApiService.getBanners();
      if (!mounted || banners.isEmpty) return;
      setState(() => _banners = banners.map(_bannerFromApi).toList());
    } catch (_) {}
  }

  _BannerData _bannerFromApi(dynamic raw) {
    final banner = Map<String, dynamic>.from(raw as Map);
    final bg = _gradientColors(banner['bg_color']?.toString());
    return _BannerData(
      title: banner['title']?.toString() ?? '',
      subtitle: banner['subtitle']?.toString() ?? '',
      tag: banner['tag']?.toString() ?? '',
      icon: Icons.campaign_rounded,
      colors: bg,
      textColor: _parseColor(banner['text_color']?.toString(), Colors.white),
      accentColor: _parseColor(banner['accent_color']?.toString(), AppColors.cyan),
      imageUrl: banner['image_url']?.toString() ?? '',
    );
  }

  List<Color> _gradientColors(String? value) {
    final matches = RegExp(r'#[0-9a-fA-F]{6,8}').allMatches(value ?? '').map((match) => _parseColor(match.group(0), AppColors.primary)).toList();
    if (matches.length >= 2) return matches.take(2).toList();
    if (matches.length == 1) return [matches.first, AppColors.bg2];
    return const [AppColors.primary, AppColors.primaryDark];
  }

  Color _parseColor(String? value, Color fallback) {
    if (value == null) return fallback;
    final hex = value.replaceFirst('#', '');
    final normalized = hex.length == 6 ? 'FF$hex' : hex;
    final parsed = int.tryParse(normalized, radix: 16);
    return parsed == null ? fallback : Color(parsed);
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

  Future<void> _openTopup({int initialTab = 0}) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TopupScreen(initialTab: initialTab)));
    _loadUser();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: SafeArea(
        child: _tabIndex == 0 ? _buildHome() : _buildOtherTab(),
      ),
      bottomNavigationBar: _buildBottomNav(),
    );
  }

  // [UI PORT] شريط تنقل سفلي بزر "شحن" دائري عائم في المنتصف — مطابق
  // لتصميم المشروع المرجعي (BottomNav.tsx) بدل NavigationBar الافتراضي.
  Widget _buildBottomNav() {
    final items = <_NavItemData>[
      _NavItemData(0, 'الرئيسية', Icons.home_rounded, Icons.home_outlined),
      _NavItemData(1, 'الخدمات', Icons.grid_view_rounded, Icons.grid_view_outlined),
      _NavItemData(3, 'طلباتي', Icons.shopping_bag_rounded, Icons.shopping_bag_outlined),
      _NavItemData(4, 'حسابي', Icons.person_rounded, Icons.person_outline_rounded),
    ];
    return Container(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).padding.bottom + 8, top: 8, right: 6, left: 6),
      decoration: BoxDecoration(
        color: AppColors.bg2.withOpacity(0.97),
        border: Border(top: BorderSide(color: AppColors.border)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          _navTabButton(items[0]),
          _navTabButton(items[1]),
          // الزر الدائري العائم لشحن الرصيد في المنتصف
          GestureDetector(
            onTap: () => _openTopup(initialTab: 0),
            child: Transform.translate(
              offset: const Offset(0, -22),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 54,
                    height: 54,
                    decoration: BoxDecoration(
                      gradient: AppColors.balanceGradient,
                      shape: BoxShape.circle,
                      border: Border.all(color: AppColors.bg2, width: 4),
                      boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(0.45), blurRadius: 16, offset: const Offset(0, 6))],
                    ),
                    child: const Icon(Icons.add_circle_rounded, color: Colors.white, size: 26),
                  ),
                  const SizedBox(height: 2),
                  const Text('شحن', style: TextStyle(color: AppColors.primary, fontSize: 10, fontWeight: FontWeight.bold)),
                ],
              ),
            ),
          ),
          _navTabButton(items[2]),
          _navTabButton(items[3]),
        ],
      ),
    );
  }

  Widget _navTabButton(_NavItemData item) {
    final isActive = _tabIndex == item.index;
    return InkWell(
      onTap: () => setState(() => _tabIndex = item.index),
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(isActive ? item.activeIcon : item.icon, size: 21, color: isActive ? AppColors.primary : AppColors.text2),
            const SizedBox(height: 3),
            Text(item.label, style: TextStyle(fontSize: 10, fontWeight: isActive ? FontWeight.bold : FontWeight.w600, color: isActive ? AppColors.text : AppColors.text3)),
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
          _buildBannerSlider(),
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
        _roundHeaderButton(Icons.support_agent_rounded, _openSupportChat),
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

  Future<void> _openSupportChat() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SupportChatScreen()));
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

  // [UI PORT] بطاقة الرصيد — مطابقة لتصميم BalanceHero.tsx المرجعي:
  // شارة "حساب موثق"، زر إظهار/إخفاء فعلي، وصف 4 أزرار إجراءات سريعة.
  Widget _buildBalanceHero() {
    return Container(
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Colors.white.withOpacity(0.15)),
        boxShadow: [
          BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 20, offset: const Offset(0, 10)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Text('الرصيد المتاح بالمحفظة', style: TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.w500)),
              const SizedBox(width: 4),
              GestureDetector(
                onTap: () => setState(() => _hideBalance = !_hideBalance),
                child: Icon(_hideBalance ? Icons.visibility_off_rounded : Icons.visibility_rounded, color: Colors.white70, size: 16),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(color: Colors.black.withOpacity(0.2), borderRadius: BorderRadius.circular(20), border: Border.all(color: Colors.white.withOpacity(0.1))),
                child: const Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.verified_user_rounded, color: AppColors.green, size: 13),
                  SizedBox(width: 5),
                  Text('حساب موثق', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
                ]),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _hideBalance
              ? const Text('••••••••', style: TextStyle(color: Colors.white70, fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 3))
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic,
                  children: [
                    Text('$_balance', style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: 0.5)),
                    const SizedBox(width: 6),
                    const Text('ريال يمني', style: TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.w600)),
                  ],
                ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(child: _balanceActionBtn('شحن المحفظة', Icons.add_rounded, filled: true, onTap: () => _openTopup(initialTab: 0))),
              const SizedBox(width: 8),
              Expanded(child: _balanceActionBtn('إيداع USDT', Icons.bolt_rounded, iconColor: AppColors.cyan, onTap: () => _openTopup(initialTab: 1))),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _balanceActionBtn('كود بطاقة', Icons.credit_card_rounded, iconColor: AppColors.gold, onTap: () => _openTopup(initialTab: 2))),
              const SizedBox(width: 8),
              Expanded(child: _balanceActionBtn('سجل الطلبات', Icons.north_east_rounded, onTap: () => setState(() => _tabIndex = 3))),
            ],
          ),
        ],
      ),
    );
  }

  Widget _balanceActionBtn(String label, IconData icon, {bool filled = false, Color? iconColor, required VoidCallback onTap}) {
    return Material(
      color: filled ? Colors.white : Colors.white.withOpacity(0.15),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 11),
          decoration: filled ? null : BoxDecoration(borderRadius: BorderRadius.circular(16), border: Border.all(color: Colors.white.withOpacity(0.15))),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 15, color: filled ? AppColors.primaryDark : (iconColor ?? Colors.white)),
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  label,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: filled ? AppColors.primaryDark : Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBannerSlider() {
    final banners = _banners.isEmpty ? const [
      _BannerData(
        title: 'كروت الشبكات',
        subtitle: 'صارت في الجيب',
        tag: 'متوفر الآن',
        icon: Icons.router_rounded,
        colors: [Color(0xFF123A77), Color(0xFF071B3D)],
      ),
      _BannerData(
        title: 'اشحن ألعابك',
        subtitle: 'بطاقات رقمية بأسعار مميزة',
        tag: 'عروض رقمية',
        icon: Icons.sports_esports_rounded,
        colors: [Color(0xFF3B226D), Color(0xFF171033)],
      ),
      _BannerData(
        title: 'رصيدك جاهز',
        subtitle: 'شحن سريع وآمن من محفظتك',
        tag: 'نجاز كارد بلاس',
        icon: Icons.account_balance_wallet_rounded,
        colors: [Color(0xFF075C5D), Color(0xFF062B3D)],
      ),
    ] : _banners;
    return Container(
      height: 148,
      margin: const EdgeInsets.only(top: 14),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      child: Stack(
        children: [
          PageView.builder(
            controller: _bannerController,
            itemCount: banners.length,
            onPageChanged: (index) => setState(() => _bannerIndex = index),
            itemBuilder: (context, index) => _buildBannerSlide(banners[index]),
          ),
          Positioned(
            bottom: 11,
            left: 0,
            right: 0,
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: List.generate(banners.length, (index) {
              final active = _bannerIndex == index;
              return AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                width: active ? 20 : 6,
                height: 6,
                margin: const EdgeInsets.symmetric(horizontal: 3),
                decoration: BoxDecoration(color: active ? Colors.white : Colors.white38, borderRadius: BorderRadius.circular(6)),
              );
            })),
          ),
        ],
      ),
    );
  }

  Widget _buildBannerSlide(_BannerData banner) {
    // [FIX] عندما تكون هناك صورة إعلانية حقيقية من السيرفر، كانت تُعرض
    // بحجم مربع صغير 108×108 مع BoxFit.cover، فتُقص أغلب التصميم (خصوصاً
    // إن كانت الصورة الأصلية مستطيلة عريضة) — تمامًا مثل صورة "بريميوم"
    // بالمرفق. الآن تُعرض الصورة كاملة العرض على مساحة الشريط بالكامل،
    // بنفس الطريقة الظاهرة بالموقع والمشروع المرجعي. البطاقات الاحتياطية
    // (بلا صورة) لم تتغير إطلاقاً.
    if (banner.imageUrl.isNotEmpty) {
      return Stack(
        fit: StackFit.expand,
        children: [
          CachedNetworkImage(
            imageUrl: banner.imageUrl,
            fit: BoxFit.cover,
            errorWidget: (_, __, ___) => _bannerFallbackCard(banner),
          ),
          if (banner.title.isNotEmpty)
            Container(
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.bottomCenter,
                  end: Alignment.topCenter,
                  colors: [Color(0xB3000000), Colors.transparent],
                  stops: [0.0, 0.55],
                ),
              ),
            ),
          if (banner.title.isNotEmpty)
            Positioned(
              right: 16,
              left: 16,
              bottom: 14,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(banner.title, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor, fontSize: 15, fontWeight: FontWeight.bold)),
                  if (banner.subtitle.isNotEmpty)
                    Text(banner.subtitle, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor.withOpacity(.85), fontSize: 11)),
                ],
              ),
            ),
        ],
      );
    }
    return _bannerFallbackCard(banner);
  }

  Widget _bannerFallbackCard(_BannerData banner) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 16, 18, 22),
      decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: banner.colors)),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (banner.tag.isNotEmpty) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4), decoration: BoxDecoration(color: banner.accentColor.withOpacity(.2), borderRadius: BorderRadius.circular(20)), child: Text(banner.tag, style: TextStyle(color: banner.accentColor, fontSize: 10, fontWeight: FontWeight.bold))),
                const SizedBox(height: 8),
                Text(banner.title, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor, fontSize: 20, fontWeight: FontWeight.bold)),
                const SizedBox(height: 3),
                Text(banner.subtitle, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor.withOpacity(.72), fontSize: 12)),
              ],
            ),
          ),
          const SizedBox(width: 16),
          _bannerIcon(banner),
        ],
      ),
    );
  }

  Widget _bannerIcon(_BannerData banner) => Container(width: 74, height: 74, decoration: BoxDecoration(color: banner.textColor.withOpacity(.13), shape: BoxShape.circle, border: Border.all(color: banner.textColor.withOpacity(.2))), child: Icon(banner.icon, color: banner.accentColor, size: 38));

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
          childAspectRatio: .80,
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
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(12),
          boxShadow: const [BoxShadow(color: Color(0x66000000), blurRadius: 10, offset: Offset(0, 4))],
        ),
        child: Column(
          children: [
            Expanded(
              child: SizedBox.expand(
                child: Container(
                  color: color.withOpacity(.12),
                  child: image.isEmpty
                      ? Icon(Icons.folder_rounded, color: color, size: 34)
                      : CachedNetworkImage(
                          imageUrl: 'https://njaz.net/$image',
                          fit: BoxFit.cover,
                          errorWidget: (_, __, ___) => Icon(Icons.folder_rounded, color: color, size: 34),
                        ),
                ),
              ),
            ),
            Container(
              width: double.infinity,
              color: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 7),
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Color(0xFF0F172A), fontSize: 10, height: 1.3, fontWeight: FontWeight.w800),
              ),
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
