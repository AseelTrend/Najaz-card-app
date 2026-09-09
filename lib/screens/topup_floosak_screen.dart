import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class TopupFloosakScreen extends StatefulWidget {
  final String? imageUrl;
  const TopupFloosakScreen({super.key, this.imageUrl});

  @override
  State<TopupFloosakScreen> createState() => _TopupFloosakScreenState();
}

enum _FloosakPhase { form, otp, done }

class _TopupFloosakScreenState extends State<TopupFloosakScreen> {
  _FloosakPhase _phase = _FloosakPhase.form;
  final _amountCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _otpCtrl = TextEditingController();
  bool _loading = false;
  String? _error;
  int? _purchaseId;
  String? _resultMsg;

  @override
  void dispose() {
    _amountCtrl.dispose();
    _phoneCtrl.dispose();
    _otpCtrl.dispose();
    super.dispose();
  }

  Future<void> _initiate() async {
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    var phone = _phoneCtrl.text.replaceAll(RegExp(r'[^0-9]'), '');
    if (phone.startsWith('00967')) {
      phone = phone.substring(2);
    } else if (phone.startsWith('7') && phone.length == 9) {
      phone = '967$phone';
    }
    _phoneCtrl.text = phone;
    if (amount < 100) {
      setState(() => _error = 'الحد الأدنى للشحن 100 ريال');
      return;
    }
    if (!phone.startsWith('967') || phone.length != 12) {
      setState(() => _error = 'أدخل رقم هاتفك في فلوسك بشكل صحيح');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.floosakInitiate(amount: amount, phone: phone);
      setState(() {
        _purchaseId = (data['purchase_id'] as num).toInt();
        _phase = _FloosakPhase.otp;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر إرسال طلب الدفع');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _confirm() async {
    final otp = _otpCtrl.text.trim();
    if (otp.length != 6) {
      setState(() => _error = 'رمز التحقق يجب أن يكون 6 أرقام');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.floosakConfirm(purchaseId: _purchaseId!, otp: otp);
      setState(() {
        _resultMsg = data['message']?.toString() ?? 'تمت العملية بنجاح!';
        _phase = _FloosakPhase.done;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر تأكيد الدفع');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: const Text('محفظة فلوسك', style: TextStyle(color: AppColors.text)),
        iconTheme: const IconThemeData(color: AppColors.text),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (widget.imageUrl != null) ...[
              Center(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(14),
                  child: CachedNetworkImage(imageUrl: widget.imageUrl!, height: 70, errorWidget: (_, __, ___) => const SizedBox.shrink()),
                ),
              ),
              const SizedBox(height: 16),
            ],
            if (_phase == _FloosakPhase.form) ..._buildForm(),
            if (_phase == _FloosakPhase.otp) ..._buildOtp(),
            if (_phase == _FloosakPhase.done) ..._buildDone(),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 13), textAlign: TextAlign.center),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _buildForm() {
    return [
      const Text('المبلغ (ريال يمني)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      TextField(
        controller: _amountCtrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        style: const TextStyle(color: AppColors.text),
        decoration: InputDecoration(
          hintText: 'الحد الأدنى 100',
          hintStyle: const TextStyle(color: AppColors.text3),
          filled: true,
          fillColor: AppColors.card2,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
        ),
      ),
      const SizedBox(height: 16),
      const Text('رقم هاتفك المسجل في فلوسك', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      TextField(
        controller: _phoneCtrl,
        keyboardType: TextInputType.phone,
        style: const TextStyle(color: AppColors.text),
        decoration: InputDecoration(
          hintText: '7XXXXXXXX',
          hintStyle: const TextStyle(color: AppColors.text3),
          filled: true,
          fillColor: AppColors.card2,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
        ),
      ),
      const SizedBox(height: 20),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _loading ? null : _initiate,
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.cyan,
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _loading
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('إرسال رمز التحقق', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }

  List<Widget> _buildOtp() {
    return [
      const Icon(Icons.sms_outlined, color: AppColors.cyan, size: 44),
      const SizedBox(height: 10),
      const Text('تم إرسال رمز تحقق مكون من 6 أرقام إلى هاتفك المسجل في فلوسك',
          style: TextStyle(color: AppColors.text2, fontSize: 13), textAlign: TextAlign.center),
      const SizedBox(height: 20),
      TextField(
        controller: _otpCtrl,
        keyboardType: TextInputType.number,
        maxLength: 6,
        textAlign: TextAlign.center,
        style: const TextStyle(color: AppColors.text, fontSize: 22, letterSpacing: 8, fontWeight: FontWeight.bold),
        decoration: InputDecoration(
          counterText: '',
          hintText: '••••••',
          filled: true,
          fillColor: AppColors.card2,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
        ),
      ),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _loading ? null : _confirm,
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.cyan,
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _loading
              ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('تأكيد الدفع', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
      const SizedBox(height: 10),
      Center(
        child: TextButton(
          onPressed: _loading ? null : () => setState(() => _phase = _FloosakPhase.form),
          child: const Text('رجوع', style: TextStyle(color: AppColors.text2)),
        ),
      ),
    ];
  }

  List<Widget> _buildDone() {
    return [
      const SizedBox(height: 20),
      const Icon(Icons.check_circle_rounded, color: AppColors.green, size: 60),
      const SizedBox(height: 16),
      Text(_resultMsg ?? '', style: const TextStyle(color: AppColors.text, fontSize: 15), textAlign: TextAlign.center),
      const SizedBox(height: 24),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: () => Navigator.of(context).pop(true),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.green,
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: const Text('تم', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ),
      ),
    ];
  }
}
