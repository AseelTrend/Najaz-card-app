import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:file_picker/file_picker.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class TopupManualDetailScreen extends StatefulWidget {
  final Map<String, dynamic> method;
  final List<dynamic> exchangeRates;
  const TopupManualDetailScreen({super.key, required this.method, required this.exchangeRates});

  @override
  State<TopupManualDetailScreen> createState() => _TopupManualDetailScreenState();
}

class _TopupManualDetailScreenState extends State<TopupManualDetailScreen> {
  late List<dynamic> _allowedRates;
  Map<String, dynamic>? _selectedRate;
  final _amountCtrl = TextEditingController();
  final _notesCtrl = TextEditingController();
  File? _receipt;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final allowedCodes = ((widget.method['allowed_currency_codes'] as List<dynamic>?) ?? [])
        .map((c) => c.toString().toUpperCase())
        .toList();
    _allowedRates = allowedCodes.isEmpty
        ? widget.exchangeRates
        : widget.exchangeRates.where((r) => allowedCodes.contains(r['currency_code'].toString().toUpperCase())).toList();
    if (_allowedRates.isNotEmpty) _selectedRate = _allowedRates.first as Map<String, dynamic>;
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _notesCtrl.dispose();
    super.dispose();
  }

  double get _amountUsd {
    final amt = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    final rate = double.tryParse('${_selectedRate?['rate_to_usd'] ?? 1}') ?? 1;
    return amt * rate;
  }

  Future<void> _pickReceipt() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'],
    );
    if (result != null && result.files.single.path != null) {
      setState(() => _receipt = File(result.files.single.path!));
    }
  }

  Future<void> _submit() async {
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (_selectedRate == null) {
      setState(() => _error = 'اختر العملة أولاً');
      return;
    }
    if (amount <= 0) {
      setState(() => _error = 'أدخل مبلغاً صحيحاً');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.submitManualTopup(
        methodId: (widget.method['id'] as num).toInt(),
        currencyCode: _selectedRate!['currency_code'].toString(),
        amountSent: amount,
        notes: _notesCtrl.text.trim(),
        receiptFile: _receipt,
      );
      if (!mounted) return;
      await showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: AppColors.card,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(children: [
            Icon(Icons.check_circle_rounded, color: AppColors.green),
            SizedBox(width: 8),
            Text('تم الإرسال', style: TextStyle(color: AppColors.text)),
          ]),
          content: Text(data['msg']?.toString() ?? 'تم إرسال طلب الشحن، سيتم مراجعته قريباً',
              style: const TextStyle(color: AppColors.text2)),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً', style: TextStyle(color: AppColors.primary))),
          ],
        ),
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر إرسال الطلب، حاول مرة أخرى');
    } finally {
      if (mounted) setState(() => _loading = false);
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
    final fields = (widget.method['fields'] as List<dynamic>?) ?? [];
    final symbol = _selectedRate?['currency_symbol']?.toString() ?? '';

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: Text(widget.method['name']?.toString() ?? 'تحويل يدوي', style: const TextStyle(color: AppColors.text)),
        iconTheme: const IconThemeData(color: AppColors.text),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (fields.isNotEmpty) ...[
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(14), border: Border.all(color: AppColors.border)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('بيانات التحويل', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold, fontSize: 13)),
                    const SizedBox(height: 10),
                    ...fields.map((f) => Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(f['label']?.toString() ?? '', style: const TextStyle(color: AppColors.text2, fontSize: 11)),
                                    Text(f['value']?.toString() ?? '', style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600)),
                                  ],
                                ),
                              ),
                              if (f['copyable'] == true)
                                IconButton(
                                  icon: const Icon(Icons.copy_rounded, color: AppColors.primary, size: 18),
                                  onPressed: () => _copy(f['value']?.toString() ?? ''),
                                ),
                            ],
                          ),
                        )),
                  ],
                ),
              ),
              const SizedBox(height: 16),
            ],
            const Text('العملة', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              children: _allowedRates.map((r) {
                final selected = _selectedRate?['currency_code'] == r['currency_code'];
                return ChoiceChip(
                  label: Text('${r['currency_code']}', style: TextStyle(color: selected ? Colors.white : AppColors.text2, fontSize: 12)),
                  selected: selected,
                  onSelected: (_) => setState(() => _selectedRate = r as Map<String, dynamic>),
                  selectedColor: AppColors.primary,
                  backgroundColor: AppColors.card2,
                  side: BorderSide.none,
                );
              }).toList(),
            ),
            const SizedBox(height: 16),
            const Text('المبلغ المُرسَل', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            TextField(
              controller: _amountCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              onChanged: (_) => setState(() {}),
              style: const TextStyle(color: AppColors.text),
              decoration: InputDecoration(
                hintText: '0.00',
                suffixText: symbol,
                suffixStyle: const TextStyle(color: AppColors.text2),
                filled: true,
                fillColor: AppColors.card2,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
              ),
            ),
            if (_amountCtrl.text.trim().isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('سيُضاف لرصيدك: \$${_amountUsd.toStringAsFixed(4)}', style: const TextStyle(color: AppColors.green, fontSize: 12)),
            ],
            const SizedBox(height: 16),
            const Text('إيصال التحويل (اختياري)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            InkWell(
              onTap: _pickReceipt,
              borderRadius: BorderRadius.circular(14),
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.card2,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.border, style: BorderStyle.solid),
                ),
                child: Column(
                  children: [
                    Icon(_receipt != null ? Icons.check_circle_rounded : Icons.upload_file_rounded,
                        color: _receipt != null ? AppColors.green : AppColors.text2),
                    const SizedBox(height: 6),
                    Text(
                      _receipt != null ? _receipt!.path.split('/').last : 'اضغط لإرفاق صورة أو PDF للإيصال',
                      style: const TextStyle(color: AppColors.text2, fontSize: 12),
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            const Text('ملاحظات (اختياري)', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            TextField(
              controller: _notesCtrl,
              maxLines: 2,
              style: const TextStyle(color: AppColors.text),
              decoration: InputDecoration(
                hintText: 'رقم الحوالة أو أي تفاصيل إضافية',
                hintStyle: const TextStyle(color: AppColors.text3),
                filled: true,
                fillColor: AppColors.card2,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 13), textAlign: TextAlign.center),
            ],
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _loading ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
                child: _loading
                    ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Text('إرسال طلب الشحن', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              ),
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}
