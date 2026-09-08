import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class TopupUsdtScreen extends StatefulWidget {
  final Map<String, dynamic> settings;
  const TopupUsdtScreen({super.key, required this.settings});

  @override
  State<TopupUsdtScreen> createState() => _TopupUsdtScreenState();
}

class _TopupUsdtScreenState extends State<TopupUsdtScreen> {
  Map<String, dynamic>? _request;
  final _amountCtrl = TextEditingController();
  final _txCtrl = TextEditingController();
  bool _creating = false;
  bool _verifying = false;
  String? _error;
  Timer? _timer;
  Duration _remaining = Duration.zero;

  @override
  void initState() {
    super.initState();
    final active = widget.settings['active_request'];
    if (active is Map<String, dynamic>) {
      _request = active;
      _startTimer();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _amountCtrl.dispose();
    _txCtrl.dispose();
    super.dispose();
  }

  void _startTimer() {
    _timer?.cancel();
    _tick();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  void _tick() {
    final expiresAt = DateTime.tryParse(_request?['expires_at']?.toString() ?? '');
    if (expiresAt == null) return;
    final now = DateTime.now();
    final diff = expiresAt.difference(now);
    if (mounted) {
      setState(() => _remaining = diff.isNegative ? Duration.zero : diff);
    }
    if (diff.isNegative) {
      _timer?.cancel();
    }
  }

  Future<void> _createRequest() async {
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    final minDep = double.tryParse('${widget.settings['min_deposit'] ?? 1}') ?? 1;
    if (amount < minDep) {
      setState(() => _error = 'الحد الأدنى للإيداع ${minDep.toStringAsFixed(2)}\$');
      return;
    }
    setState(() {
      _creating = true;
      _error = null;
    });
    try {
      final req = await ApiService.createUsdtRequest(amount);
      setState(() => _request = req);
      _startTimer();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر إنشاء طلب الإيداع');
    } finally {
      if (mounted) setState(() => _creating = false);
    }
  }

  Future<void> _verify() async {
    final txId = _txCtrl.text.trim().toLowerCase();
    if (!RegExp(r'^0x[a-f0-9]{64}$').hasMatch(txId)) {
      setState(() => _error = 'txID غير صالح — يبدأ بـ 0x ويتكون من 66 حرفاً');
      return;
    }
    setState(() {
      _verifying = true;
      _error = null;
    });
    try {
      final amount = await ApiService.verifyUsdtTx(requestId: (_request!['id'] as num).toInt(), txId: txId);
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
          content: Text('تم إضافة \$$amount لرصيدك بنجاح', style: const TextStyle(color: AppColors.text2)),
          actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً', style: TextStyle(color: AppColors.primary)))],
        ),
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر التحقق من العملية');
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

  String _fmtDuration(Duration d) {
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: const Text('USDT — BEP20', style: TextStyle(color: AppColors.text)),
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
    final minDep = double.tryParse('${widget.settings['min_deposit'] ?? 1}') ?? 1;
    return [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(children: [
              Icon(Icons.account_balance_wallet_outlined, color: Color(0xFF26A17B)),
              SizedBox(width: 8),
              Text('إيداع USDT عبر شبكة BEP20', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold)),
            ]),
            const SizedBox(height: 8),
            Text('الحد الأدنى للإيداع: ${minDep.toStringAsFixed(2)}\$', style: const TextStyle(color: AppColors.text2, fontSize: 12)),
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
          onPressed: _creating ? null : _createRequest,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF26A17B),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _creating
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('احصل على عنوان الإيداع', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }

  List<Widget> _buildActiveRequest() {
    final expired = _remaining == Duration.zero;
    return [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('المبلغ المطلوب إرساله بالضبط', style: TextStyle(color: AppColors.text2, fontSize: 12)),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: expired ? AppColors.red.withOpacity(0.15) : AppColors.gold.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(expired ? 'انتهت الصلاحية' : _fmtDuration(_remaining),
                      style: TextStyle(color: expired ? AppColors.red : AppColors.gold, fontSize: 11, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: Text('${_request!['unique_amount']} USDT',
                      style: const TextStyle(color: AppColors.text, fontSize: 20, fontWeight: FontWeight.bold)),
                ),
                IconButton(
                  icon: const Icon(Icons.copy_rounded, color: AppColors.primary, size: 18),
                  onPressed: () => _copy('${_request!['unique_amount']}'),
                ),
              ],
            ),
            const Divider(color: AppColors.border, height: 24),
            const Text('عنوان المحفظة (BEP20)', style: TextStyle(color: AppColors.text2, fontSize: 12)),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: Text('${_request!['wallet_address']}',
                      style: const TextStyle(color: AppColors.text, fontSize: 12), maxLines: 2),
                ),
                IconButton(
                  icon: const Icon(Icons.copy_rounded, color: AppColors.primary, size: 18),
                  onPressed: () => _copy('${_request!['wallet_address']}'),
                ),
              ],
            ),
            const SizedBox(height: 6),
            const Text('⚠️ أرسل المبلغ بالضبط وبنفس الشبكة (BEP20) فقط', style: TextStyle(color: AppColors.gold, fontSize: 11)),
          ],
        ),
      ),
      const SizedBox(height: 20),
      const Text('بعد إتمام التحويل، ألصق رقم العملية (txID)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      TextField(
        controller: _txCtrl,
        style: const TextStyle(color: AppColors.text, fontSize: 12),
        decoration: InputDecoration(
          hintText: '0x...',
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
          onPressed: (_verifying || expired) ? null : _verify,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF26A17B),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _verifying
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('تحقق وشحن الرصيد', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }
}
