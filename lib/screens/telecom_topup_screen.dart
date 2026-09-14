import 'dart:async';
import 'package:flutter/material.dart';
import '../services/telecom_api_service.dart';
import '../theme/app_colors.dart';

class TelecomTopupScreen extends StatefulWidget {
  final int categoryId;
  final String categoryName;

  const TelecomTopupScreen({
    super.key,
    required this.categoryId,
    required this.categoryName,
  });

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

  bool loading = false;
  bool checking = false;
  bool paying = false;
  String activeTab = 'all';
  String? error;

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
      error = null;
    });

    final p = value.replaceAll(RegExp(r'[^0-9]'), '');
    if (p.length >= 8) {
      timer = Timer(
        const Duration(milliseconds: 350),
        () => detect(p),
      );
    }
  }

  Future<void> detect(String p) async {
    setState(() => loading = true);
    try {
      final d = await TelecomApiService.detectNetwork(p);
      if (!mounted) return;

      network = d['network'] is Map
          ? Map<String, dynamic>.from(d['network'])
          : null;

      final id = int.tryParse('${network?['id'] ?? 0}') ?? 0;
      if (id > 0) {
        bunches = await TelecomApiService.bunches(id);
      }
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) setState(() => error = _cleanError(e));
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

    setState(() {
      checking = true;
      error = null;
    });

    try {
      final d = await TelecomApiService.checkService(
        phone: p,
        networkId: n,
      );
      if (!mounted) return;
      checkData = d['data'] is Map
          ? Map<String, dynamic>.from(d['data'])
          : d;
      setState(() {});
    } catch (e) {
      if (mounted) setState(() => error = _cleanError(e));
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

  String _number(double value) {
    return value == value.roundToDouble()
        ? value.toInt().toString()
        : value.toString();
  }

  String _cleanError(Object e) {
    final text = e.toString().replaceFirst('Exception: ', '').trim();
    return text.isEmpty ? 'حدث خطأ غير متوقع، حاول مرة أخرى' : text;
  }

  String _sectionName(String section) {
    switch (section) {
      case 'amount':
        return 'شحن رصيد';
      case 'fees':
        return 'الفئات والرسوم';
      case 'bundles':
        return 'الباقات';
      case 'yemen4g_change':
        return 'تغيير الباقة';
      case 'yemen4g_credit':
        return 'رصيد يمن فورجي';
      case 'yemen4g_internet':
        return 'باقات الإنترنت';
      case 'yemen4g_voice':
        return 'باقات المكالمات';
      default:
        return 'فئات أخرى';
    }
  }

  IconData _sectionIcon(String section) {
    switch (section) {
      case 'amount':
        return Icons.account_balance_wallet_rounded;
      case 'fees':
        return Icons.receipt_long_rounded;
      case 'yemen4g_internet':
      case 'bundles':
        return Icons.public_rounded;
      case 'yemen4g_voice':
        return Icons.phone_in_talk_rounded;
      default:
        return Icons.apps_rounded;
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

  List<MapEntry<String, List<Map<String, dynamic>>>> visibleGroups() {
    final groups = groupedBunches();
    if (activeTab == 'all') return groups;
    if (activeTab == 'balance') {
      return groups.where((g) => g.key == 'amount').toList();
    }
    if (activeTab == 'bundles') {
      return groups.where((g) => g.key != 'amount').toList();
    }
    return groups;
  }

  Future<void> submit() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    final a = double.tryParse(amount.text.replaceAll(',', '')) ?? 0;
    final b = selectedBunch;
    final bunchId =
        '${b?['unified_code'] ?? b?['bunch_id'] ?? b?['id'] ?? ''}';

    if (p.length < 7 || n <= 0 || b == null || bunchId.isEmpty || a <= 0) {
      setState(() => error = 'اختر فئة الشحن وأدخل المبلغ الصحيح');
      return;
    }

    setState(() {
      paying = true;
      error = null;
    });

    try {
      final d = await TelecomApiService.topup(
        phone: p,
        networkId: n,
        bunchId: bunchId,
        amountYer: a,
      );
      if (!mounted) return;

      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('تم إرسال العملية'),
          content: Text(
            '${d['message'] ?? d['msg'] ?? 'تم الشحن بنجاح'}\nرقم الطلب: ${d['order_id'] ?? d['orderId'] ?? '—'}',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    } catch (e) {
      if (mounted) setState(() => error = _cleanError(e));
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final supports = network?['supports_balance'] == true ||
        '${network?['supports_balance']}' == '1';

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        centerTitle: false,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_rounded),
          onPressed: () => Navigator.maybePop(context),
        ),
        titleSpacing: 0,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName,
              style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800),
            ),
            Text(
              'شحن رصيد الاتصالات اليمنية',
              style: TextStyle(fontSize: 10, color: AppColors.text2),
            ),
          ],
        ),
        actions: [
          Container(
            margin: const EdgeInsets.only(left: 12, top: 8, bottom: 8),
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
              color: AppColors.card2,
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: AppColors.border),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('مساعدة', style: TextStyle(color: AppColors.text2, fontSize: 11)),
                const SizedBox(width: 5),
                const Icon(Icons.help_rounded, size: 16, color: AppColors.gold),
              ],
            ),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(14, 4, 14, 28),
        children: [
          _phoneCard(),
          if (network != null) ...[
            const SizedBox(height: 10),
            _networkCard(supports),
            const SizedBox(height: 10),
            _accountCard(),
            const SizedBox(height: 12),
            _checkButton(),
          ],
          if (checkData != null) _checkCard(),
          if (bunches.isNotEmpty) ...[
            const SizedBox(height: 17),
            _tabs(),
            const SizedBox(height: 18),
            ...visibleGroups().map(
              (g) => _bunchSection(g.key, g.value),
            ),
          ],
          if (selectedBunch != null) ...[
            const SizedBox(height: 8),
            _transactionCard(supports),
          ],
          if (error != null) _errorBox(),
          const SizedBox(height: 18),
          _trustRow(),
          const SizedBox(height: 14),
          _noticeCard(),
        ],
      ),
    );
  }

  Widget _phoneCard() {
    return _panel(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          const Text('رقم الهاتف', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          TextField(
            controller: phone,
            onChanged: changed,
            keyboardType: TextInputType.phone,
            textAlign: TextAlign.right,
            style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700, letterSpacing: 1),
            decoration: InputDecoration(
              hintText: 'أدخل رقم الهاتف',
              prefixIcon: Icon(Icons.phone_android_rounded, color: AppColors.primary),
              suffixIcon: loading
                  ? const Padding(
                      padding: EdgeInsets.all(14),
                      child: SizedBox(
                        width: 17,
                        height: 17,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : IconButton(
                      onPressed: () {
                        phone.clear();
                        changed('');
                      },
                      icon: const Icon(Icons.close_rounded),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _networkCard(bool supports) {
    final name = '${network?['name'] ?? 'الشبكة'}';
    final logo = '${network?['logo'] ?? ''}'.trim();

    return _panel(
      padding: const EdgeInsets.all(12),
      child: Row(
        children: [
          _networkLogo(logo),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(name, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 3),
                Text('تم التعرف على الشبكة تلقائياً', style: TextStyle(color: AppColors.text2, fontSize: 10)),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Icon(
            supports ? Icons.check_circle_rounded : Icons.error_outline_rounded,
            color: supports ? AppColors.green : AppColors.red,
            size: 24,
          ),
        ],
      ),
    );
  }

  Widget _networkLogo(String logo) {
    if (logo.isNotEmpty) {
      final url = logo.startsWith('http') ? logo : 'https://njaz.net/$logo';
      return Container(
        width: 62,
        height: 62,
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(13),
          boxShadow: const [BoxShadow(blurRadius: 12, offset: Offset(0, 4), color: Color(0x22000000))],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(9),
          child: Image.network(
            url,
            fit: BoxFit.contain,
            errorBuilder: (_, __, ___) => const Icon(Icons.sim_card_rounded, color: AppColors.primary, size: 30),
          ),
        ),
      );
    }

    return Container(
      width: 62,
      height: 62,
      decoration: BoxDecoration(
        color: AppColors.primary.withOpacity(.13),
        borderRadius: BorderRadius.circular(13),
      ),
      child: const Icon(Icons.sim_card_rounded, color: AppColors.primary, size: 31),
    );
  }

  Widget _accountCard() {
    final balance = checkData?['balance'];
    final loan = checkData?['loan'];

    return _panel(
      padding: EdgeInsets.zero,
      child: Row(
        children: [
          Expanded(child: _accountStat('الرصيد الحالي', balance == null ? '—' : '$balance ر.ي', Icons.account_balance_wallet_rounded, AppColors.purple)),
          _verticalDivider(),
          Expanded(child: _accountStat('السلفة المتاحة', loan == null ? '—' : '$loan ر.ي', Icons.access_time_rounded, AppColors.gold)),
          _verticalDivider(),
          Expanded(child: _accountStat('الحالة', 'نشط', Icons.person_outline_rounded, AppColors.green)),
        ],
      ),
    );
  }

  Widget _accountStat(String title, String value, IconData icon, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 5),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 15, color: color),
              const SizedBox(width: 4),
              Flexible(child: Text(title, textAlign: TextAlign.center, style: TextStyle(color: AppColors.text2, fontSize: 9))),
            ],
          ),
          const SizedBox(height: 7),
          Text(value, textAlign: TextAlign.center, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: value == 'نشط' ? AppColors.green : AppColors.text)),
        ],
      ),
    );
  }

  Widget _verticalDivider() => Container(width: 1, height: 54, color: AppColors.border);

  Widget _checkButton() {
    return SizedBox(
      height: 54,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [AppColors.primary, AppColors.accentPurple]),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [BoxShadow(color: AppColors.primary.withOpacity(.20), blurRadius: 16, offset: const Offset(0, 6))],
        ),
        child: ElevatedButton.icon(
          onPressed: checking ? null : checkNumber,
          style: ElevatedButton.styleFrom(
            backgroundColor: Colors.transparent,
            shadowColor: Colors.transparent,
            disabledBackgroundColor: Colors.transparent,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          icon: checking
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.search_rounded, color: Colors.white),
          label: Text(
            checking ? 'جاري الفحص...' : 'فحص الرصيد والسلفة والباقات',
            style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w800),
          ),
        ),
      ),
    );
  }

  Widget _tabs() {
    return Row(
      children: [
        _tab('all', 'كل الخدمات', Icons.apps_rounded),
        const SizedBox(width: 7),
        _tab('balance', 'شحن رصيد', Icons.account_balance_wallet_rounded),
        const SizedBox(width: 7),
        _tab('bundles', 'باقات', Icons.public_rounded),
      ],
    );
  }

  Widget _tab(String id, String label, IconData icon) {
    final selected = activeTab == id;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => activeTab = id),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          height: 43,
          decoration: BoxDecoration(
            color: selected ? AppColors.primary : AppColors.card,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: selected ? AppColors.primary : AppColors.border),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (selected) Icon(icon, size: 15, color: Colors.white),
              if (selected) const SizedBox(width: 5),
              Text(
                label,
                style: TextStyle(
                  color: selected ? Colors.white : AppColors.text2,
                  fontSize: 11,
                  fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _checkCard() {
    final d = checkData!;
    final balance = d['balance'];
    final loan = d['loan'];
    final offers = d['offers'];

    return Container(
      margin: const EdgeInsets.only(top: 10),
      child: _panel(
        padding: const EdgeInsets.all(13),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Row(
              children: [
                const Icon(Icons.verified_rounded, color: AppColors.green, size: 19),
                const Spacer(),
                const Text('نتيجة الفحص', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800)),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(child: _miniStat('الرصيد', balance == null ? '—' : '$balance ر.ي', AppColors.purple)),
                const SizedBox(width: 8),
                Expanded(child: _miniStat('السلفة', (double.tryParse('$loan') ?? 0) > 0 ? '$loan ر.ي' : 'لا توجد', AppColors.gold)),
              ],
            ),
            if (offers is List && offers.isNotEmpty) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const Icon(Icons.local_offer_rounded, color: AppColors.primary, size: 18),
                  const Spacer(),
                  Text('العروض المتاحة', style: TextStyle(color: AppColors.text2, fontSize: 11, fontWeight: FontWeight.w700)),
                ],
              ),
              const SizedBox(height: 7),
              ...offers.take(4).map((o) => Container(
                    margin: const EdgeInsets.only(bottom: 5),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(9)),
                    child: Align(
                      alignment: Alignment.centerRight,
                      child: Text(
                        o is Map ? '${o['offer_name'] ?? o['name'] ?? o['offer_id'] ?? ''}' : '$o',
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
                      ),
                    ),
                  )),
            ],
          ],
        ),
      ),
    );
  }

  Widget _miniStat(String title, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(11)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(title, style: TextStyle(color: AppColors.text2, fontSize: 10)),
          const SizedBox(height: 4),
          Text(value, style: TextStyle(color: color, fontSize: 13, fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }

  Widget _bunchSection(String section, List<Map<String, dynamic>> items) {
    if (items.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Row(
            children: [
              Container(
                width: 31,
                height: 31,
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(.13),
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Icon(_sectionIcon(section), color: AppColors.primary, size: 17),
              ),
              const SizedBox(width: 8),
              Text(_sectionName(section), style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
            ],
          ),
          const SizedBox(height: 10),
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: items.length,
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              crossAxisSpacing: 9,
              mainAxisSpacing: 9,
              childAspectRatio: 1.30,
            ),
            itemBuilder: (_, index) => _bunchCard(items[index]),
          ),
        ],
      ),
    );
  }

  Widget _bunchCard(Map<String, dynamic> b) {
    final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
    final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
    final validity = '${b['validity'] ?? ''}'.trim();
    final selected = selectedBunch != null && '${selectedBunch!['id']}' == '${b['id']}';
    final isAmount = '${b['section'] ?? ''}' == 'amount' || b['is_free_amount'] == 1 || '${b['is_free_amount']}' == '1';

    return GestureDetector(
      onTap: () => chooseBunch(b),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        padding: const EdgeInsets.fromLTRB(10, 10, 10, 9),
        decoration: BoxDecoration(
          color: selected ? AppColors.primary.withOpacity(.10) : AppColors.card,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? AppColors.primary : AppColors.border,
            width: selected ? 1.5 : 1,
          ),
          boxShadow: selected
              ? [BoxShadow(color: AppColors.primary.withOpacity(.10), blurRadius: 12)]
              : null,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Row(
              children: [
                if (selected)
                  const Icon(Icons.check_circle_rounded, color: AppColors.primary, size: 17),
                const Spacer(),
                Flexible(
                  child: Text(
                    name.isEmpty ? (price > 0 ? _number(price) : 'خدمة') : name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
            const Spacer(),
            if (validity.isNotEmpty)
              Text(validity, style: TextStyle(color: AppColors.text2, fontSize: 9)),
            const SizedBox(height: 3),
            Text(
              price > 0 ? '${_number(price)} ر.ي' : (isAmount ? 'أدخل المبلغ' : 'حسب الخدمة'),
              style: TextStyle(color: selected ? AppColors.primary : AppColors.text2, fontSize: 11, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 7),
            Container(
              height: 30,
              width: double.infinity,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: selected ? AppColors.primary : Colors.transparent,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.primary),
              ),
              child: Text(
                selected ? 'تم الاختيار' : 'اختيار',
                style: TextStyle(color: selected ? Colors.white : AppColors.primary, fontSize: 10, fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _transactionCard(bool supports) {
    final b = selectedBunch!;
    final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
    final a = double.tryParse(amount.text.replaceAll(',', '')) ?? price;
    final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? 'الخدمة'}'.trim();
    final logo = '${network?['logo'] ?? ''}'.trim();

    return _panel(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Row(
            children: [
              const Icon(Icons.settings_rounded, color: AppColors.text2, size: 19),
              const Spacer(),
              const Text('تفاصيل العملية', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800)),
            ],
          ),
          const SizedBox(height: 10),
          if (logo.isNotEmpty)
            Row(
              children: [
                _networkLogoSmall(logo),
                const Spacer(),
                Text(name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              ],
            ),
          if (logo.isNotEmpty) const SizedBox(height: 8),
          _detailRow('الخدمة', name),
          _detailRow('رقم الهاتف', phone.text),
          _detailRow('السعر', '${_number(a)} ر.ي'),
          _detailRow('الخصم من الرصيد', '${_number(a)} ر.ي', valueColor: AppColors.green),
          const SizedBox(height: 11),
          SizedBox(
            height: 49,
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: (paying || !supports || a <= 0) ? null : submit,
              icon: paying
                  ? const SizedBox(width: 17, height: 17, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.send_rounded, size: 18),
              label: Text(paying ? 'جاري تنفيذ العملية...' : 'شحن الآن'),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                disabledBackgroundColor: AppColors.card3,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                textStyle: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Center(child: Text('بالضغط على شحن الآن فأنت توافق على تنفيذ العملية', style: TextStyle(color: AppColors.text3, fontSize: 9))),
        ],
      ),
    );
  }

  Widget _networkLogoSmall(String logo) {
    final url = logo.startsWith('http') ? logo : 'https://njaz.net/$logo';
    return Container(
      width: 43,
      height: 34,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: Image.network(url, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const Icon(Icons.sim_card, size: 18)),
      ),
    );
  }

  Widget _detailRow(String label, String value, {Color? valueColor}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Text(value, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 11, color: valueColor ?? AppColors.text)),
          const Spacer(),
          Text(label, style: TextStyle(color: AppColors.text2, fontSize: 10)),
        ],
      ),
    );
  }

  Widget _errorBox() {
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: AppColors.red.withOpacity(.09),
        borderRadius: BorderRadius.circular(11),
        border: Border.all(color: AppColors.red.withOpacity(.22)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline_rounded, color: AppColors.red, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(error!, textAlign: TextAlign.right, style: const TextStyle(color: AppColors.red, fontSize: 11, height: 1.4))),
        ],
      ),
    );
  }

  Widget _trustRow() {
    return Row(
      children: [
        Expanded(child: _trustItem(Icons.schedule_rounded, 'متاح 24 ساعة', AppColors.green)),
        Expanded(child: _trustItem(Icons.bolt_rounded, 'تنفيذ فوري', AppColors.gold)),
        Expanded(child: _trustItem(Icons.verified_user_rounded, 'آمن وموثوق', AppColors.green)),
      ],
    );
  }

  Widget _trustItem(IconData icon, String text, Color color) {
    return Column(
      children: [
        Icon(icon, color: color, size: 22),
        const SizedBox(height: 5),
        Text(text, textAlign: TextAlign.center, style: TextStyle(color: AppColors.text2, fontSize: 9, fontWeight: FontWeight.w600)),
      ],
    );
  }

  Widget _noticeCard() {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.card2,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_rounded, color: AppColors.purple, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                const Text('ملاحظة', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
                const SizedBox(height: 3),
                Text(
                  'الأسعار قابلة للتغيير حسب تحديثات الشركة المقدمة للخدمة.',
                  textAlign: TextAlign.right,
                  style: TextStyle(color: AppColors.text2, fontSize: 9, height: 1.4),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _panel({required Widget child, EdgeInsetsGeometry? padding}) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(15),
        border: Border.all(color: AppColors.border),
      ),
      child: child,
    );
  }
}
