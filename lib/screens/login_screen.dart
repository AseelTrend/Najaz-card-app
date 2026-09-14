import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import 'home_screen.dart';
import 'register_screen.dart';
import 'forgot_password_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

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

class _LoginScreenState extends State<LoginScreen> {
  final _loginCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _totpCtrl = TextEditingController();
  bool _loading = false;
  bool _need2fa = false;
  bool _obscurePassword = true;
  String? _error;

  final PageController _bannerController = PageController();
  Timer? _bannerTimer;
  int _bannerIndex = 0;
  List<_BannerData> _banners = const [];

  @override
  void initState() {
    super.initState();
    _loadBanners();
    _startBannerTimer();
  }

  @override
  void dispose() {
    _bannerTimer?.cancel();
    _bannerController.dispose();
    _loginCtrl.dispose();
    _passCtrl.dispose();
    _totpCtrl.dispose();
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

  Future<void> _openGoogleAuth() async {
    final uri = Uri.parse('https://njaz.net/auth/google/redirect.php');
    final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!opened && mounted) setState(() => _error = 'تعذر فتح تسجيل الدخول عبر Google');
  }

  Future<void> _submit() async {
    if (_loginCtrl.text.trim().isEmpty || _passCtrl.text.isEmpty) {
      setState(() => _error = 'أدخل اسم المستخدم وكلمة المرور');
      return;
    }
    setState(() { _loading = true; _error = null; });
    try {
      await ApiService.login(login: _loginCtrl.text.trim(), password: _passCtrl.text, totpCode: _need2fa ? _totpCtrl.text.trim() : null);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const HomeScreen()), (route) => false);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        if (e.data['need_2fa'] == true) _need2fa = true;
        if (e.data['device_pending'] == true) _error = '${e.message}\nراجع بريدك الإلكتروني أو واتساب لتفعيل هذا الجهاز.';
      });
    } catch (e) { setState(() => _error = 'تعذر الاتصال بالسيرفر، تحقق من الإنترنت'); }
    finally { if (mounted) setState(() => _loading = false); }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = AppColors.isDark;
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Stack(
        children: [
          Positioned(top: -100, right: -80, child: Container(width: 300, height: 300, decoration: BoxDecoration(shape: BoxShape.circle, boxShadow: [BoxShadow(color: AppColors.primary.withOpacity(isDark ? 0.12 : 0.06), blurRadius: 100, spreadRadius: 50)]))),
          Positioned(bottom: -120, left: -80, child: Container(width: 300, height: 300, decoration: BoxDecoration(shape: BoxShape.circle, boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(isDark ? 0.10 : 0.05), blurRadius: 120, spreadRadius: 60)]))),
          SafeArea(
            child: CustomScrollView(
              slivers: [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Align(alignment: Alignment.topLeft, child: Material(color: Colors.transparent, child: InkWell(borderRadius: BorderRadius.circular(16), onTap: () async { final nextMode = !AppColors.isDark; AppColors.setDark(nextMode); await StorageService.saveSetting('dark_mode', '$nextMode'); setState(() {}); }, child: Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border), boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 10, offset: const Offset(0, 4))]), child: AnimatedSwitcher(duration: const Duration(milliseconds: 300), transitionBuilder: (child, anim) => ScaleTransition(scale: anim, child: child), child: Icon(isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded, key: ValueKey<bool>(isDark), color: isDark ? AppColors.gold : AppColors.primary, size: 22))))),
                      const Spacer(),
                      _buildBannerSlider(),
                      const SizedBox(height: 24),
                      Text('تسجيل الدخول', textAlign: TextAlign.center, style: TextStyle(color: AppColors.text, fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: -0.5)),
                      const SizedBox(height: 8),
                      Text('مرحباً بعودتك 👋 سجّل دخولك للوصول إلى حسابك', textAlign: TextAlign.center, style: TextStyle(color: AppColors.text2, fontSize: 13.5, height: 1.4)),
                      const SizedBox(height: 32),
                      Container(
                        padding: const EdgeInsets.all(22),
                        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(28), border: Border.all(color: AppColors.border, width: 1.2), boxShadow: [BoxShadow(color: isDark ? Colors.black.withOpacity(0.3) : Colors.black.withOpacity(0.04), blurRadius: 30, offset: const Offset(0, 15))]),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          _field(controller: _loginCtrl, label: 'اسم المستخدم أو البريد الإلكتروني', icon: Icons.person_outline_rounded),
                          const SizedBox(height: 16),
                          _field(controller: _passCtrl, label: 'كلمة المرور', icon: Icons.lock_outline_rounded, obscure: _obscurePassword, isPassword: true, onToggleObscure: () => setState(() => _obscurePassword = !_obscurePassword)),
                          Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: _loading ? null : () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ForgotPasswordScreen())), style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4)), child: const Text('نسيت كلمة المرور؟', style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.bold, fontSize: 13)))),
                          if (_need2fa) ...[const SizedBox(height: 8), _field(controller: _totpCtrl, label: 'رمز المصادقة الثنائية', icon: Icons.security_rounded, keyboardType: TextInputType.number)],
                          if (_error != null) ...[const SizedBox(height: 16), Container(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12), decoration: BoxDecoration(color: AppColors.red.withOpacity(0.08), borderRadius: BorderRadius.circular(14), border: Border.all(color: AppColors.red.withOpacity(0.25))), child: Row(children: [const Icon(Icons.error_outline_rounded, color: AppColors.red, size: 20), const SizedBox(width: 10), Expanded(child: Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 12.5, height: 1.4, fontWeight: FontWeight.w600)))]))],
                          const SizedBox(height: 24),
                          _gradientButton(label: 'دخول', loading: _loading, onPressed: _submit),
                          const SizedBox(height: 14),
                          OutlinedButton.icon(onPressed: _loading ? null : _openGoogleAuth, icon: const Icon(Icons.account_circle_outlined), label: const Text('تسجيل الدخول باستخدام Google'), style: OutlinedButton.styleFrom(foregroundColor: AppColors.text, side: BorderSide(color: AppColors.border), minimumSize: const Size.fromHeight(52), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)))),
                        ]),
                      ),
                      const Spacer(),
                      const SizedBox(height: 24),
                      Row(mainAxisAlignment: MainAxisAlignment.center, children: [Text('ليس لديك حساب؟', style: TextStyle(color: AppColors.text2, fontSize: 13.5)), TextButton(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RegisterScreen())), style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8)), child: const Text('إنشاء حساب جديد', style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.bold, fontSize: 13.5)))]),
                      const SizedBox(height: 16),
                    ]),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBannerSlider() {
    final banners = _banners.isEmpty ? const [
      _BannerData(title: 'كروت الشبكات', subtitle: 'صارت في الجيب', tag: 'متوفر الآن', icon: Icons.router_rounded, colors: [Color(0xFF123A77), Color(0xFF071B3D)]),
      _BannerData(title: 'اشحن ألعابك', subtitle: 'بطاقات رقمية بأسعار مميزة', tag: 'عروض رقمية', icon: Icons.sports_esports_rounded, colors: [Color(0xFF3B226D), Color(0xFF171033)]),
      _BannerData(title: 'رصيدك جاهز', subtitle: 'شحن سريع وآمن من محفظتك', tag: 'نجاز كارد بلاس', icon: Icons.account_balance_wallet_rounded, colors: [Color(0xFF075C5D), Color(0xFF062B3D)]),
    ] : _banners;
    return Container(height: 148, clipBehavior: Clip.antiAlias, decoration: BoxDecoration(borderRadius: BorderRadius.circular(24), border: Border.all(color: AppColors.border), boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 20, offset: const Offset(0, 8))]), child: Stack(children: [
      PageView.builder(controller: _bannerController, itemCount: banners.length, onPageChanged: (index) => setState(() => _bannerIndex = index), itemBuilder: (context, index) => _buildBannerSlide(banners[index])),
      Positioned(bottom: 11, left: 0, right: 0, child: Row(mainAxisAlignment: MainAxisAlignment.center, children: List.generate(banners.length, (index) { final active = _bannerIndex == index; return AnimatedContainer(duration: const Duration(milliseconds: 220), width: active ? 20 : 6, height: 6, margin: const EdgeInsets.symmetric(horizontal: 3), decoration: BoxDecoration(color: active ? Colors.white : Colors.white38, borderRadius: BorderRadius.circular(6))); }))),
    ]));
  }

  Widget _buildBannerSlide(_BannerData banner) {
    if (banner.imageUrl.isNotEmpty) {
      return Stack(fit: StackFit.expand, children: [
        CachedNetworkImage(imageUrl: banner.imageUrl, fit: BoxFit.cover, errorWidget: (_, __, ___) => _bannerFallbackCard(banner)),
        if (banner.title.isNotEmpty) Container(decoration: const BoxDecoration(gradient: LinearGradient(begin: Alignment.bottomCenter, end: Alignment.topCenter, colors: [Color(0xB3000000), Colors.transparent], stops: [0.0, 0.55]))),
        if (banner.title.isNotEmpty) Positioned(right: 16, left: 16, bottom: 14, child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Text(banner.title, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor, fontSize: 15, fontWeight: FontWeight.bold)), if (banner.subtitle.isNotEmpty) Text(banner.subtitle, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor.withOpacity(.85), fontSize: 11))])),
      ]);
    }
    return _bannerFallbackCard(banner);
  }

  Widget _bannerFallbackCard(_BannerData banner) {
    return Container(padding: const EdgeInsets.fromLTRB(20, 16, 18, 22), decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: banner.colors)), child: Row(children: [
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisAlignment: MainAxisAlignment.center, children: [
        if (banner.tag.isNotEmpty) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4), decoration: BoxDecoration(color: banner.accentColor.withOpacity(.2), borderRadius: BorderRadius.circular(20)), child: Text(banner.tag, style: TextStyle(color: banner.accentColor, fontSize: 10, fontWeight: FontWeight.bold))),
        const SizedBox(height: 8),
        Text(banner.title, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor, fontSize: 19, fontWeight: FontWeight.bold)),
        const SizedBox(height: 3),
        Text(banner.subtitle, textAlign: TextAlign.right, style: TextStyle(color: banner.textColor.withOpacity(.72), fontSize: 12)),
      ])),
      const SizedBox(width: 16),
      _bannerIcon(banner),
    ]));
  }

  Widget _bannerIcon(_BannerData banner) => Container(width: 68, height: 68, decoration: BoxDecoration(color: banner.textColor.withOpacity(.13), shape: BoxShape.circle, border: Border.all(color: banner.textColor.withOpacity(.2))), child: Icon(banner.icon, color: banner.accentColor, size: 32));

  Widget _gradientButton({required String label, required bool loading, required VoidCallback onPressed}) {
    return Container(decoration: BoxDecoration(gradient: AppColors.balanceGradient, borderRadius: BorderRadius.circular(16), boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 16, offset: const Offset(0, 8))]), child: Material(color: Colors.transparent, child: InkWell(borderRadius: BorderRadius.circular(16), onTap: loading ? null : onPressed, child: Padding(padding: const EdgeInsets.symmetric(vertical: 16), child: Center(child: loading ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white)) : Text(label, style: const TextStyle(fontSize: 16, color: Colors.white, fontWeight: FontWeight.bold, letterSpacing: 0.5))))));
  }

  Widget _field({required TextEditingController controller, required String label, required IconData icon, bool obscure = false, bool isPassword = false, TextInputType? keyboardType, VoidCallback? onToggleObscure}) {
    return TextField(controller: controller, obscureText: obscure, keyboardType: keyboardType, style: TextStyle(color: AppColors.text, fontSize: 14), decoration: InputDecoration(labelText: label, labelStyle: TextStyle(color: AppColors.text2, fontSize: 13), prefixIcon: Icon(icon, color: AppColors.text2, size: 20), suffixIcon: isPassword ? IconButton(onPressed: onToggleObscure, icon: Icon(obscure ? Icons.visibility_off_outlined : Icons.visibility_outlined, color: AppColors.text2, size: 20), splashRadius: 20) : null, filled: true, fillColor: AppColors.card2, border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none), enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none), focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: AppColors.primary, width: 1.5)), contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16)));
  }
}
