import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class TopupSmsInlineTab extends StatefulWidget {
  final Map<String, dynamic> providers; // {north:[], south:[], no_region:[]}
  final void Function(VoidCallback action) onRequireKyc;
  final VoidCallback onSuccess;
  const TopupSmsInlineTab({super.key, required this.providers, required this.onRequireKyc, required this.onSuccess});

  @override
  State<TopupSmsInlineTab> createState() => _TopupSmsInlineTabState();
}

class _TopupSmsInlineTabState extends State<TopupSmsInlineTab> {
  late List<String> _regions;
  String _region = '';
  Map<String, dynamic>? _selectedProvider;
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  bool _loading = false;
  String? _error;
  String? _successMsg;

  @override
  void initState() {
    super.initState();
    _regions = [
      if (((widget.providers['north'] as List?) ?? []).isNotEmpty) 'north',
      if (((widget.providers['south'] as List?) ?? []).isNotEmpty) 'south',
      if (((widget.providers['no_region'] as List?) ?? []).isNotEmpty) 'no_region',
    ];
    if (_regions.isNotEmpty) {
      _region = _regions.first;
      final list = widget.providers[_region] as List<dynamic>;
      if (list.isNotEmpty) _selectedProvider = list.first as Map<String, dynamic>;
    }
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    super.dispose();
  }

  String _regionLabel(String r) => r == 'north' ? 'شمال' : (r == 'south' ? 'جنوب' : 'أخرى');

  Future<void> _submit() async {
    final phone = _phoneCtrl.text.replaceAll(RegExp(r'[^0-9]'), '');
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (phone.length < 8) {
      setState(() => _error = 'رقم الهاتف غير صحيح');
      return;
    }
    if (amount <= 0) {
      setState(() => _error = 'المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (_selectedProvider == null) {
      setState(() => _error = 'اختر جهة التحويل');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
      _successMsg = null;
    });
    try {
      final data = await ApiService.smsVerifyTopup(
        phone: phone,
        amount: amount,
        providerId: (_selectedProvider!['id'] as num).toInt(),
      );
      final inner = data['data'] as Map<String, dynamic>?;
      setState(() => _successMsg = data['message']?.toString() ?? 'تم شحن رصيدك بنجاح!');
      if (inner?['credited'] == true) {
        widget.onSuccess();
      }
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'تعذر الاتصال بالسيرفر');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_regions.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('لا توجد جهات تحويل متاحة حالياً', style: TextStyle(color: AppColors.text2)),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_regions.length > 1) ...[
          Row(
            children: _regions
                .map((r) => Padding(
                      padding: const EdgeInsets.only(left: 8),
                      child: ChoiceChip(
                        label: Text(_regionLabel(r), style: TextStyle(color: _region == r ? Colors.white : AppColors.text2, fontSize: 12)),
                        selected: _region == r,
                        onSelected: (_) {
                          setState(() {
                            _region = r;
                            final list = widget.providers[r] as List<dynamic>;
                            _selectedProvider = list.isNotEmpty ? list.first as Map<String, dynamic> : null;
                          });
                        },
                        selectedColor: AppColors.primary,
                        backgroundColor: AppColors.card2,
                        side: BorderSide.none,
                      ),
                    ))
                .toList(),
          ),
          const SizedBox(height: 14),
        ],
        const Text('جهة التحويل', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: ((widget.providers[_region] as List<dynamic>?) ?? []).map((p) {
            final selected = _selectedProvider != null && _selectedProvider!['id'] == p['id'];
            return ChoiceChip(
              label: Text(p['name']?.toString() ?? '', style: TextStyle(color: selected ? Colors.white : AppColors.text2, fontSize: 12)),
              selected: selected,
              onSelected: (_) => setState(() => _selectedProvider = p as Map<String, dynamic>),
              selectedColor: AppColors.primary,
              backgroundColor: AppColors.card2,
              side: BorderSide.none,
            );
          }).toList(),
        ),
        if (_selectedProvider != null && (_selectedProvider!['transfer_account']?.toString().isNotEmpty ?? false)) ...[
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(14), border: Border.all(color: AppColors.border)),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('حوّل المبلغ إلى', style: TextStyle(color: AppColors.text2, fontSize: 11)),
                const SizedBox(height: 4),
                Text('${_selectedProvider!['transfer_account']}', style: const TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)),
                if ((_selectedProvider!['account_holder']?.toString().isNotEmpty ?? false))
                  Text('${_selectedProvider!['account_holder']}', style: const TextStyle(color: AppColors.text2, fontSize: 12)),
              ],
            ),
          ),
        ],
        const SizedBox(height: 16),
        const Text('رقم الهاتف الذي أرسلت منه', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
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
        const SizedBox(height: 16),
        const Text('المبلغ المُرسَل', style: TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        TextField(
          controller: _amountCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          style: const TextStyle(color: AppColors.text),
          decoration: InputDecoration(
            hintText: '0',
            filled: true,
            fillColor: AppColors.card2,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 13), textAlign: TextAlign.center),
        ],
        if (_successMsg != null) ...[
          const SizedBox(height: 12),
          Text(_successMsg!, style: const TextStyle(color: AppColors.green, fontSize: 13), textAlign: TextAlign.center),
        ],
        const SizedBox(height: 18),
        SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: _loading ? null : () => widget.onRequireKyc(_submit),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.primary,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            ),
            child: _loading
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('تحقق وشحن الرصيد', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }
}
