import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import 'topup_manual_detail_screen.dart';
import 'topup_usdt_screen.dart';
import 'topup_binance_screen.dart';
import 'topup_sms_screen.dart';
import 'topup_floosak_screen.dart';

class TopupScreen extends StatefulWidget {
  final int initialTab; // 0=يدوي 1=مباشر 2=بكود 3=مباشر2(SMS)
  const TopupScreen({super.key, this.initialTab = 0});

  @override
  State<TopupScreen> createState() => _TopupScreenState();
}

class _TopupScreenState extends State<TopupScreen> with SingleTickerProviderStateMixin {
  TabController? _tabController;
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _options;
  final _cardCodeCtrl = TextEditingController();
  bool _cardLoading = false;
  String? _cardMsg;
  bool _cardSuccess = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tabController?.dispose();
    _cardCodeCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.getTopupOptions();
      final smsEnabled = data['sms_topup_enabled'] == true;
      _tabController?.dispose();
      _tabController = TabController(
        length: smsEnabled ? 4 : 3,
        vsync: this,
        initialIndex: widget.initialTab.clamp(0, smsEnabled ? 3 : 2),
      );
      setState(() => _options = data);
    } catch (e) {
      setState(() => _error = 'تعذر تحميل بيانات شحن الرصيد');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  bool get _kycApproved => _options?['kyc_status'] == 'approved';

  void _requireKycThen(VoidCallback action) {
    if (_kycApproved) {
      action();
      return;
    }
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('يجب توثيق هويتك أولاً قبل شحن الرصيد — يمكنك إتمام التوثيق من موقع نجاز'),
        backgroundColor: AppColors.card2,
      ),
    );
  }

  Future<void> _push(Widget screen) async {
    final result = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => screen));
    if (result == true && mounted) Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final smsEnabled = _options?['sms_topup_enabled'] == true;

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: const Text('شحن الرصيد', style: TextStyle(color: AppColors.text)),
        iconTheme: const IconThemeData(color: AppColors.text),
        bottom: (_loading || _tabController == null)
            ? null
            : TabBar(
                controller: _tabController,
                indicatorColor: AppColors.primary,
                labelColor: AppColors.primary,
                unselectedLabelColor: AppColors.text2,
                labelStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                tabs: [
                  const Tab(text: 'يدوي'),
                  const Tab(text: 'مباشر'),
                  const Tab(text: 'بكود'),
                  if (smsEnabled) const Tab(text: 'مباشر 2'),
                ],
              ),
      ),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : _error != null
                ? Center(child: Text(_error!, style: const TextStyle(color: AppColors.red)))
                : Column(
                    children: [
                      _buildBalanceStrip(),
                      Expanded(
                        child: TabBarView(
                          controller: _tabController,
                          children: [
                            _buildManualTab(),
                            _buildAutoTab(),
                            _buildCardTab(),
                            if (smsEnabled) _buildSmsTab(),
                          ],
                        ),
                      ),
                    ],
                  ),
      ),
    );
  }

  Widget _buildBalanceStrip() {
    final balance = double.tryParse('${_options?['balance'] ?? 0}') ?? 0;
    final symbol = _options?['currency_symbol']?.toString() ?? '\$';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      color: AppColors.card2,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          const Text('رصيدك الحالي', style: TextStyle(color: AppColors.text2, fontSize: 12)),
          Text('${balance.toStringAsFixed(2)} $symbol',
              style: const TextStyle(color: AppColors.green, fontSize: 14, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }

  // ══════════════════ يدوي ══════════════════
  Widget _buildManualTab() {
    final methods = ((_options?['payment_methods'] as List<dynamic>?) ?? [])
        .where((m) => (m['payment_mode'] ?? 'manual') == 'manual')
        .toList();
    final rates = (_options?['exchange_rates'] as List<dynamic>?) ?? [];

    if (methods.isEmpty) {
      return const _EmptyHint(text: 'لا توجد طرق تحويل يدوي متاحة حالياً');
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: methods.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, i) {
        final m = methods[i];
        return _MethodCard(
          title: m['name']?.toString() ?? '',
          subtitle: m['description']?.toString() ?? 'اتبع التعليمات لإتمام التحويل',
          imageUrl: m['image_url']?.toString(),
          icon: Icons.account_balance_outlined,
          onTap: () => _requireKycThen(() => _push(TopupManualDetailScreen(method: m, exchangeRates: rates))),
        );
      },
    );
  }

  // ══════════════════ مباشر (تلقائي) ══════════════════
  Widget _buildAutoTab() {
    final autoMethods = ((_options?['payment_methods'] as List<dynamic>?) ?? [])
        .where((method) => (method['payment_mode'] ?? 'manual') == 'auto')
        .toList();
    final usdt = _options?['usdt'] as Map<String, dynamic>? ?? {};
    final binance = _options?['binance'] as Map<String, dynamic>? ?? {};
    final floosak = _options?['floosak'] as Map<String, dynamic>? ?? {};

    final cards = <Widget>[];
    for (final method in autoMethods) {
      cards.add(_MethodCard(
        title: method['name']?.toString() ?? 'طريقة دفع مباشرة',
        subtitle: method['description']?.toString() ?? 'دفع مباشر',
        imageUrl: method['image_url']?.toString(),
        icon: Icons.bolt_rounded,
        color: _parseColor(method['color']?.toString(), AppColors.cyan),
        onTap: () => _requireKycThen(() => _push(TopupManualDetailScreen(method: method, exchangeRates: const []))),
      ));
    }
    if (binance['enabled'] == true) {
      cards.add(_MethodCard(
        title: 'مباشر Binance',
        subtitle: 'إيداع USDT عبر Binance Pay — شحن فوري',
        imageUrl: binance['icon_url']?.toString(),
        icon: Icons.currency_bitcoin_rounded,
        color: const Color(0xFFF6C11A),
        onTap: () => _push(TopupBinanceScreen(settings: binance)),
      ));
    }
    if (usdt['enabled'] == true) {
      cards.add(_MethodCard(
        title: 'USDT — BEP20',
        subtitle: 'BNB Smart Chain — شحن تلقائي فوري',
        imageUrl: usdt['image_url']?.toString(),
        icon: Icons.account_balance_wallet_outlined,
        color: const Color(0xFF26A17B),
        onTap: () => _push(TopupUsdtScreen(settings: usdt)),
      ));
    }
    if (floosak['enabled'] == true) {
      cards.add(_MethodCard(
        title: 'محفظة فلوسك',
        subtitle: 'دفع مباشر من محفظتك في فلوسك برمز تحقق',
        icon: Icons.phone_iphone_rounded,
        color: AppColors.cyan,
        onTap: () => _push(TopupFloosakScreen(imageUrl: floosak['image_url']?.toString())),
      ));
    }

    if (cards.isEmpty) {
      return const _EmptyHint(text: 'لا توجد طرق شحن مباشر متاحة حالياً');
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: cards.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (_, i) => cards[i],
    );
  }

  Color _parseColor(String? value, Color fallback) {
    if (value == null) return fallback;
    final hex = value.replaceFirst('#', '');
    final normalized = hex.length == 6 ? 'FF$hex' : hex;
    final parsed = int.tryParse(normalized, radix: 16);
    return parsed == null ? fallback : Color(parsed);
  }

  // ══════════════════ بكود ══════════════════
  Widget _buildCardTab() {
    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        children: [
          const SizedBox(height: 12),
          const Icon(Icons.confirmation_num_outlined, color: AppColors.gold, size: 46),
          const SizedBox(height: 10),
          const Text('شحن بكود البطاقة', style: TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          const Text('أدخل الكود لشحن رصيدك فوراً', style: TextStyle(color: AppColors.text2, fontSize: 12)),
          const SizedBox(height: 20),
          TextField(
            controller: _cardCodeCtrl,
            textAlign: TextAlign.center,
            style: const TextStyle(color: AppColors.text, letterSpacing: 2, fontWeight: FontWeight.bold),
            decoration: InputDecoration(
              hintText: 'XXXXXXXX-XXXXXXXX-XXXXXXXX',
              hintStyle: const TextStyle(color: AppColors.text3, letterSpacing: 1),
              filled: true,
              fillColor: AppColors.card2,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
            ),
          ),
          if (_cardMsg != null) ...[
            const SizedBox(height: 10),
            Text(_cardMsg!,
                style: TextStyle(color: _cardSuccess ? AppColors.green : AppColors.red, fontSize: 13),
                textAlign: TextAlign.center),
          ],
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: _cardLoading ? null : () => _requireKycThen(_redeemCard),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.green,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              child: _cardLoading
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('شحن الرصيد', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _redeemCard() async {
    final code = _cardCodeCtrl.text.trim();
    if (code.replaceAll('-', '').length < 4) {
      setState(() {
        _cardMsg = 'أدخل الكود كاملاً';
        _cardSuccess = false;
      });
      return;
    }
    setState(() {
      _cardLoading = true;
      _cardMsg = null;
    });
    try {
      final data = await ApiService.redeemCard(code);
      setState(() {
        _cardSuccess = true;
        _cardMsg = data['message']?.toString() ?? 'تم شحن رصيدك بنجاح!';
      });
      _cardCodeCtrl.clear();
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() {
        _cardSuccess = false;
        _cardMsg = e.message;
      });
    } catch (e) {
      setState(() {
        _cardSuccess = false;
        _cardMsg = 'تعذر الاتصال بالسيرفر';
      });
    } finally {
      if (mounted) setState(() => _cardLoading = false);
    }
  }

  // ══════════════════ مباشر 2 (SMS) ══════════════════
  Widget _buildSmsTab() {
    final smsProviders = _options?['sms_providers'] as Map<String, dynamic>? ?? {};
    return TopupSmsInlineTab(
      providers: smsProviders,
      onRequireKyc: _requireKycThen,
      onSuccess: () => Navigator.of(context).pop(true),
    );
  }
}

class _EmptyHint extends StatelessWidget {
  final String text;
  const _EmptyHint({required this.text});
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(text, style: const TextStyle(color: AppColors.text2), textAlign: TextAlign.center),
      ),
    );
  }
}

class _MethodCard extends StatelessWidget {
  final String title;
  final String subtitle;
  final IconData icon;
  final String? imageUrl;
  final Color color;
  final VoidCallback onTap;
  const _MethodCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    this.imageUrl,
    this.color = AppColors.primary,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.card,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(13)),
                child: imageUrl != null
                    ? ClipRRect(
                        borderRadius: BorderRadius.circular(13),
                        child: CachedNetworkImage(imageUrl: imageUrl!, fit: BoxFit.contain, errorWidget: (_, __, ___) => Icon(icon, color: color)),
                      )
                    : Icon(icon, color: color),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: const TextStyle(color: AppColors.text, fontSize: 14, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 3),
                    Text(subtitle, style: const TextStyle(color: AppColors.text2, fontSize: 11), maxLines: 2, overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
              const Icon(Icons.chevron_left_rounded, color: AppColors.text3),
            ],
          ),
        ),
      ),
    );
  }
}
