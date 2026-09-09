import 'dart:convert';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class OrderDetailScreen extends StatefulWidget {
  final int orderId;
  const OrderDetailScreen({super.key, required this.orderId});

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  Map<String, dynamic>? _order;
  List<dynamic> _log = [];
  Map<String, dynamic>? _objection;
  bool _loading = true;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.getOrderDetail(widget.orderId);
      if (!mounted) return;
      setState(() {
        _order = data['order'] as Map<String, dynamic>?;
        _log = (data['log'] as List<dynamic>?) ?? [];
        _objection = data['objection'] as Map<String, dynamic>?;
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر تحميل تفاصيل الطلب');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Color _statusColor(String? status) {
    switch (status) {
      case 'completed':
        return AppColors.green;
      case 'pending':
      case 'processing':
        return AppColors.gold;
      case 'rejected':
      case 'cancelled':
      case 'failed':
        return AppColors.red;
      default:
        return AppColors.text2;
    }
  }

  String _statusLabel(String? status) {
    switch (status) {
      case 'completed': return 'مكتمل';
      case 'pending': return 'قيد الانتظار';
      case 'processing': return 'قيد التنفيذ';
      case 'rejected': return 'مرفوض';
      case 'cancelled': return 'ملغى';
      case 'failed': return 'فشل';
      default: return status ?? 'غير معروف';
    }
  }

  Future<void> _showObjectionForm() async {
    final controller = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('تقديم اعتراض'),
        content: TextField(controller: controller, maxLines: 4, maxLength: 500, decoration: const InputDecoration(hintText: 'اكتب سبب الاعتراض بالتفصيل')), 
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('إرسال')),
        ],
      ),
    );
    controller.dispose();
    if (reason == null || reason.length < 5) return;
    setState(() => _submitting = true);
    try {
      await ApiService.submitOrderObjection(orderId: widget.orderId, reason: reason);
      if (mounted) {
        _showMessage('تم تقديم الاعتراض بنجاح');
        _load();
      }
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _showMessage(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تفاصيل الطلب')),
      body: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.primary,
        backgroundColor: AppColors.card,
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : _error != null
                ? ListView(children: [const SizedBox(height: 130), Center(child: Text(_error!, style: const TextStyle(color: AppColors.red)))])
                : ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 28), children: [_summaryCard(), _detailsCard(), if (_log.isNotEmpty) _timelineCard(), if (_canObject) _objectionButton(), if (_submitting) const LinearProgressIndicator(color: AppColors.primary)]),
      ),
    );
  }

  bool get _canObject => _order?['status'] == 'completed' && _objection == null;

  Widget _summaryCard() {
    final order = _order ?? {};
    final color = _statusColor(order['status']?.toString());
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(gradient: AppColors.balanceGradient, borderRadius: BorderRadius.circular(20), boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(.25), blurRadius: 16, offset: const Offset(0, 8))]),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(order['service_name']?.toString() ?? 'طلب', style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
        const SizedBox(height: 12),
        Row(children: [Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5), decoration: BoxDecoration(color: color.withOpacity(.2), borderRadius: BorderRadius.circular(20)), child: Text(_statusLabel(order['status']?.toString()), style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.bold))), const Spacer(), Text('\$${order['total_price'] ?? '0'}', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.bold))]),
      ]),
    );
  }

  Widget _detailsCard() {
    final order = _order ?? {};
    Map<String, dynamic> fields = {};
    final rawFields = order['field_data'];
    if (rawFields is Map) fields = Map<String, dynamic>.from(rawFields);
    if (rawFields is String && rawFields.isNotEmpty) {
      try { fields = Map<String, dynamic>.from(jsonDecode(rawFields) as Map); } catch (_) {}
    }
    final rows = <MapEntry<String, String>>[
      MapEntry('رقم الطلب', order['ref_id']?.toString() ?? '#${widget.orderId}'),
      MapEntry('الكمية', order['quantity']?.toString() ?? '1'),
      MapEntry('تاريخ الطلب', order['created_at']?.toString() ?? ''),
      if (order['provider_order_id'] != null) MapEntry('رقم المزود', order['provider_order_id'].toString()),
      ...fields.entries.map((entry) => MapEntry(entry.key, entry.value.toString())),
    ];
    return _panel('بيانات الطلب', Column(children: rows.map((row) => _infoRow(row.key, row.value)).toList()));
  }

  Widget _timelineCard() {
    return _panel('سجل الحالة', Column(children: _log.map((item) {
      final status = item['status']?.toString();
      final color = _statusColor(status);
      return ListTile(contentPadding: EdgeInsets.zero, leading: Container(width: 12, height: 12, decoration: BoxDecoration(color: color, shape: BoxShape.circle)), title: Text(_statusLabel(status), style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)), subtitle: Text(item['created_at']?.toString() ?? '', style: const TextStyle(color: AppColors.text3, fontSize: 11)), trailing: Text(item['message']?.toString() ?? '', style: const TextStyle(color: AppColors.text2, fontSize: 11)));
    }).toList()));
  }

  Widget _objectionButton() => Padding(padding: const EdgeInsets.only(top: 14), child: OutlinedButton.icon(onPressed: _showObjectionForm, icon: const Icon(Icons.flag_outlined), label: const Text('تقديم اعتراض على الطلب'), style: OutlinedButton.styleFrom(foregroundColor: AppColors.gold, side: BorderSide(color: AppColors.gold.withOpacity(.45)), padding: const EdgeInsets.symmetric(vertical: 14))));

  Widget _panel(String title, Widget child) => Container(margin: const EdgeInsets.only(top: 14), padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(17), border: Border.all(color: AppColors.border)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: const TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)), const SizedBox(height: 8), child]));

  Widget _infoRow(String label, String value) => Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: Text(label, style: const TextStyle(color: AppColors.text2, fontSize: 12))), const SizedBox(width: 12), Flexible(child: Text(value, textAlign: TextAlign.end, style: const TextStyle(color: AppColors.text, fontSize: 12, fontWeight: FontWeight.w600)))]));
}
