import 'dart:async';
import 'package:flutter/material.dart';
import '../services/telecom_api_service.dart';
import '../theme/app_colors.dart';

class TelecomTopupScreen extends StatefulWidget {
  final int categoryId;
  final String categoryName;
  const TelecomTopupScreen({super.key, required this.categoryId, required this.categoryName});

  @override
  State<TelecomTopupScreen> createState() => _TelecomTopupScreenState();
}

class _TelecomTopupScreenState extends State<TelecomTopupScreen> {
  final phone = TextEditingController();
  final amount = TextEditingController();
  Timer? timer;
  Map<String, dynamic>? network;
  List<dynamic> bunches = [];
  Map<String, dynamic>? checkData;
  Map<String, dynamic>? selectedBunch;
  bool loading = false, checking = false, paying = false;
  String? error;

  // لا يتم عرض أي فئة أو باقة عند فتح الشاشة حتى يختار المستخدم القسم المطلوب.
  String? activeTab;

  @override
  void dispose() {
    timer?.cancel();
    phone.dispose();
    amount.dispose();
    super.dispose();
  }

  void changed(String value) {
    timer?.cancel();
    setState(() {
      network = null;
      bunches = [];
      checkData = null;
      selectedBunch = null;
      activeTab = null;
      error = null;
    });
    final p = value.replaceAll(RegExp(r'[^0-9]'), '');
    if (p.length >= 8) timer = Timer(const Duration(milliseconds: 350), () => detect(p));
  }

  Future<void> detect(String p) async {
    setState(() => loading = true);
    try {
      final d = await TelecomApiService.detectNetwork(p);
      if (!mounted) return;
      network = d['network'] as Map<String, dynamic>?;
      final id = int.tryParse('${network?['id'] ?? 0}') ?? 0;
      if (id > 0) bunches = await TelecomApiService.bunches(id);
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> checkNumber() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    if (p.length < 7 || n <= 0) {
      setState(() => error = 'أدخل رقم الهاتف أولاً');
      return;
    }
    setState(() { checking = true; error = null; });
    try {
      final d = await TelecomApiService.checkService(phone: p, networkId: n);
      if (!mounted) return;
      checkData = (d['data'] is Map) ? Map<String, dynamic>.from(d['data']) : d;
      setState(() {});
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  void chooseBunch(Map<String, dynamic> b) {
    final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
    setState(() {
      selectedBunch = b;
      if (price > 0) amount.text = _number(price);
      error = null;
    });
  }

  String _number(double v) => v == v.roundToDouble() ? v.toInt().toString() : v.toString();

  String _sectionName(String section) {
    switch (section) {
      case 'amount': return 'شحن الرصيد';
      case 'fees': return 'الفئات والرسوم';
      case 'bundles': return 'الباقات';
      case 'yemen4g_change': return 'تغيير الباقة';
      case 'yemen4g_credit': return 'رصيد يمن فورجي';
      case 'yemen4g_internet': return 'باقات الإنترنت';
      case 'yemen4g_voice': return 'باقات المكالمات';
      default: return 'فئات أخرى';
    }
  }

  List<MapEntry<String, List<Map<String, dynamic>>>> groupedBunches() {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final item in bunches) {
      if (item is! Map) continue;
      final b = Map<String, dynamic>.from(item);
      final section = '${b['section'] ?? 'bundles'}';
      groups.putIfAbsent(section, () => []).add(b);
    }
    return groups.entries.toList();
  }

  bool _isAmountSection(String section) => section == 'amount' || section == 'fees';
  bool _isBundleSection(String section) => section != 'amount' && section != 'fees';

  List<Map<String, dynamic>> _itemsForTab(String tab) {
    final result = <Map<String, dynamic>>[];
    for (final item in bunches) {
      if (item is! Map) continue;
      final b = Map<String, dynamic>.from(item);
      final section = '${b['section'] ?? 'bundles'}';
      if (tab == 'amount' && _isAmountSection(section)) result.add(b);
      if (tab == 'bundles' && _isBundleSection(section)) result.add(b);
    }
    return result;
  }

  Future<void> submit() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    final a = double.tryParse(amount.text.replaceAll(',', '')) ?? 0;
    final b = selectedBunch;
    final bunchId = '${b?['unified_code'] ?? b?['bunch_id'] ?? b?['id'] ?? ''}';
    if (p.length < 7 || n <= 0 || b == null || bunchId.isEmpty || a <= 0) {
      setState(() => error = 'اختر فئة الشحن وأدخل المبلغ الصحيح');
      return;
    }
    setState(() { paying = true; error = null; });
    try {
      final d = await TelecomApiService.topup(phone: p, networkId: n, bunchId: bunchId, amountYer: a);
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('تم إرسال العملية'),
          content: Text('${d['message'] ?? d['msg'] ?? 'تم الشحن بنجاح'}\nرقم الطلب: ${d['order_id'] ?? d['orderId'] ?? '—'}'),
          actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً'))],
        ),
      );
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final supports = network?['supports_balance'] == true || '${network?['supports_balance']}' == '1';
    final showOffers = activeTab == 'offers';
    final showAmount = activeTab == 'amount' || activeTab == 'all';
    final showBundles = activeTab == 'bundles' || activeTab == 'all';

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        title: Text(widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName),
        backgroundColor: AppColors.bg,
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 28),
        children: [
          _heroCard(),
          const SizedBox(height: 14),
          _title('رقم الهاتف'),
          const SizedBox(height: 7),
          TextField(
            controller: phone,
            onChanged: changed,
            keyboardType: TextInputType.phone,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 20, letterSpacing: 2, fontWeight: FontWeight.w700),
            decoration: InputDecoration(
              hintText: '7X XXX XXXX',
              prefixIcon: const Icon(Icons.phone_android_rounded),
              suffixIcon: loading
                  ? const Padding(padding: EdgeInsets.all(14), child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)))
                  : IconButton(onPressed: () { phone.clear(); changed(''); }, icon: const Icon(Icons.clear_rounded)),
            ),
          ),
          if (network != null) _networkCard(supports),
          if (network != null) ...[
            const SizedBox(height: 12),
            SizedBox(
              height: 52,
              child: ElevatedButton.icon(
                onPressed: checking ? null : checkNumber,
                icon: checking ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.search_rounded),
                label: Text(checking ? 'جاري الفحص...' : 'فحص الرصيد والسلفة والباقات'),
              ),
            ),
          ],
          if (checkData != null && activeTab == 'offers') _checkCard(),
          if (bunches.isNotEmpty) ...[
            const SizedBox(height: 18),
            _serviceTabs(),
            if (activeTab != null) ...[
              const SizedBox(height: 18),
              if (showOffers) _offersSection(),
              if (showAmount) ...groupedBunches().where((g) => _isAmountSection(g.key)).map((g) => _bunchSection(g.key, g.value)),
              if (showBundles) ...groupedBunches().where((g) => _isBundleSection(g.key)).map((g) => _bunchSection(g.key, g.value)),
            ],
          ],
          if (selectedBunch != null) _detailsCard(),
          const SizedBox(height: 8),
          if (error != null) Padding(padding: const EdgeInsets.only(top: 14), child: _errorCard(error!)),
        ],
      ),
    );
  }

  Widget _panel({required Widget child, EdgeInsets padding = const EdgeInsets.all(14)}) => Container(
        padding: padding,
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
        child: child,
      );

  Widget _heroCard() => _panel(
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 15),
        child: Row(children: [
          Container(width: 44, height: 44, decoration: BoxDecoration(gradient: AppColors.balanceGradient, borderRadius: BorderRadius.circular(13)), child: const Icon(Icons.sim_card_rounded, color: Colors.white)),
          const Spacer(),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
            const SizedBox(height: 3),
            Text('شحن رصيد الاتصالات اليمنية', style: TextStyle(color: AppColors.text2, fontSize: 12)),
          ]),
        ]),
      );

  Widget _networkCard(bool supports) {
    final logo = '${network?['logo'] ?? ''}'.trim();
    final name = '${network?['name'] ?? network?['name_ar'] ?? ''}';
    return _panel(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(children: [
        Icon(supports ? Icons.check_circle_rounded : Icons.error_outline, color: supports ? AppColors.green : AppColors.red),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Text(name, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900)), const SizedBox(height: 3), Text('تم التعرف على الشبكة تلقائياً', style: TextStyle(color: AppColors.text2, fontSize: 11))])),
        const SizedBox(width: 10),
        _networkLogo(logo),
      ]),
    );
  }

  Widget _networkLogo(String logo) {
    if (logo.isEmpty) return Container(width: 52, height: 52, decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.signal_cellular_alt_rounded, color: AppColors.primary));
    return Container(width: 52, height: 52, padding: const EdgeInsets.all(3), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)), child: Image.network(logo, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const Icon(Icons.sim_card_rounded, color: AppColors.primary)));
  }

  Widget _checkCard() {
    final d = checkData!;
    final balance = d['balance'];
    final loan = d['loan'];
    final offers = d['offers'];
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: _panel(
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Row(children: [Icon(Icons.account_balance_wallet_rounded, color: AppColors.purple, size: 19), const Spacer(), const Text('معلومات الرقم', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w900))]),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: _stat('الرصيد الحالي', balance == null ? '—' : '$balance ر.ي', AppColors.purple, Icons.account_balance_wallet_rounded)),
            const SizedBox(width: 8),
            Expanded(child: _stat('السلفة المتاحة', (double.tryParse('$loan') ?? 0) > 0 ? '$loan ر.ي' : '0 ر.ي', AppColors.purple, Icons.access_time_rounded)),
          ]),
          if (offers is List && offers.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text('العروض المتاحة', style: TextStyle(color: AppColors.text2, fontWeight: FontWeight.w800)),
            const SizedBox(height: 7),
            ...offers.map((o) => Container(margin: const EdgeInsets.only(bottom: 5), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8), decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(10)), child: Text(o is Map ? '${o['offer_name'] ?? o['name'] ?? o['offer_id'] ?? ''}' : '$o', textAlign: TextAlign.right))),
          ],
        ]),
      ),
    );
  }

  Widget _offersSection() {
    final offers = checkData?['offers'];
    if (offers is! List || offers.isEmpty) {
      return _panel(child: Text(checkData == null ? 'اضغط على زر الفحص لإظهار العروض المتاحة' : 'لا توجد عروض متاحة لهذا الرقم', textAlign: TextAlign.right, style: TextStyle(color: AppColors.text2)));
    }
    return _checkCard();
  }

  Widget _stat(String title, String value, Color valueColor, IconData icon) => Container(
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(13), border: Border.all(color: AppColors.border)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Row(children: [Icon(icon, color: AppColors.purple, size: 16), const Spacer(), Text(title, style: TextStyle(color: AppColors.text2, fontSize: 10))]),
          const SizedBox(height: 5),
          Text(value, style: TextStyle(color: valueColor, fontWeight: FontWeight.w900, fontSize: 15)),
        ]),
      );

  Widget _serviceTabs() => SizedBox(
        height: 44,
        child: Row(children: [
          _tab('كل الخدمات', 'all'),
          const SizedBox(width: 6),
          _tab('شحن رصيد', 'amount'),
          const SizedBox(width: 6),
          _tab('باقات', 'bundles'),
          const SizedBox(width: 6),
          _tab('عروض', 'offers'),
        ]),
      );

  Widget _tab(String label, String value) {
    final selected = activeTab == value;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () => setState(() {
          activeTab = selected ? null : value;
          if (activeTab != 'offers') checkData = activeTab == null ? checkData : checkData;
        }),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          alignment: Alignment.center,
          decoration: BoxDecoration(color: selected ? AppColors.primary : AppColors.card, borderRadius: BorderRadius.circular(22), border: Border.all(color: selected ? AppColors.primary : AppColors.border)),
          child: Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: selected ? Colors.white : AppColors.text2)),
        ),
      ),
    );
  }

  Widget _bunchSection(String section, List<Map<String, dynamic>> items) {
    final title = _sectionName(section);
    final icon = _isAmountSection(section) ? Icons.account_balance_wallet_rounded : Icons.language_rounded;
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Row(children: [Icon(icon, color: AppColors.purple, size: 21), const Spacer(), Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900))]),
        const SizedBox(height: 9),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: items.length,
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, crossAxisSpacing: 9, mainAxisSpacing: 9, childAspectRatio: 1.13),
          itemBuilder: (_, index) {
            final b = items[index];
            final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
            final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
            final selected = selectedBunch != null && '${selectedBunch!['id']}' == '${b['id']}';
            return _bunchCard(b, name.isEmpty ? (price > 0 ? '${_number(price)} ر.ي' : 'اختيار') : name, price, selected);
          },
        ),
      ]),
    );
  }

  Widget _bunchCard(Map<String, dynamic> b, String name, double price, bool selected) => InkWell(
        onTap: () => chooseBunch(b),
        borderRadius: BorderRadius.circular(16),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.fromLTRB(11, 11, 11, 9),
          decoration: BoxDecoration(color: selected ? AppColors.primary.withOpacity(.13) : AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.5 : 1)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Row(children: [if (selected) const Icon(Icons.check_circle_rounded, color: AppColors.primary, size: 19), const Spacer(), Flexible(child: Text(name, maxLines: 2, overflow: TextOverflow.ellipsis, textAlign: TextAlign.right, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900)))]),
            const Spacer(),
            if (price > 0) Text('${_number(price)} ر.ي', style: TextStyle(color: AppColors.text2, fontSize: 11)),
            const SizedBox(height: 8),
            SizedBox(width: double.infinity, height: 36, child: OutlinedButton(onPressed: () => chooseBunch(b), style: OutlinedButton.styleFrom(side: const BorderSide(color: AppColors.primary), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)), padding: EdgeInsets.zero), child: Text(selected ? 'تم الاختيار' : 'اختيار', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800))))
          ]),
        ),
      );

  Widget _detailsCard() {
    final b = selectedBunch!;
    final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? 'الخدمة'}';
    final price = double.tryParse('${b['price'] ?? amount.text}') ?? (double.tryParse(amount.text) ?? 0);
    final logo = '${network?['logo'] ?? ''}'.trim();
    return Padding(
      padding: const EdgeInsets.only(top: 3),
      child: _panel(
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Row(children: [Icon(Icons.settings_rounded, color: AppColors.text2, size: 19), const Spacer(), const Text('تفاصيل العملية', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800))]),
          const SizedBox(height: 10),
          if (logo.isNotEmpty) Align(alignment: Alignment.centerRight, child: _networkLogo(logo)),
          _detailRow('الخدمة', name),
          _detailRow('رقم الهاتف', phone.text),
          _detailRow('السعر', '${_number(price)} ر.ي'),
          _detailRow('الخصم من الرصيد', '${_number(price)} ر.ي', valueColor: AppColors.green),
          const SizedBox(height: 10),
          SizedBox(height: 50, width: double.infinity, child: ElevatedButton.icon(onPressed: paying ? null : submit, icon: paying ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send_rounded), label: Text(paying ? 'جاري تنفيذ العملية...' : 'شحن الآن'))),
          const SizedBox(height: 7),
          Text('بالضغط على شحن الآن أنت توافق على تنفيذ العملية', textAlign: TextAlign.center, style: TextStyle(color: AppColors.text3, fontSize: 10)),
        ]),
      ),
    );
  }

  Widget _detailRow(String title, String value, {Color? valueColor}) => Padding(padding: const EdgeInsets.symmetric(vertical: 7), child: Row(children: [Text(value, style: TextStyle(fontWeight: FontWeight.w800, color: valueColor ?? AppColors.text)), const Spacer(), Text(title, style: TextStyle(color: AppColors.text2, fontSize: 11))]));

  Widget _errorCard(String message) => Container(padding: const EdgeInsets.all(11), decoration: BoxDecoration(color: AppColors.red.withOpacity(.08), borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.red.withOpacity(.22))), child: Text(message, textAlign: TextAlign.right, style: const TextStyle(color: AppColors.red, fontSize: 12)));

  Widget _title(String s) => Align(alignment: Alignment.centerRight, child: Text(s, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800)));
}
