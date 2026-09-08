import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

/// نفس رسائل الخطأ المستخدمة بالضبط في app-script-2.php (binanceErrors)
const Map<String, String> _binanceErrors = {
  'feature_not_ready': 'خدمة Binance غير جاهزة حالياً.',
  'invalid_amount': 'أدخل مبلغاً صحيحاً يساوي الحد الأدنى أو أكثر.',
  'request_not_found': 'طلب الإيداع غير موجود.',
  'too_many_pending': 'لديك طلبات Binance مفتوحة بالفعل. أكمل أو انتظر انتهاء أحدها قبل إنشاء طلب جديد.',
  'request_expired': 'انتهت صلاحية طلب الإيداع. أنشئ طلباً جديداً.',
  'attempt_limit': 'تم بلوغ عدد محاولات التحقق المسموح.',
  'verification_in_progress': 'توجد محاولة تحقق قيد التنفيذ لهذا الطلب. انتظر قليلاً.',
  'transaction_locked': 'هذا الطلب مرتبط بمعرّف عملية مختلف.',
  'invalid_transaction_id': 'أدخل معرّف العملية كما يظهر في Binance Pay.',
  'transaction_not_found': 'لم تظهر العملية في سجل Binance Pay ضمن النافذة الحالية.',
  'transaction_unsuccessful': 'العملية موجودة لكنها غير ناجحة.',
  'wrong_order_type': 'نوع العملية غير مقبول للإيداع. المقبول هو C2C الوارد فقط.',
  'wrong_currency': 'عملة العملية ليست USDT.',
  'amount_mismatch': 'المبلغ المستلم لا يطابق مبلغ طلب الإيداع.',
  'time_outside_window': 'وقت العملية خارج مدة طلب الإيداع.',
  'receiver_mismatch': 'حساب المستلم لا يطابق حساب الموقع.',
  'api_error': 'تعذر الوصول إلى Binance حالياً. حاول لاحقاً.',
  'transport_error': 'تعذر الاتصال بخدمة التحقق حالياً.',
  'credentials_unavailable': 'خدمة Binance غير مهيأة من الإدارة.',
  'credit_failed': 'تم العثور على العملية لكن تعذر قيد الرصيد. تواصل مع الإدارة.',
  'csrf_failed': 'انتهت جلسة الأمان. أعد المحاولة.',
  'login_required': 'يجب تسجيل الدخول أولاً.',
};

String _bmsg(String? code) => _binanceErrors[code] ?? 'تعذر إكمال التحقق حالياً.';

class TopupBinanceScreen extends StatefulWidget {
  final Map<String, dynamic> settings;
  const TopupBinanceScreen({super.key, required this.settings});

  @override
  State<TopupBinanceScreen> createState() => _TopupBinanceScreenState();
}

class _TopupBinanceScreenState extends State<TopupBinanceScreen> {
  Map<String, dynamic>? _request;
  Map<String, dynamic>? _instructions;
  final _amountCtrl = TextEditingController();
  final _txCtrl = TextEditingController();
  bool _creating = false;
  bool _verifying = false;
  String? _error;

  @override
  void dispose() {
    _amountCtrl.dispose();
    _txCtrl.dispose();
    super.dispose();
  }

  Future<void> _create() async {
    final amount = _amountCtrl.text.trim();
    final minimum = double.tryParse('${widget.settings['minimum_amount'] ?? 1}') ?? 1;
    if ((double.tryParse(amount) ?? 0) < minimum) {
      setState(() => _error = _bmsg('invalid_amount'));
      return;
    }
    setState(() {
      _creating = true;
      _error = null;
    });
    try {
      final data = await ApiService.binanceCreate(amount);
      if (data['ok'] == true) {
        setState(() {
          _request = data['request'] as Map<String, dynamic>?;
          _instructions = data['instructions'] as Map<String, dynamic>?;
        });
      } else {
        setState(() => _error = _bmsg(data['error_code']?.toString()));
      }
    } on ApiException catch (e) {
      setState(() => _error = e.data.containsKey('error_code') ? _bmsg(e.data['error_code']?.toString()) : e.message);
    } catch (e) {
      setState(() => _error = 'تعذر الاتصال بالسيرفر');
    } finally {
      if (mounted) setState(() => _creating = false);
    }
  }

  Future<void> _verify() async {
    final txId = _txCtrl.text.trim();
    if (txId.isEmpty) {
      setState(() => _error = _bmsg('invalid_transaction_id'));
      return;
    }
    setState(() {
      _verifying = true;
      _error = null;
    });
    try {
      final data = await ApiService.binanceVerify(requestId: (_request!['id'] as num).toInt(), transactionId: txId);
      if (data['ok'] == true && data['credited'] == true) {
        if (!mounted) return;
        await showDialog(
          context: context,
          builder: (_) => AlertDialog(
            backgroundColor: AppColors.card,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(children: [
              Icon(Icons.check_circle_rounded, color: AppColors.green),
              SizedBox(width: 8),
              Text('تم الشحن', style: TextStyle(color: AppColors.text)),
            ]),
            content: const Text('تم التحقق من العملية وإضافة الرصيد بنجاح', style: TextStyle(color: AppColors.text2)),
            actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً', style: TextStyle(color: AppColors.primary)))],
          ),
        );
        if (mounted) Navigator.of(context).pop(true);
      } else {
        setState(() => _error = _bmsg(data['error_code']?.toString()));
      }
    } on ApiException catch (e) {
      setState(() => _error = e.data.containsKey('error_code') ? _bmsg(e.data['error_code']?.toString()) : e.message);
    } catch (e) {
      setState(() => _error = 'تعذر الاتصال بالسيرفر');
    } finally {
      if (mounted) setState(() => _verifying = false);
    }
  }

  void _copy(String value) {
    Clipboard.setData(ClipboardData(text: value));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('تم النسخ'), duration: Duration(seconds: 1), backgroundColor: AppColors.card2),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: const Text('مباشر Binance', style: TextStyle(color: AppColors.text)),
        iconTheme: const IconThemeData(color: AppColors.text),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_request == null) ..._buildCreateForm() else ..._buildActiveRequest(),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 13), textAlign: TextAlign.center),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _buildCreateForm() {
    final minimum = double.tryParse('${widget.settings['minimum_amount'] ?? 1}') ?? 1;
    return [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(children: [
              Icon(Icons.currency_bitcoin_rounded, color: Color(0xFFF6C11A)),
              SizedBox(width: 8),
              Text('إيداع USDT عبر Binance Pay', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold)),
            ]),
            const SizedBox(height: 8),
            Text('الحد الأدنى: ${minimum.toStringAsFixed(2)}\$', style: const TextStyle(color: AppColors.text2, fontSize: 12)),
          ],
        ),
      ),
      const SizedBox(height: 16),
      const Text('المبلغ (USDT)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      TextField(
        controller: _amountCtrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        style: const TextStyle(color: AppColors.text),
        decoration: InputDecoration(
          hintText: '0.00',
          filled: true,
          fillColor: AppColors.card2,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
        ),
      ),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _creating ? null : _create,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFFF6C11A),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _creating
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black))
              : const Text('إنشاء طلب الإيداع', style: TextStyle(color: Colors.black, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }

  List<Widget> _buildActiveRequest() {
    final receiver = _instructions?['receiver_identifier']?.toString() ?? '';
    final receiverType = _instructions?['receiver_identifier_type']?.toString() ?? '';
    final amount = _instructions?['amount']?.toString() ?? _request?['expected_amount']?.toString() ?? '';
    return [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('أرسل عبر Binance Pay إلى', style: TextStyle(color: AppColors.text2, fontSize: 12)),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: Text(receiver.isEmpty ? '—' : '$receiver${receiverType.isNotEmpty ? ' ($receiverType)' : ''}',
                      style: const TextStyle(color: AppColors.text, fontSize: 14, fontWeight: FontWeight.bold)),
                ),
                if (receiver.isNotEmpty)
                  IconButton(icon: const Icon(Icons.copy_rounded, color: AppColors.primary, size: 18), onPressed: () => _copy(receiver)),
              ],
            ),
            const Divider(color: AppColors.border, height: 24),
            const Text('المبلغ المطلوب بالضبط', style: TextStyle(color: AppColors.text2, fontSize: 12)),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(child: Text('$amount USDT', style: const TextStyle(color: AppColors.text, fontSize: 20, fontWeight: FontWeight.bold))),
                IconButton(icon: const Icon(Icons.copy_rounded, color: AppColors.primary, size: 18), onPressed: () => _copy(amount)),
              ],
            ),
            const SizedBox(height: 6),
            const Text('⚠️ استخدم Binance Pay فقط (C2C) وبنفس المبلغ بالضبط', style: TextStyle(color: AppColors.gold, fontSize: 11)),
          ],
        ),
      ),
      const SizedBox(height: 20),
      const Text('بعد إتمام التحويل، أدخل معرّف العملية (Transaction ID)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      TextField(
        controller: _txCtrl,
        style: const TextStyle(color: AppColors.text, fontSize: 12),
        decoration: InputDecoration(
          hintText: 'Transaction ID',
          hintStyle: const TextStyle(color: AppColors.text3),
          filled: true,
          fillColor: AppColors.card2,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
        ),
      ),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _verifying ? null : _verify,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFFF6C11A),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _verifying
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black))
              : const Text('تحقق وشحن الرصيد', style: TextStyle(color: Colors.black, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }
}
