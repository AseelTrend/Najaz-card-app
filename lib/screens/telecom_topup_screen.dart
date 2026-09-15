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

  bool loading = false;
  bool checking = false;
  bool paying = false;
  String? error;
  String? activeTab;
  String? _openBundleGroup;
  String? _openFeeGroup;

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
      _openBundleGroup = null;
      _openFeeGroup = null;
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
      network = d['network'] is Map ? Map<String, dynamic>.from(d['network']) : null;
      final id = int.tryParse('${network?['id'] ?? 0}') ?? 0;
      if (id > 0) {
        bunches = await TelecomApiService.bunches(id);
      }
      setState(() {});
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
      checkData = d['data'] is Map ? Map<String, dynamic>.from(d['data']) : d;
      setState(() {});
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  void selectTab(String value) {
    setState(() {
      activeTab = activeTab == value ? null : value;
      error = null;
      if (activeTab == 'amount' || activeTab == null) {
        selectedBunch = null;
      }
      _openBundleGroup = null;
      _openFeeGroup = null;
    });
  }

  void chooseBunch(Map<String, dynamic> b) {
    setState(() {
      selectedBunch = b;
      error = null;
    });
  }

  String _number(double v) => v == v.roundToDouble() ? v.toInt().toString() : v.toString();

  String _sectionName(String section) {
    switch (section) {
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
        return 'الخدمات';
    }
  }

  List<Map<String, dynamic>> _itemsFor(String tab) {
    final result = <Map<String, dynamic>>[];
    for (final item in bunches) {
      if (item is! Map) continue;
      final b = Map<String, dynamic>.from(item);
      final section = '${b['section'] ?? ''}';

      if (tab == 'fees' && section == 'fees') result.add(b);
      if (tab == 'bundles' && section != 'fees' && section != 'amount') result.add(b);
    }
    return result;
  }

  Map<String, List<Map<String, dynamic>>> _group(List<Map<String, dynamic>> items) {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final b in items) {
      final section = '${b['section'] ?? 'bundles'}';
      groups.putIfAbsent(section, () => []).add(b);
    }
    return groups;
  }

  Future<void> submitAmount() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    final a = double.tryParse(amount.text.replaceAll(',', '').trim()) ?? 0;

    if (p.length < 7 || n <= 0) {
      setState(() => error = 'رقم الهاتف غير صحيح');
      return;
    }
    if (a <= 0) {
      setState(() => error = 'أدخل مبلغ الشحن');
      return;
    }

    setState(() {
      paying = true;
      error = null;
    });

    try {
      final d = await TelecomApiService.payBalance(phone: p, networkId: n, amountYer: a);
      if (!mounted) return;
      await _successDialog(d);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  Future<void> submitBunch() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    final b = selectedBunch;
    final bunchId = '${b?['unified_code'] ?? b?['bunch_id'] ?? b?['id'] ?? ''}';
    final a = double.tryParse('${b?['price'] ?? 0}') ?? 0;

    if (p.length < 7 || n <= 0 || b == null || bunchId.isEmpty || a <= 0) {
      setState(() => error = 'اختر الفئة أو الباقة أولاً');
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
      await _successDialog(d);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  Future<void> _successDialog(Map<String, dynamic> d) async {
    await showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('تم إرسال العملية'),
        content: Text(
          '${d['message'] ?? d['msg'] ?? 'تم تنفيذ العملية بنجاح'}\n'
          'رقم الطلب: ${d['order_id'] ?? d['orderId'] ?? '—'}',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً')),
        ],
      ),
    );
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
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 28),
        children: [
          _heroCard(),
          const SizedBox(height: 14),
          _title('رقم الهاتف'),
          const SizedBox(height: 7),
          _phoneField(),
          if (network != null) ...[
            const SizedBox(height: 8),
            _networkCard(supports),
            if (checkData != null) ...[
              const SizedBox(height: 12),
              _checkResultCard(),
            ],
            const SizedBox(height: 14),
            _serviceTabs(),
            if (activeTab != null) ...[
              const SizedBox(height: 14),
              if (activeTab == 'amount') _amountSection(),
              if (activeTab == 'fees') _categoriesSection(),
              if (activeTab == 'bundles') _bundlesSection(),
            ],
          ],
          if (selectedBunch != null && activeTab != 'amount') ...[
            const SizedBox(height: 12),
            _detailsCard(),
          ],
          if (error != null) ...[
            const SizedBox(height: 12),
            _errorCard(error!),
          ],
        ],
      ),
    );
  }

  Widget _phoneField() {
    return Stack(
      children: [
        TextField(
          controller: phone,
          onChanged: changed,
          keyboardType: TextInputType.phone,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 20, letterSpacing: 2, fontWeight: FontWeight.w700),
          decoration: const InputDecoration(
            hintText: '7X XXX XXXX',
            prefixIcon: Icon(Icons.phone_android_rounded),
            contentPadding: EdgeInsets.only(left: 88, right: 14, top: 15, bottom: 15),
          ),
        ),
        Positioned(
          left: 5,
          top: 5,
          bottom: 5,
          child: SizedBox(
            width: 78,
            child: ElevatedButton.icon(
              onPressed: (network == null || checking) ? null : checkNumber,
              icon: checking
                  ? const SizedBox(
                      width: 15,
                      height: 15,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.search_rounded, size: 17),
              label: Text(checking ? '...' : 'فحص', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w900)),
              style: ElevatedButton.styleFrom(
                padding: EdgeInsets.zero,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
              ),
            ),
          ),
        ),
        if (loading)
          const Positioned(
            right: 10,
            top: 0,
            bottom: 0,
            child: Center(child: SizedBox(width: 17, height: 17, child: CircularProgressIndicator(strokeWidth: 2))),
          ),
      ],
    );
  }

  Widget _serviceTabs() => SizedBox(
        height: 45,
        child: Row(
          children: [
            _tab('شحن رصيد', 'amount'),
            const SizedBox(width: 6),
            _tab('الفئات والرسوم', 'fees'),
            const SizedBox(width: 6),
            _tab('باقات', 'bundles'),
          ],
        ),
      );

  Widget _tab(String label, String value) {
    final selected = activeTab == value;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () => selectTab(value),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? AppColors.primary : AppColors.card,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: selected ? AppColors.primary : AppColors.border),
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: selected ? Colors.white : AppColors.text2),
          ),
        ),
      ),
    );
  }

  Widget _amountSection() => Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          _sectionHeader(Icons.account_balance_wallet_rounded, 'شحن الرصيد'),
          const SizedBox(height: 9),
          _panel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text('أدخل المبلغ الذي تريد شحنه', style: TextStyle(color: AppColors.text2, fontSize: 12)),
                const SizedBox(height: 8),
                TextField(
                  controller: amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
                  decoration: const InputDecoration(hintText: '0', suffixText: 'ر.ي', prefixIcon: Icon(Icons.payments_rounded)),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  height: 50,
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: paying ? null : submitAmount,
                    icon: paying
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.send_rounded),
                    label: Text(paying ? 'جاري تنفيذ العملية...' : 'شحن الرصيد'),
                  ),
                ),
              ],
            ),
          ),
        ],
      );

  Widget _categoriesSection() {
    final items = _itemsFor('fees');
    if (items.isEmpty) {
      return _panel(
        child: Text('لا توجد فئات شحن متاحة حالياً', textAlign: TextAlign.right, style: TextStyle(color: AppColors.text2)),
      );
    }

    final groups = <String, List<Map<String, dynamic>>>{};
    for (final b in items) {
      final raw = '${b['bundle_group'] ?? ''}'.trim();
      final name = raw.isEmpty ? 'الفئات والرسوم' : raw;
      groups.putIfAbsent(name, () => []).add(b);
    }

    final children = <Widget>[
      _sectionHeader(Icons.category_rounded, 'الفئات والرسوم'),
      const SizedBox(height: 9),
    ];

    for (final entry in groups.entries) {
      final open = _openFeeGroup == entry.key;
      children.add(
        InkWell(
          onTap: () => setState(() {
            _openFeeGroup = open ? null : entry.key;
            selectedBunch = null;
            error = null;
          }),
          borderRadius: BorderRadius.circular(15),
          child: Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            decoration: BoxDecoration(
              color: open ? AppColors.primary.withOpacity(.13) : AppColors.card,
              borderRadius: BorderRadius.circular(15),
              border: Border.all(color: open ? AppColors.primary : AppColors.border),
            ),
            child: Row(
              children: [
                Icon(open ? Icons.keyboard_arrow_up_rounded : Icons.keyboard_arrow_down_rounded, color: AppColors.primary),
                const SizedBox(width: 8),
                Text('${entry.value.length} فئة', style: TextStyle(color: AppColors.text2, fontSize: 11)),
                const Spacer(),
                Icon(open ? Icons.folder_open_rounded : Icons.folder_rounded, color: AppColors.primary, size: 21),
                const SizedBox(width: 9),
                Expanded(
                  child: Text(entry.key, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.right, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900)),
                ),
              ],
            ),
          ),
        ),
      );
      if (open) {
        children.add(const SizedBox(height: 2));
        children.add(_itemsGrid(entry.value));
        children.add(const SizedBox(height: 8));
      }
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.end, children: children);
  }

  Widget _bundlesSection() {
    final items = _itemsFor('bundles');
    if (items.isEmpty) {
      return _panel(
        child: Text('لا توجد أقسام باقات متاحة حالياً', textAlign: TextAlign.right, style: TextStyle(color: AppColors.text2)),
      );
    }

    final groups = <String, List<Map<String, dynamic>>>{};
    for (final b in items) {
      final raw = '${b['bundle_group'] ?? ''}'.trim();
      final section = '${b['section'] ?? ''}'.trim();
      final name = raw.isNotEmpty ? raw : (section.isNotEmpty ? _sectionName(section) : 'أخرى');
      groups.putIfAbsent(name, () => []).add(b);
    }

    final children = <Widget>[
      _sectionHeader(Icons.language_rounded, 'أقسام الباقات'),
      const SizedBox(height: 9),
    ];

    for (final entry in groups.entries) {
      children.add(_groupRow(entry.key, entry.value.length));
      if (_openBundleGroup == entry.key) {
        children.add(const SizedBox(height: 2));
        children.add(_itemsGrid(entry.value));
        children.add(const SizedBox(height: 8));
      }
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.end, children: children);
  }

  Widget _groupRow(String name, int count) {
    final selected = _openBundleGroup == name;
    return InkWell(
      onTap: () {
        setState(() {
          selectedBunch = null;
          error = null;
          _openBundleGroup = _openBundleGroup == name ? null : name;
        });
      },
      borderRadius: BorderRadius.circular(15),
      child: Container(
        width: double.infinity,
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        decoration: BoxDecoration(
          color: selected ? AppColors.primary.withOpacity(.13) : AppColors.card,
          borderRadius: BorderRadius.circular(15),
          border: Border.all(color: selected ? AppColors.primary : AppColors.border),
        ),
        child: Row(
          children: [
            Icon(selected ? Icons.keyboard_arrow_up_rounded : Icons.keyboard_arrow_down_rounded, color: AppColors.primary),
            const SizedBox(width: 8),
            Text('$count باقة', style: TextStyle(color: AppColors.text2, fontSize: 11)),
            const Spacer(),
            Icon(selected ? Icons.folder_open_rounded : Icons.folder_rounded, color: AppColors.primary, size: 21),
            const SizedBox(width: 9),
            Expanded(
              child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.right, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _itemsGrid(List<Map<String, dynamic>> items) {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: items.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 9,
        mainAxisSpacing: 9,
        childAspectRatio: 1.13,
      ),
      itemBuilder: (_, index) {
        final b = items[index];
        final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
        final validity = '${b['validity'] ?? b['duration'] ?? ''}'.trim();
        final baseName = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
        final name = baseName.isEmpty ? (price > 0 ? '${_number(price)} ر.ي' : 'الخدمة') : baseName;
        final selected = selectedBunch != null && '${selectedBunch!['id']}' == '${b['id']}';
        return _bunchCard(b, name, price, selected, validity: validity);
      },
    );
  }

  Widget _itemsSection(String title, IconData icon, List<Map<String, dynamic>> items) {
    if (items.isEmpty) {
      return _panel(
        child: Row(
          children: [
            Icon(Icons.info_outline, color: AppColors.text2, size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text('لا توجد خدمات متاحة حالياً', textAlign: TextAlign.right, style: TextStyle(color: AppColors.text2))),
          ],
        ),
      );
    }

    final groups = _group(items);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: groups.entries.map((entry) {
        final sectionTitle = entry.key == 'fees' ? title : _sectionName(entry.key);
        return Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              _sectionHeader(icon, sectionTitle),
              const SizedBox(height: 9),
              GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: entry.value.length,
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  crossAxisSpacing: 9,
                  mainAxisSpacing: 9,
                  childAspectRatio: 1.13,
                ),
                itemBuilder: (_, index) {
                  final b = entry.value[index];
                  final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
                  final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
                  final selected = selectedBunch != null && '${selectedBunch!['id']}' == '${b['id']}';
                  return _bunchCard(
                    b,
                    name.isEmpty ? (price > 0 ? '${_number(price)} ر.ي' : 'الخدمة') : name,
                    price,
                    selected,
                  );
                },
              ),
            ],
          ),
        );
      }).toList(),
    );
  }

  Widget _checkResultCard() {
    final d = checkData ?? <String, dynamic>{};
    final nested = d['data'];
    final source = nested is Map ? Map<String, dynamic>.from(nested) : d;
    final invoice = source['invoice'] ?? source['bill'] ?? source['bill_amount'] ?? source['amount_due'] ?? source['due_amount'] ?? 0;
    final balance = source['balance'];
    final loan = source['loan'];
    final rawOffers = source['offers'] ?? d['offers'];

    final offerList = <Map<String, dynamic>>[];
    if (rawOffers is List) {
      for (final item in rawOffers) {
        if (item is Map) offerList.add(Map<String, dynamic>.from(item));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        _panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              _sectionHeader(Icons.fact_check_rounded, 'نتيجة الفحص'),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(child: _stat('مبلغ الفاتورة', invoice == null ? '0 ر.ي' : '$invoice ر.ي', AppColors.purple, Icons.receipt_long_rounded)),
                  const SizedBox(width: 7),
                  Expanded(child: _stat('الرصيد', balance == null ? '—' : '$balance ر.ي', AppColors.purple, Icons.account_balance_wallet_rounded)),
                  const SizedBox(width: 7),
                  Expanded(child: _stat('السلفة', loan == null || '$loan' == '0' || '$loan' == '0.0' ? 'لا توجد' : '$loan ر.ي', AppColors.purple, Icons.credit_score_rounded)),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        _panel(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              _sectionHeader(Icons.local_offer_rounded, 'العروض المتاحة'),
              const SizedBox(height: 10),
              if (offerList.isEmpty)
                Text('لا توجد عروض متاحة لهذا الرقم حالياً', textAlign: TextAlign.right, style: TextStyle(color: AppColors.text2, fontSize: 12))
              else
                Column(
                  children: offerList.map((offer) {
                    final name = '${offer['offer_name'] ?? offer['name'] ?? offer['offer_id'] ?? 'عرض'}';
                    final price = offer['price'] ?? offer['amount'];
                    final duration = offer['validity'] ?? offer['duration'];
                    return Container(
                      width: double.infinity,
                      margin: const EdgeInsets.only(bottom: 8),
                      padding: const EdgeInsets.all(11),
                      decoration: BoxDecoration(
                        color: AppColors.card2,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: AppColors.border),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.chevron_left_rounded, color: AppColors.primary),
                          const Spacer(),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text(name, textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w800)),
                                if (price != null || duration != null) ...[
                                  const SizedBox(height: 3),
                                  Text(
                                    [if (price != null) '$price ر.ي', if (duration != null) '$duration'].join(' • '),
                                    textAlign: TextAlign.right,
                                    style: TextStyle(color: AppColors.text2, fontSize: 10),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    );
                  }).toList(),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _bunchCard(Map<String, dynamic> b, String name, double price, bool selected, {String validity = ''}) => InkWell(
        onTap: () => chooseBunch(b),
        borderRadius: BorderRadius.circular(16),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: const EdgeInsets.fromLTRB(11, 11, 11, 9),
          decoration: BoxDecoration(
            color: selected ? AppColors.primary.withOpacity(.13) : AppColors.card,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.5 : 1),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Row(
                children: [
                  if (selected) const Icon(Icons.check_circle_rounded, color: AppColors.primary, size: 19),
                  const Spacer(),
                  Flexible(child: Text(name, maxLines: 2, overflow: TextOverflow.ellipsis, textAlign: TextAlign.right, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900))),
                ],
              ),
              const Spacer(),
              if (price > 0) Text('${_number(price)} ر.ي', style: TextStyle(color: AppColors.text2, fontSize: 11)),
              if (validity.isNotEmpty) ...[
                const SizedBox(height: 3),
                Text(validity, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.text2, fontSize: 10)),
              ],
              const SizedBox(height: 8),
              SizedBox(
                width: double.infinity,
                height: 36,
                child: OutlinedButton(
                  onPressed: () => chooseBunch(b),
                  style: OutlinedButton.styleFrom(
                    side: const BorderSide(color: AppColors.primary),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    padding: EdgeInsets.zero,
                  ),
                  child: Text(selected ? 'تم الاختيار' : 'اختيار', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
                ),
              ),
            ],
          ),
        ),
      );

  Widget _detailsCard() {
    final b = selectedBunch!;
    final name = '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? 'الخدمة'}';
    final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
    final logo = '${network?['logo'] ?? ''}'.trim();

    return _panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Row(
            children: [
              Icon(Icons.receipt_long_rounded, color: AppColors.text2, size: 19),
              const Spacer(),
              const Text('تفاصيل العملية', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800)),
            ],
          ),
          const SizedBox(height: 10),
          if (logo.isNotEmpty) Align(alignment: Alignment.centerRight, child: _networkLogo(logo)),
          _detailRow('الخدمة', name),
          _detailRow('رقم الهاتف', phone.text),
          _detailRow('السعر', '${_number(price)} ر.ي'),
          _detailRow('الخصم من الرصيد', '${_number(price)} ر.ي', valueColor: AppColors.green),
          const SizedBox(height: 10),
          SizedBox(
            height: 50,
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: paying ? null : submitBunch,
              icon: paying ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send_rounded),
              label: Text(paying ? 'جاري تنفيذ العملية...' : 'شحن الآن'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _networkCard(bool supports) {
    final logo = '${network?['logo'] ?? ''}'.trim();
    final name = '${network?['name'] ?? network?['name_ar'] ?? ''}';
    return _panel(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Icon(supports ? Icons.check_circle_rounded : Icons.error_outline, color: supports ? AppColors.green : AppColors.red),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(name, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900)),
                const SizedBox(height: 3),
                Text('تم التعرف على الشبكة تلقائياً', style: TextStyle(color: AppColors.text2, fontSize: 11)),
              ],
            ),
          ),
          const SizedBox(width: 10),
          _networkLogo(logo),
        ],
      ),
    );
  }

  Widget _networkLogo(String logo) {
    if (logo.isEmpty) {
      return Container(
        width: 52,
        height: 52,
        decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(12)),
        child: const Icon(Icons.signal_cellular_alt_rounded, color: AppColors.primary),
      );
    }
    return Container(
      width: 52,
      height: 52,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Image.network(logo, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const Icon(Icons.sim_card_rounded, color: AppColors.primary)),
    );
  }

  Widget _sectionHeader(IconData icon, String title) => Row(
        children: [
          Icon(icon, color: AppColors.purple, size: 21),
          const Spacer(),
          Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900)),
        ],
      );

  Widget _stat(String title, String value, Color valueColor, IconData icon) => Container(
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(13), border: Border.all(color: AppColors.border)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Row(children: [Icon(icon, color: AppColors.purple, size: 16), const Spacer(), Text(title, style: TextStyle(color: AppColors.text2, fontSize: 10))]),
            const SizedBox(height: 5),
            Text(value, style: TextStyle(color: valueColor, fontWeight: FontWeight.w900, fontSize: 15)),
          ],
        ),
      );

  Widget _detailRow(String title, String value, {Color? valueColor}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 7),
        child: Row(children: [Text(value, style: TextStyle(fontWeight: FontWeight.w800, color: valueColor ?? AppColors.text)), const Spacer(), Text(title, style: TextStyle(color: AppColors.text2, fontSize: 11))]),
      );

  Widget _panel({required Widget child, EdgeInsets padding = const EdgeInsets.all(14)}) => Container(
        padding: padding,
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
        child: child,
      );

  Widget _heroCard() => _panel(
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 15),
        child: Row(
          children: [
            Container(width: 44, height: 44, decoration: BoxDecoration(gradient: AppColors.balanceGradient, borderRadius: BorderRadius.circular(13)), child: const Icon(Icons.sim_card_rounded, color: Colors.white)),
            const Spacer(),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
                const SizedBox(height: 3),
                Text('شحن رصيد الاتصالات اليمنية', style: TextStyle(color: AppColors.text2, fontSize: 12)),
              ],
            ),
          ],
        ),
      );

  Widget _errorCard(String message) => Container(
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(color: AppColors.red.withOpacity(.08), borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.red.withOpacity(.22))),
        child: Text(message, textAlign: TextAlign.right, style: const TextStyle(color: AppColors.red, fontSize: 12)),
      );

  Widget _title(String s) => Align(alignment: Alignment.centerRight, child: Text(s, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800)));
}
