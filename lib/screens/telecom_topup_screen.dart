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
  Map<String, dynamic>? checkData;
  Map<String, dynamic>? selectedBunch;
  List<dynamic> bunches = [];

  String? error;
  String? activeTab;
  String? activeBundleGroup;
  bool loading = false;
  bool checking = false;
  bool paying = false;

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
      activeBundleGroup = null;
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
      final d = await TelecomApiService.checkService(
        phone: p,
        networkId: n,
      );
      if (!mounted) return;
      final raw = d['data'];
      checkData = raw is Map ? Map<String, dynamic>.from(raw) : d;
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
      selectedBunch = null;
      activeBundleGroup = null;
    });
  }

  void selectBundleGroup(String group) {
    setState(() {
      activeBundleGroup = activeBundleGroup == group ? null : group;
      selectedBunch = null;
    });
  }

  String money(double value) {
    return value == value.roundToDouble()
        ? value.toInt().toString()
        : value.toString();
  }

  List<Map<String, dynamic>> items(String type) {
    final out = <Map<String, dynamic>>[];
    for (final x in bunches) {
      if (x is! Map) continue;
      final b = Map<String, dynamic>.from(x);
      final section = '${b['section'] ?? ''}';
      if (type == 'fees' && section == 'fees') {
        out.add(b);
      }
      if (type == 'bundles' && section != 'fees' && section != 'amount') {
        out.add(b);
      }
    }
    return out;
  }

  Map<String, List<Map<String, dynamic>>> bundleGroups() {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final b in items('bundles')) {
      final raw = '${b['bundle_group'] ?? ''}'.trim();
      final name = raw.isEmpty ? 'أخرى' : raw;
      groups.putIfAbsent(name, () => []).add(b);
    }
    return groups;
  }

  Future<void> payAmount() async {
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
      final d = await TelecomApiService.payBalance(
        phone: p,
        networkId: n,
        amountYer: a,
      );
      if (mounted) await success(d);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  Future<void> payBunch() async {
    final p = phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    final n = int.tryParse('${network?['id'] ?? 0}') ?? 0;
    final b = selectedBunch;
    final id = '${b?['unified_code'] ?? b?['bunch_id'] ?? b?['id'] ?? ''}';
    final a = double.tryParse('${b?['price'] ?? 0}') ?? 0;

    if (p.length < 7 || n <= 0 || b == null || id.isEmpty || a <= 0) {
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
        bunchId: id,
        amountYer: a,
      );
      if (mounted) await success(d);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => paying = false);
    }
  }

  Future<void> success(Map<String, dynamic> data) async {
    await showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('تم إرسال العملية'),
        content: Text(
          '${data['message'] ?? data['msg'] ?? 'تم تنفيذ العملية بنجاح'}\n'
          'رقم الطلب: ${data['order_id'] ?? data['orderId'] ?? '—'}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('حسناً'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final supports = network?['supports_balance'] == true ||
        '${network?['supports_balance']}' == '1';

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        title: Text(
          widget.categoryName.isEmpty ? 'كبينة السداد' : widget.categoryName,
        ),
        backgroundColor: AppColors.bg,
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 28),
        children: [
          hero(),
          const SizedBox(height: 14),
          title('رقم الهاتف'),
          const SizedBox(height: 7),
          phoneField(),
          if (network != null) ...[
            const SizedBox(height: 8),
            networkCard(supports),
            const SizedBox(height: 12),
            checkButton(),
            if (checkData != null) ...[
              const SizedBox(height: 12),
              checkResult(),
            ],
            const SizedBox(height: 14),
            tabs(),
            if (activeTab != null) ...[
              const SizedBox(height: 14),
              content(),
            ],
          ],
          if (selectedBunch != null && activeTab != 'amount') ...[
            const SizedBox(height: 12),
            details(),
          ],
          if (error != null) ...[
            const SizedBox(height: 12),
            errorCard(error!),
          ],
        ],
      ),
    );
  }

  Widget content() {
    switch (activeTab) {
      case 'amount':
        return amountSection();
      case 'fees':
        return feesSection();
      case 'bundles':
        return bundleGroupsSection();
      default:
        return const SizedBox.shrink();
    }
  }

  Widget amountSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        header(Icons.account_balance_wallet_rounded, 'شحن الرصيد'),
        const SizedBox(height: 9),
        panel(
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                'أدخل المبلغ الذي تريد شحنه',
                style: TextStyle(color: AppColors.text2, fontSize: 12),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: amount,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                ),
                decoration: const InputDecoration(
                  hintText: '0',
                  suffixText: 'ر.ي',
                  prefixIcon: Icon(Icons.payments_rounded),
                ),
              ),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: paying ? null : payAmount,
                  icon: paying
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.send_rounded),
                  label: Text(paying ? 'جاري التنفيذ...' : 'شحن الرصيد'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget feesSection() {
    final list = items('fees');
    if (list.isEmpty) {
      return panel(
        Text(
          'لا توجد فئات شحن متاحة حالياً',
          textAlign: TextAlign.right,
          style: TextStyle(color: AppColors.text2),
        ),
      );
    }

    final groups = <String, List<Map<String, dynamic>>>{};
    for (final b in list) {
      final raw = '${b['bundle_group'] ?? ''}'.trim();
      final name = raw.isEmpty ? 'الفئات والرسوم' : raw;
      groups.putIfAbsent(name, () => []).add(b);
    }

    final children = <Widget>[
      header(Icons.category_rounded, 'الفئات والرسوم'),
      const SizedBox(height: 9),
    ];

    for (final entry in groups.entries) {
      children.add(sectionRow(
        entry.key,
        '${entry.value.length} فئة',
        activeBundleGroup == entry.key,
        () => selectBundleGroup(entry.key),
      ));

      if (activeBundleGroup == entry.key) {
        children.add(const SizedBox(height: 9));
        children.add(bundleItemsSection(entry.key, entry.value));
        children.add(const SizedBox(height: 9));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: children,
    );
  }

  Widget bundleGroupsSection() {
    final groups = bundleGroups();
    if (groups.isEmpty) {
      return panel(
        Text(
          'لا توجد أقسام باقات متاحة حالياً',
          textAlign: TextAlign.right,
          style: TextStyle(color: AppColors.text2),
        ),
      );
    }

    final children = <Widget>[
      header(Icons.language_rounded, 'أقسام الباقات'),
      const SizedBox(height: 9),
    ];

    for (final entry in groups.entries) {
      final selected = activeBundleGroup == entry.key;
      children.add(sectionRow(
        entry.key,
        '${entry.value.length} باقة',
        selected,
        () => selectBundleGroup(entry.key),
      ));

      if (selected) {
        children.add(const SizedBox(height: 9));
        children.add(bundleItemsSection(entry.key, entry.value));
        children.add(const SizedBox(height: 9));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: children,
    );
  }

  Widget sectionRow(
    String name,
    String count,
    bool selected,
    VoidCallback onTap,
  ) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(15),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        margin: const EdgeInsets.only(bottom: 7),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primary.withOpacity(.13)
              : AppColors.card,
          borderRadius: BorderRadius.circular(15),
          border: Border.all(
            color: selected ? AppColors.primary : AppColors.border,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Icon(
              selected
                  ? Icons.keyboard_arrow_up_rounded
                  : Icons.keyboard_arrow_down_rounded,
              color: AppColors.primary,
              size: 23,
            ),
            const SizedBox(width: 8),
            Text(
              count,
              style: TextStyle(color: AppColors.text2, fontSize: 11),
            ),
            const Spacer(),
            Icon(
              selected ? Icons.folder_open_rounded : Icons.folder_rounded,
              color: AppColors.primary,
              size: 22,
            ),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.right,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget bundleItemsSection(
    String group,
    List<Map<String, dynamic>> list,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        header(Icons.folder_open_rounded, group),
        const SizedBox(height: 9),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: list.length,
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            crossAxisSpacing: 9,
            mainAxisSpacing: 9,
            childAspectRatio: 1.13,
          ),
          itemBuilder: (_, i) {
            final b = list[i];
            final price = double.tryParse('${b['price'] ?? 0}') ?? 0;
            final name =
                '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? ''}'.trim();
            final selected = selectedBunch != null &&
                '${selectedBunch!['id']}' == '${b['id']}';
            return bunchCard(
              b,
              name.isEmpty ? 'الخدمة' : name,
              price,
              selected,
            );
          },
        ),
      ],
    );
  }

  Widget bunchCard(
    Map<String, dynamic> b,
    String name,
    double price,
    bool selected,
  ) {
    return InkWell(
      onTap: () => setState(() => selectedBunch = b),
      borderRadius: BorderRadius.circular(16),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        padding: const EdgeInsets.fromLTRB(11, 11, 11, 9),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primary.withOpacity(.13)
              : AppColors.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: selected ? AppColors.primary : AppColors.border,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Row(
              children: [
                if (selected)
                  const Icon(
                    Icons.check_circle_rounded,
                    color: AppColors.primary,
                    size: 19,
                  ),
                const Spacer(),
                Flexible(
                  child: Text(
                    name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              ],
            ),
            const Spacer(),
            if (price > 0)
              Text(
                '${money(price)} ر.ي',
                style: TextStyle(color: AppColors.text2, fontSize: 11),
              ),
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              height: 36,
              child: OutlinedButton(
                onPressed: () => setState(() => selectedBunch = b),
                style: OutlinedButton.styleFrom(
                  side: const BorderSide(color: AppColors.primary),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                  padding: EdgeInsets.zero,
                ),
                child: Text(
                  selected ? 'تم الاختيار' : 'اختيار',
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget checkResult() {
    final d = checkData ?? <String, dynamic>{};
    final rawOffers = d['offers'];
    final children = <Widget>[
      header(Icons.fact_check_rounded, 'نتيجة الفحص'),
      const SizedBox(height: 10),
      Row(
        children: [
          Expanded(
            child: stat(
              'الرصيد',
              d['balance'] == null ? '—' : '${d['balance']} ر.ي',
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: stat(
              'السلفة',
              d['loan'] == null ? '0 ر.ي' : '${d['loan']} ر.ي',
            ),
          ),
        ],
      ),
      const SizedBox(height: 12),
      Row(
        children: [
          const Icon(
            Icons.local_offer_rounded,
            color: AppColors.purple,
            size: 20,
          ),
          const Spacer(),
          const Text(
            'العروض والباقات المتاحة',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w900),
          ),
        ],
      ),
      const SizedBox(height: 8),
    ];

    if (rawOffers is List && rawOffers.isNotEmpty) {
      for (final offer in rawOffers) {
        String name;
        String details = '';
        if (offer is Map) {
          name =
              '${offer['offer_name'] ?? offer['name'] ?? offer['offer_id'] ?? 'عرض'}';
          final price = offer['price'] ?? offer['amount'];
          final validity = offer['validity'] ?? offer['duration'];
          if (price != null && '$price'.isNotEmpty) {
            details = '${price} ر.ي';
          }
          if (validity != null && '$validity'.isNotEmpty) {
            details = details.isEmpty ? '$validity' : '$details • $validity';
          }
        } else {
          name = '$offer';
        }

        children.add(
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 7),
            padding: const EdgeInsets.all(11),
            decoration: BoxDecoration(
              color: AppColors.card2,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.border),
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.chevron_left_rounded,
                  color: AppColors.primary,
                ),
                const Spacer(),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        name,
                        textAlign: TextAlign.right,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      if (details.isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text(
                          details,
                          textAlign: TextAlign.right,
                          style: TextStyle(
                            color: AppColors.text2,
                            fontSize: 10,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      }
    } else {
      children.add(
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(11),
          decoration: BoxDecoration(
            color: AppColors.card2,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            'لا توجد عروض متاحة لهذا الرقم حالياً',
            textAlign: TextAlign.right,
            style: TextStyle(color: AppColors.text2, fontSize: 12),
          ),
        ),
      );
    }

    return panel(
      Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: children,
      ),
    );
  }

  Widget details() {
    final b = selectedBunch!;
    final name =
        '${b['bunch_name'] ?? b['name'] ?? b['code'] ?? 'الخدمة'}';
    final price = double.tryParse('${b['price'] ?? 0}') ?? 0;

    return panel(
      Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          header(Icons.receipt_long_rounded, 'تفاصيل العملية'),
          const SizedBox(height: 10),
          row('الخدمة', name),
          row('رقم الهاتف', phone.text),
          row('السعر', '${money(price)} ر.ي'),
          row('الخصم من الرصيد', '${money(price)} ر.ي', AppColors.green),
          const SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            height: 50,
            child: ElevatedButton.icon(
              onPressed: paying ? null : payBunch,
              icon: paying
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.send_rounded),
              label: Text(paying ? 'جاري التنفيذ...' : 'شحن الآن'),
            ),
          ),
        ],
      ),
    );
  }

  Widget phoneField() {
    return TextField(
      controller: phone,
      onChanged: changed,
      keyboardType: TextInputType.phone,
      textAlign: TextAlign.center,
      style: const TextStyle(
        fontSize: 20,
        letterSpacing: 2,
        fontWeight: FontWeight.w700,
      ),
      decoration: InputDecoration(
        hintText: '7X XXX XXXX',
        prefixIcon: const Icon(Icons.phone_android_rounded),
        suffixIcon: loading
            ? const Padding(
                padding: EdgeInsets.all(14),
                child: SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              )
            : IconButton(
                onPressed: () {
                  phone.clear();
                  changed('');
                },
                icon: const Icon(Icons.clear_rounded),
              ),
      ),
    );
  }

  Widget checkButton() {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton.icon(
        onPressed: checking ? null : checkNumber,
        icon: checking
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.search_rounded),
        label: Text(
          checking ? 'جاري الفحص...' : 'فحص الرصيد والسلفة والباقات',
        ),
      ),
    );
  }

  Widget tabs() {
    return SizedBox(
      height: 45,
      child: Row(
        children: [
          tabButton('شحن رصيد', 'amount'),
          const SizedBox(width: 5),
          tabButton('الفئات والرسوم', 'fees'),
          const SizedBox(width: 5),
          tabButton('باقات', 'bundles'),
        ],
      ),
    );
  }

  Widget tabButton(String text, String value) {
    final selected = activeTab == value;
    return Expanded(
      child: InkWell(
        onTap: () => selectTab(value),
        borderRadius: BorderRadius.circular(22),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? AppColors.primary : AppColors.card,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: selected ? AppColors.primary : AppColors.border,
            ),
          ),
          child: Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 9,
              fontWeight: FontWeight.w800,
              color: selected ? Colors.white : AppColors.text2,
            ),
          ),
        ),
      ),
    );
  }

  Widget networkCard(bool supports) {
    final logo = '${network?['logo'] ?? ''}'.trim();
    final name = '${network?['name'] ?? network?['name_ar'] ?? ''}';
    return panel(
      Row(
        children: [
          Icon(
            supports ? Icons.check_circle_rounded : Icons.error_outline,
            color: supports ? AppColors.green : AppColors.red,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  'تم التعرف على الشبكة تلقائياً',
                  style: TextStyle(color: AppColors.text2, fontSize: 11),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          networkLogo(logo),
        ],
      ),
    );
  }

  Widget networkLogo(String logo) {
    if (logo.isEmpty) {
      return Container(
        width: 52,
        height: 52,
        decoration: BoxDecoration(
          color: AppColors.card2,
          borderRadius: BorderRadius.circular(12),
        ),
        child: const Icon(
          Icons.signal_cellular_alt_rounded,
          color: AppColors.primary,
        ),
      );
    }

    return Container(
      width: 52,
      height: 52,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Image.network(
        logo,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) => const Icon(
          Icons.sim_card_rounded,
          color: AppColors.primary,
        ),
      ),
    );
  }

  Widget hero() {
    return panel(
      Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              gradient: AppColors.balanceGradient,
              borderRadius: BorderRadius.circular(13),
            ),
            child: const Icon(
              Icons.sim_card_rounded,
              color: Colors.white,
            ),
          ),
          const Spacer(),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                widget.categoryName.isEmpty
                    ? 'كبينة السداد'
                    : widget.categoryName,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                'شحن رصيد الاتصالات اليمنية',
                style: TextStyle(color: AppColors.text2, fontSize: 12),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget header(IconData icon, String text) {
    return Row(
      children: [
        Icon(icon, color: AppColors.purple, size: 21),
        const Spacer(),
        Text(
          text,
          style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w900,
          ),
        ),
      ],
    );
  }

  Widget stat(String titleText, String value) {
    return Container(
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: AppColors.card2,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            titleText,
            style: TextStyle(color: AppColors.text2, fontSize: 10),
          ),
          const SizedBox(height: 5),
          Text(
            value,
            style: TextStyle(
              color: AppColors.purple,
              fontWeight: FontWeight.w900,
              fontSize: 15,
            ),
          ),
        ],
      ),
    );
  }

  Widget row(String titleText, String value, [Color? color]) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: [
          Text(
            value,
            style: TextStyle(
              fontWeight: FontWeight.w800,
              color: color ?? AppColors.text,
            ),
          ),
          const Spacer(),
          Text(
            titleText,
            style: TextStyle(color: AppColors.text2, fontSize: 11),
          ),
        ],
      ),
    );
  }

  Widget panel(Widget child) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.border),
      ),
      child: child,
    );
  }

  Widget errorCard(String message) {
    return Container(
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: AppColors.red.withOpacity(.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.red.withOpacity(.22)),
      ),
      child: Text(
        message,
        textAlign: TextAlign.right,
        style: const TextStyle(
          color: AppColors.red,
          fontSize: 12,
        ),
      ),
    );
  }

  Widget title(String text) {
    return Align(
      alignment: Alignment.centerRight,
      child: Text(
        text,
        style: const TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
