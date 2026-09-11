import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import '../utils/format.dart';
import 'topup_screen.dart';

class ServiceDetailScreen extends StatefulWidget {
  final int serviceId;
  const ServiceDetailScreen({super.key, required this.serviceId});

  @override
  State<ServiceDetailScreen> createState() => _ServiceDetailScreenState();
}

class _ServiceDetailScreenState extends State<ServiceDetailScreen> {
  Map<String, dynamic>? _service;
  final Map<String, TextEditingController> _fieldControllers = {};
  final _couponCtrl = TextEditingController();
  final _quantityCtrl = TextEditingController();
  int _quantity = 1;
  bool _loading = true;
  bool _hasCoupons = false;
  bool _placing = false;
  String? _error;
  double _userBalance = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final service = await ApiService.getServiceDetail(widget.serviceId);
      var hasCoupons = false;
      try {
        hasCoupons = await ApiService.serviceHasCoupons(widget.serviceId);
      } catch (_) {}
      final user = await StorageService.getUser();
      for (final f in (service['fields'] as List<dynamic>)) {
        _fieldControllers[f['field_name']] = TextEditingController();
      }
      setState(() {
        _service = service;
        _hasCoupons = hasCoupons;
        _quantity = _minQuantity;
        _quantityCtrl.text = '$_quantity';
        _userBalance = double.tryParse('${user['balance'] ?? 0}') ?? 0;
      });
    } catch (e) {
      setState(() => _error = 'تعذر تحميل الخدمة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    for (final controller in _fieldControllers.values) {
      controller.dispose();
    }
    _couponCtrl.dispose();
    _quantityCtrl.dispose();
    super.dispose();
  }

  int get _minQuantity => int.tryParse('${_service?['min_qty'] ?? 1}') ?? 1;
  int get _maxQuantity => int.tryParse('${_service?['max_qty'] ?? 9999}') ?? 9999;
  double get _unitPrice => double.tryParse('${_service?['price'] ?? 0}') ?? 0;
  double get _totalPrice => _unitPrice * _quantity;
  bool get _hasEnoughBalance => _userBalance >= _totalPrice;

  Future<void> _goToTopup() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const TopupScreen(initialTab: 0)));
    final user = await StorageService.getUser();
    if (mounted) setState(() => _userBalance = double.tryParse('${user['balance'] ?? 0}') ?? 0);
  }

  void _setQuantity(int value) {
    final clamped = value.clamp(_minQuantity, _maxQuantity).toInt();
    setState(() {
      _quantity = clamped;
      _quantityCtrl.value = TextEditingValue(
        text: '$clamped',
        selection: TextSelection.collapsed(offset: '$clamped'.length),
      );
    });
  }

  void _quantityChanged(String value) {
    final parsed = int.tryParse(value);
    if (parsed == null) return;
    setState(() => _quantity = parsed.clamp(_minQuantity, _maxQuantity).toInt());
  }

  void _normalizeQuantity() => _setQuantity(int.tryParse(_quantityCtrl.text) ?? _minQuantity);

  Future<void> _placeOrder() async {
    for (final f in (_service!['fields'] as List<dynamic>)) {
      if (f['is_required'] == 1 && (_fieldControllers[f['field_name']]?.text.trim().isEmpty ?? true)) {
        setState(() => _error = 'الحقل "${f['field_label']}" مطلوب');
        return;
      }
    }

    if (!_hasEnoughBalance) {
      setState(() => _error = 'رصيدك الحالي غير كافٍ لإتمام هذا الطلب. يرجى شحن الرصيد أولاً.');
      return;
    }

    setState(() {
      _placing = true;
      _error = null;
    });

    try {
      final fields = <String, String>{};
      _fieldControllers.forEach((key, ctrl) => fields[key] = ctrl.text.trim());

      final result = await ApiService.placeOrder(
        serviceId: widget.serviceId,
        quantity: _quantity,
        fields: fields,
        couponCode: _couponCtrl.text.trim(),
      );

      if (!mounted) return;
      _showSuccessDialog(result);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر إتمام الطلب، حاول مجدداً');
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  void _showSuccessDialog(Map<String, dynamic> result) {
    bool codeCopied = false;
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: const [
            Icon(Icons.check_circle_rounded, color: AppColors.green),
            SizedBox(width: 8),
            Text('تم الطلب بنجاح', style: TextStyle(color: AppColors.text, fontSize: 16)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (result['order_ref'] != null)
              Text('رقم الطلب: ${result['order_ref']}', style: const TextStyle(color: AppColors.text2)),
            if (result['new_balance'] != null)
              Text('رصيدك الجديد: \$${result['new_balance']}', style: const TextStyle(color: AppColors.text2)),
            if (result['delivered_code'] != null && result['delivered_code'].toString().isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(10), border: Border.all(color: AppColors.primary.withOpacity(.3))),
                child: Row(children: [
                  Expanded(child: SelectableText(result['delivered_code'].toString(), style: const TextStyle(color: AppColors.cyan, fontWeight: FontWeight.bold, fontFamily: 'monospace'))),
                  StatefulBuilder(
                    builder: (context, setCopyState) {
                      return TextButton.icon(
                        onPressed: () async {
                          await Clipboard.setData(ClipboardData(text: result['delivered_code'].toString()));
                          setCopyState(() => codeCopied = true);
                        },
                        icon: Icon(codeCopied ? Icons.check_rounded : Icons.copy_rounded, size: 14),
                        label: Text(codeCopied ? 'تم النسخ' : 'نسخ', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                        style: TextButton.styleFrom(foregroundColor: AppColors.primary, backgroundColor: AppColors.primary.withOpacity(.12), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                      );
                    },
                  ),
                ]),
              ),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.of(context).pop();
              Navigator.of(context).pop();
            },
            child: const Text('حسناً', style: TextStyle(color: AppColors.primary)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        iconTheme: const IconThemeData(color: AppColors.text),
        title: Text(_service?['name'] ?? '', style: const TextStyle(color: AppColors.text, fontSize: 16)),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : _service == null
              ? Center(child: Text(_error ?? 'خطأ', style: const TextStyle(color: AppColors.text)))
              : SafeArea(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_service!['image'] != null && _service!['image'].toString().isNotEmpty)
                          ClipRRect(
                            borderRadius: BorderRadius.circular(16),
                            child: CachedNetworkImage(imageUrl: 'https://njaz.net/${_service!['image']}',
                                height: 160, fit: BoxFit.cover,
                              errorWidget: (_, __, ___) => const SizedBox()),
                          ),
                        const SizedBox(height: 16),
                        Text(_service!['name'] ?? '',
                            style: const TextStyle(color: AppColors.text, fontSize: 18, fontWeight: FontWeight.bold)),
                        const SizedBox(height: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          decoration: BoxDecoration(
                            color: AppColors.primary.withOpacity(0.15),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text('\$${_service!['price']}',
                              style: const TextStyle(color: AppColors.primary, fontSize: 15, fontWeight: FontWeight.bold)),
                        ),
                        if ((_service!['description'] ?? '').toString().isNotEmpty) ...[
                          const SizedBox(height: 12),
                          Text(_service!['description'], style: const TextStyle(color: AppColors.text2)),
                        ],
                        const SizedBox(height: 20),

                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                          decoration: BoxDecoration(
                            color: AppColors.card,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: AppColors.border),
                          ),
                          child: Row(
                            children: [
                              const Text('الكمية', style: TextStyle(color: AppColors.text)),
                              const Spacer(),
                              IconButton(
                                onPressed: _quantity > _minQuantity ? () => _setQuantity(_quantity - 1) : null,
                                icon: const Icon(Icons.remove_circle_outline_rounded, color: AppColors.text2),
                              ),
                              SizedBox(
                                width: 72,
                                child: TextField(
                                  controller: _quantityCtrl,
                                  onChanged: _quantityChanged,
                                  onEditingComplete: _normalizeQuantity,
                                  keyboardType: TextInputType.number,
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(color: AppColors.text, fontSize: 16, fontWeight: FontWeight.bold),
                                  decoration: const InputDecoration(
                                    isDense: true,
                                    filled: true,
                                    fillColor: AppColors.card2,
                                    border: OutlineInputBorder(borderSide: BorderSide.none),
                                    contentPadding: EdgeInsets.symmetric(vertical: 9),
                                  ),
                                ),
                              ),
                              IconButton(
                                onPressed: _quantity < _maxQuantity ? () => _setQuantity(_quantity + 1) : null,
                                icon: const Icon(Icons.add_circle_outline_rounded, color: AppColors.text2),
                              ),
                            ],
                          ),
                        ),

                        Container(
                          margin: const EdgeInsets.only(top: 12),
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          decoration: BoxDecoration(
                            color: AppColors.primary.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: AppColors.primary.withOpacity(0.2)),
                          ),
                          child: Column(children: [
                            Row(
                              children: [
                                const Text('الإجمالي', style: TextStyle(color: AppColors.text2, fontSize: 13)),
                                const Spacer(),
                                Text(
                                  '\$${formatMoney(_totalPrice)}',
                                  style: const TextStyle(color: AppColors.primary, fontSize: 18, fontWeight: FontWeight.bold),
                                ),
                              ],
                            ),
                            Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Row(
                                children: [
                                  const Text('رصيدك في المحفظة', style: TextStyle(color: AppColors.text2, fontSize: 11.5)),
                                  const Spacer(),
                                  Text('\$${formatMoney(_userBalance)}', style: const TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.w600)),
                                ],
                              ),
                            ),
                            if (!_hasEnoughBalance)
                              Container(
                                margin: const EdgeInsets.only(top: 10),
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                decoration: BoxDecoration(color: AppColors.red.withOpacity(.1), borderRadius: BorderRadius.circular(10), border: Border.all(color: AppColors.red.withOpacity(.25))),
                                child: Row(children: [
                                  const Expanded(child: Text('الرصيد غير كافٍ لإتمام الطلب', style: TextStyle(color: AppColors.red, fontSize: 11))),
                                  TextButton(
                                    onPressed: _goToTopup,
                                    style: TextButton.styleFrom(backgroundColor: AppColors.red, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6), minimumSize: Size.zero, tapTargetSize: MaterialTapTargetSize.shrinkWrap),
                                    child: const Text('شحن الآن', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                  ),
                                ]),
                              ),
                          ]),
                        ),

                        ...(_service!['fields'] as List<dynamic>).map((f) => Padding(
                              padding: const EdgeInsets.only(top: 12),
                              child: TextField(
                                controller: _fieldControllers[f['field_name']],
                                style: const TextStyle(color: AppColors.text),
                                decoration: InputDecoration(
                                  labelText: f['field_label'] + (f['is_required'] == 1 ? ' *' : ''),
                                  labelStyle: const TextStyle(color: AppColors.text2),
                                  filled: true,
                                  fillColor: AppColors.card2,
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    borderSide: BorderSide.none,
                                  ),
                                  focusedBorder: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    borderSide: const BorderSide(color: AppColors.primary),
                                  ),
                                ),
                              ),
                            )),

                        if (_hasCoupons) ...[
                          const SizedBox(height: 12),
                          TextField(
                            controller: _couponCtrl,
                            style: const TextStyle(color: AppColors.text),
                            decoration: InputDecoration(
                              labelText: 'كود الخصم (اختياري)',
                              labelStyle: const TextStyle(color: AppColors.text2),
                              filled: true,
                              fillColor: AppColors.card2,
                              border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(14),
                                borderSide: BorderSide.none,
                              ),
                            ),
                          ),
                        ],

                        if (_error != null) ...[
                          const SizedBox(height: 14),
                          Text(_error!, style: const TextStyle(color: AppColors.red), textAlign: TextAlign.center),
                        ],

                        const SizedBox(height: 20),
                        Container(
                          decoration: BoxDecoration(
                            gradient: _hasEnoughBalance ? AppColors.balanceGradient : null,
                            color: _hasEnoughBalance ? null : AppColors.card2,
                            borderRadius: BorderRadius.circular(14),
                            boxShadow: _hasEnoughBalance
                                ? [BoxShadow(color: AppColors.accentPurple.withOpacity(0.3), blurRadius: 14, offset: const Offset(0, 6))]
                                : null,
                          ),
                          child: Material(
                            color: Colors.transparent,
                            child: InkWell(
                              borderRadius: BorderRadius.circular(14),
                              onTap: (_placing || !_hasEnoughBalance) ? null : _placeOrder,
                              child: Padding(
                                padding: const EdgeInsets.symmetric(vertical: 16),
                                child: Center(
                                  child: _placing
                                      ? const SizedBox(
                                          height: 20, width: 20,
                                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                      : Text(_hasEnoughBalance ? 'تأكيد الطلب' : 'الرصيد غير كافٍ',
                                          style: TextStyle(fontSize: 16, color: _hasEnoughBalance ? Colors.white : AppColors.text3, fontWeight: FontWeight.w600)),
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
    );
  }
}
