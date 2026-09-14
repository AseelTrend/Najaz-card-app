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
      timer = Timer(const Duration(milliseconds: 350), () => detect(p));
    }
  }

  Future<void> detect(String p) async {
    setState(() => loading = true);
    try {
      final d = await TelecomApiService.detectNetwork(p);
      if (!mounted) return;
      network = d['network'] as Map<String, dynamic>?;
      final id = int.tryParse('${network?['id'] ?? 0}') ?? 0;
      if (id > 0) {
        bunches = await TelecomApiService.bunches(id);
      }
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
    setState(() {
      checking = true;
      error = null;
    });
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
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        title: Text(widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName),
        backgroundColor: AppColors.bg,
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          _headerCard(),
          const SizedBox(height: 18),
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
            OutlinedButton.icon(
              onPressed: checking ? null : checkNumber,
              icon: checking ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.search_rounded),
              label: Text(checking ? 'جاري الفحص...' : 'فحص الرصيد والسلفة والباقات'),
            ),
          ],
          if (checkData != null) _checkCard(),
          if (bunches.isNotEmpty) ...[
            const SizedBox(height: 18),
            _title('فئات الشحن والباقات'),
            const SizedBox(height: 8),
            ...groupedBunches().map((g) => _bunchSection(g.key, g.value)),
          ],
          const SizedBox(height: 18),
          _title('المبلغ'),
          const SizedBox(height: 7),
          TextField(
            controller: amount,
            onChanged: (_) => setState(() {}),
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 25, fontWeight: FontWeight.w800),
            decoration: const InputDecoration(hintText: '0', suffixText: 'ر.ي', prefixIcon: Icon(Icons.payments_rounded)),
          ),
          if (error != null) Padding(padding: const EdgeInsets.only(top: 14), child: Text(error!, textAlign: TextAlign.right, style: const TextStyle(color: AppColors.red, fontSize: 12))),
          const SizedBox(height: 18),
          ElevatedButton.icon(
            onPressed: (paying || !supports || selectedBunch == null || (double.tryParse(amount.text) ?? 0) <= 0) ? null : submit,
            icon: paying ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send_rounded),
            label: Text(paying ? 'جاري تنفيذ الشحن...' : 'شحن الرصيد'),
            style: ElevatedButton.styleFrom(minimumSize: const Size.fromHeight(54), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(13))),
          ),
        ],
      ),
    );
  }

  Widget _headerCard() => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          const Icon(Icons.sim_card_rounded, size: 34, color: AppColors.primary),
          const SizedBox(height: 8),
          const Text('كبينة السداد', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text('شحن رصيد الاتصالات اليمنية', style: TextStyle(color: AppColors.text2, fontSize: 12)),
        ]),
      );

  Widget _networkCard(bool supports) => Container(
        margin: const EdgeInsets.only(top: 9),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: AppColors.primary.withOpacity(.12), borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.primary.withOpacity(.35))),
        child: Row(children: [
          const Icon(Icons.signal_cellular_alt_rounded, color: AppColors.primary),
          const SizedBox(width: 10),
          Expanded(child: Text('${network!['name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w800))),
          Icon(supports ? Icons.check_circle_rounded : Icons.error_outline, color: supports ? AppColors.green : AppColors.red),
        ]),
      );

  Widget _checkCard() {
    final d = checkData!;
    final balance = d['balance'];
    final loan = d['loan'];
    final offers = d['offers'];
    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        const Text('نتيجة الفحص', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: _stat('الرصيد', balance == null ? '—' : '$balance ر.ي', AppColors.green)),
          const SizedBox(width: 8),
          Expanded(child: _stat('السلفة', (double.tryParse('$loan') ?? 0) > 0 ? '$loan ر.ي' : 'لا توجد', AppColors.gold)),
        ]),
        if (offers is List && offers.isNotEmpty) ...[
          const SizedBox(height: 12),
          Text('العروض المتاحة', style: TextStyle(color: AppColors.text2, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          ...offers.map((o) => Padding(padding: const EdgeInsets.symmetric(vertical: 3), child: Text(o is Map ? '${o['offer_name'] ?? o['name'] ?? o['offer_id'] ?? ''}' : '$o', textAlign: TextAlign.right))),
        ],
      ]),
    );
  }

  Widget _stat(String title, String value, Color valueColor) => Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(12)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(title, style: TextStyle(color: AppColors.text2, fontSize: 11)),
          const SizedBox(height: 4),
          Text(value, style: TextStyle(color: valueColor, fontWeight: FontWeight.w800)),
        ]),
      );

  Widget _bunchSection(String section, List<Map<String, dynamic>> items) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(15), border: Border.all(color: AppColors.border)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(_sectionName(section), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: items.map((b) {
              final price = b['price'];
              final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
              final label = name.isEmpty ? (price == null ? 'اختيار' : '$price ر.ي') : (price == null || '$price' == '0' ? name : '$name — $price ر.ي');
              final selected = selectedBunch != null && '${selectedBunch!['id']}' == '${b['id']}';
              return OutlinedButton(
                onPressed: () => chooseBunch(b),
                style: OutlinedButton.styleFrom(side: BorderSide(color: selected ? AppColors.primary : AppColors.border)),
                child: Text(label, textAlign: TextAlign.center),
              );
            }).toList(),
          ),
        ]),
      );

  Widget _title(String s) => Align(alignment: Alignment.centerRight, child: Text(s, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800)));
}
