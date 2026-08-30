import 'package:flutter/material.dart';
import '../services/api_service.dart';

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
  int _quantity = 1;
  bool _loading = true;
  bool _placing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final service = await ApiService.getServiceDetail(widget.serviceId);
      for (final f in (service['fields'] as List<dynamic>)) {
        _fieldControllers[f['field_name']] = TextEditingController();
      }
      setState(() {
        _service = service;
        _quantity = service['min_qty'] ?? 1;
      });
    } catch (e) {
      setState(() => _error = 'تعذر تحميل الخدمة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _placeOrder() async {
    // تحقق من الحقول المطلوبة قبل الإرسال
    for (final f in (_service!['fields'] as List<dynamic>)) {
      if (f['is_required'] == 1 && (_fieldControllers[f['field_name']]?.text.trim().isEmpty ?? true)) {
        setState(() => _error = 'الحقل "${f['field_label']}" مطلوب');
        return;
      }
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
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF0E1525),
        title: Row(
          children: const [
            Icon(Icons.check_circle, color: Color(0xFF34D399)),
            SizedBox(width: 8),
            Text('تم الطلب بنجاح', style: TextStyle(color: Colors.white, fontSize: 16)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (result['order_ref'] != null)
              Text('رقم الطلب: ${result['order_ref']}', style: const TextStyle(color: Color(0xFF7C93B5))),
            if (result['new_balance'] != null)
              Text('رصيدك الجديد: \$${result['new_balance']}', style: const TextStyle(color: Color(0xFF7C93B5))),
            if (result['delivered_code'] != null && result['delivered_code'].toString().isNotEmpty) ...[
              const SizedBox(height: 8),
              SelectableText('الكود: ${result['delivered_code']}',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.of(context).pop(); // إغلاق الحوار
              Navigator.of(context).pop(); // رجوع للرئيسية
            },
            child: const Text('حسناً', style: TextStyle(color: Color(0xFF3B82F6))),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF090D1A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF090D1A),
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
        title: Text(_service?['name'] ?? '', style: const TextStyle(color: Colors.white, fontSize: 16)),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF3B82F6)))
          : _service == null
              ? Center(child: Text(_error ?? 'خطأ', style: const TextStyle(color: Colors.white)))
              : SafeArea(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_service!['image'] != null && _service!['image'].toString().isNotEmpty)
                          ClipRRect(
                            borderRadius: BorderRadius.circular(14),
                            child: Image.network('https://njaz.net/${_service!['image']}',
                                height: 160, fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => const SizedBox()),
                          ),
                        const SizedBox(height: 16),
                        Text(_service!['name'] ?? '',
                            style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                        const SizedBox(height: 6),
                        Text('\$${_service!['price']}',
                            style: const TextStyle(color: Color(0xFF3B82F6), fontSize: 18, fontWeight: FontWeight.bold)),
                        if ((_service!['description'] ?? '').toString().isNotEmpty) ...[
                          const SizedBox(height: 10),
                          Text(_service!['description'], style: const TextStyle(color: Color(0xFF7C93B5))),
                        ],
                        const SizedBox(height: 20),

                        // الكمية
                        Row(
                          children: [
                            const Text('الكمية', style: TextStyle(color: Colors.white)),
                            const Spacer(),
                            IconButton(
                              onPressed: _quantity > (_service!['min_qty'] ?? 1)
                                  ? () => setState(() => _quantity--)
                                  : null,
                              icon: const Icon(Icons.remove_circle_outline, color: Color(0xFF7C93B5)),
                            ),
                            Text('$_quantity', style: const TextStyle(color: Colors.white, fontSize: 16)),
                            IconButton(
                              onPressed: _quantity < (_service!['max_qty'] ?? 9999)
                                  ? () => setState(() => _quantity++)
                                  : null,
                              icon: const Icon(Icons.add_circle_outline, color: Color(0xFF7C93B5)),
                            ),
                          ],
                        ),

                        // الحقول المطلوبة
                        ...(_service!['fields'] as List<dynamic>).map((f) => Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: TextField(
                                controller: _fieldControllers[f['field_name']],
                                style: const TextStyle(color: Colors.white),
                                decoration: InputDecoration(
                                  labelText: f['field_label'] + (f['is_required'] == 1 ? ' *' : ''),
                                  labelStyle: const TextStyle(color: Color(0xFF7C93B5)),
                                  filled: true,
                                  fillColor: const Color(0xFF151F35),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                    borderSide: BorderSide.none,
                                  ),
                                ),
                              ),
                            )),

                        const SizedBox(height: 10),
                        TextField(
                          controller: _couponCtrl,
                          style: const TextStyle(color: Colors.white),
                          decoration: InputDecoration(
                            labelText: 'كود الخصم (اختياري)',
                            labelStyle: const TextStyle(color: Color(0xFF7C93B5)),
                            filled: true,
                            fillColor: const Color(0xFF151F35),
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(12),
                              borderSide: BorderSide.none,
                            ),
                          ),
                        ),

                        if (_error != null) ...[
                          const SizedBox(height: 14),
                          Text(_error!, style: const TextStyle(color: Color(0xFFF87171)), textAlign: TextAlign.center),
                        ],

                        const SizedBox(height: 20),
                        ElevatedButton(
                          onPressed: _placing ? null : _placeOrder,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF3B82F6),
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          child: _placing
                              ? const SizedBox(
                                  height: 20, width: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Text('تأكيد الطلب', style: TextStyle(fontSize: 16, color: Colors.white)),
                        ),
                      ],
                    ),
                  ),
                ),
    );
  }
}
