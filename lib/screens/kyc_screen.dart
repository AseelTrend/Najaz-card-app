import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../services/kyc_api_service.dart';
import '../theme/app_colors.dart';

class KycScreen extends StatefulWidget {
  const KycScreen({super.key});
  @override
  State<KycScreen> createState() => _KycScreenState();
}

class _KycScreenState extends State<KycScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _id = TextEditingController();
  final _birthPlace = TextEditingController();
  final _issue = TextEditingController();
  final _expiry = TextEditingController();
  final _picker = ImagePicker();
  String _type = 'national';
  DateTime? _birthDate;
  DateTime? _issueDate;
  File? _front;
  File? _back;
  Map<String, dynamic>? _kyc;
  bool _loading = true;
  bool _submitting = false;
  String? _loadError;
  int _step = 0;

  final _types = const [
    ('national', 'بطاقة شخصية', Icons.badge_rounded),
    ('passport', 'جواز سفر', Icons.flight_takeoff_rounded),
    ('family', 'بطاقة عائلية', Icons.family_restroom_rounded),
    ('electronic', 'بطاقة إلكترونية', Icons.credit_card_rounded),
  ];

  @override
  void initState() { super.initState(); _load(); }
  @override
  void dispose() { _name.dispose(); _id.dispose(); _birthPlace.dispose(); _issue.dispose(); _expiry.dispose(); super.dispose(); }

  Future<void> _load() async {
    if (mounted) setState(() { _loading = true; _loadError = null; });
    try {
      final k = await KycApiService.getStatus();
      if (!mounted) return;
      final status = k['status']?.toString().trim().toLowerCase() ?? '';
      setState(() {
        _kyc = k.isEmpty ? null : k;
        if (status == 'approved') _step = 0;
      });
    } catch (e) {
      if (mounted) setState(() => _loadError = e.toString().replaceFirst('Exception: ', ''));
    } finally { if (mounted) setState(() => _loading = false); }
  }

  String _labelType(String value) => _types.firstWhere((e) => e.$1 == value, orElse: () => _types.first).$2;
  String _fmt(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _date(bool birth) async {
    final now = DateTime.now();
    final initial = birth ? (_birthDate ?? DateTime(now.year - 25, now.month, now.day)) : (_issueDate ?? now);
    final picked = await showDatePicker(context: context, initialDate: initial, firstDate: DateTime(1900), lastDate: DateTime(now.year + 30), helpText: birth ? 'اختر تاريخ الميلاد' : 'اختر تاريخ الإصدار');
    if (picked == null) return;
    setState(() {
      if (birth) _birthDate = picked;
      else { _issueDate = picked; _issue.text = _fmt(picked); _expiry.text = _fmt(DateTime(picked.year + 10, picked.month, picked.day)); }
    });
  }

  Future<void> _pick(bool front, ImageSource source) async {
    final x = await _picker.pickImage(source: source, imageQuality: 88, maxWidth: 2200);
    if (x == null) return;
    setState(() { if (front) _front = File(x.path); else _back = File(x.path); });
  }

  Future<void> _chooseImage(bool front) async {
    await showModalBottomSheet(context: context, backgroundColor: AppColors.bg2, shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(22))), builder: (_) => SafeArea(child: Column(mainAxisSize: MainAxisSize.min, children: [
      ListTile(leading: const Icon(Icons.camera_alt_rounded, color: AppColors.primary), title: const Text('التقاط بالكاميرا'), onTap: () { Navigator.pop(context); _pick(front, ImageSource.camera); }),
      ListTile(leading: const Icon(Icons.photo_library_rounded, color: AppColors.cyan), title: const Text('اختيار من المعرض'), onTap: () { Navigator.pop(context); _pick(front, ImageSource.gallery); }),
      const SizedBox(height: 8),
    ])));
  }

  bool _validateStep() {
    if (_step == 0) return _type.isNotEmpty;
    if (_step == 1) return (_formKey.currentState?.validate() ?? false) && _birthDate != null;
    return _front != null && (_type == 'passport' || _back != null);
  }

  void _next() { if (!_validateStep()) { _show('أكمل البيانات المطلوبة أولاً', AppColors.red); return; } setState(() => _step++); }
  void _backStep() { if (_step > 0) setState(() => _step--); }

  Future<void> _submit() async {
    if (!_validateStep()) { _show('يرجى إرفاق صور الهوية المطلوبة', AppColors.red); return; }
    setState(() => _submitting = true);
    try {
      await KycApiService.submit(idType: _type, fullName: _name.text.trim(), nationalId: _id.text.trim(), birthDate: _fmt(_birthDate!), birthPlace: _birthPlace.text.trim(), issueDate: _issue.text.trim(), expiryDate: _expiry.text.trim(), imageFront: _front!, imageBack: _type == 'passport' ? null : _back);
      if (!mounted) return;
      _show('تم إرسال طلبك بنجاح! سيتم مراجعته خلال 24 ساعة 🎉', AppColors.green);
      await _load();
      if (mounted) Navigator.pop(context);
    } catch (e) { if (mounted) _show(e.toString().replaceFirst('Exception: ', ''), AppColors.red); }
    finally { if (mounted) setState(() => _submitting = false); }
  }

  void _show(String text, Color color) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text), backgroundColor: color, behavior: SnackBarBehavior.floating));

  @override
  Widget build(BuildContext context) {
    final kycStatus = _kyc?['status']?.toString().trim().toLowerCase() ?? '';
    return Scaffold(appBar: AppBar(title: const Text('تحقق الهوية'), centerTitle: true), body: _loading ? const Center(child: CircularProgressIndicator(color: AppColors.primary)) : RefreshIndicator(onRefresh: _load, color: AppColors.primary, child: ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 30), children: [
      if (_loadError != null) _errorCard(),
      if (_kyc != null) _statusCard(),
      if (_loadError == null && (_kyc == null || kycStatus == 'rejected')) ...[_intro(), const SizedBox(height: 14), _progress(), const SizedBox(height: 18), Form(key: _formKey, child: _stepBody()), const SizedBox(height: 18), _actions()]
    ])));
  }

  Widget _errorCard() => Container(padding: const EdgeInsets.all(16), decoration: BoxDecoration(color: AppColors.red.withOpacity(.09), borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.red.withOpacity(.28))), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [const Text('تعذر جلب حالة التوثيق', style: TextStyle(color: AppColors.red, fontWeight: FontWeight.w900)), const SizedBox(height: 7), Text(_loadError ?? 'تعذر الاتصال بالسيرفر', style: TextStyle(color: AppColors.text2, fontSize: 12)), const SizedBox(height: 10), OutlinedButton(onPressed: _load, child: const Text('إعادة المحاولة'))]));

  Widget _statusCard() {
    final s = _kyc?['status']?.toString().trim().toLowerCase() ?? '';
    if (s == 'approved') return _card(AppColors.green, Icons.verified_rounded, 'تم التحقق من هويتك', 'تم اعتماد بيانات هويتك بنجاح.', [if ((_kyc?['full_name'] ?? '').toString().isNotEmpty) 'الاسم: ${_kyc!['full_name']}', 'نوع الهوية: ${_labelType(_kyc!['id_type']?.toString() ?? 'national')}', if ((_kyc?['reviewed_at'] ?? '').toString().isNotEmpty) 'تاريخ الاعتماد: ${_kyc!['reviewed_at']}']);
    if (s == 'pending') return _card(AppColors.gold, Icons.hourglass_top_rounded, 'طلبك قيد المراجعة', 'تم استلام طلب التحقق وسيتم مراجعته خلال 24 ساعة. يرجى الانتظار حتى انتهاء المراجعة.', []);
    if (s == 'rejected') return _card(AppColors.red, Icons.cancel_rounded, 'تم رفض طلبك السابق', (_kyc?['admin_note'] ?? 'يمكنك تصحيح البيانات وإعادة تقديم الطلب.').toString(), []);
    return _card(AppColors.primary, Icons.badge_rounded, 'توثيق الحساب', 'لم تقدم طلب تحقق هوية بعد. يمكنك البدء بتقديم طلب التوثيق.', []);
  }

  Widget _card(Color color, IconData icon, String title, String subtitle, List<String> lines) => Container(padding: const EdgeInsets.all(18), decoration: BoxDecoration(color: color.withOpacity(.09), borderRadius: BorderRadius.circular(20), border: Border.all(color: color.withOpacity(.28))), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [Row(children: [Container(width: 44, height: 44, decoration: BoxDecoration(color: color.withOpacity(.15), borderRadius: BorderRadius.circular(14)), child: Icon(icon, color: color)), const SizedBox(width: 12), Expanded(child: Text(title, style: TextStyle(color: color, fontWeight: FontWeight.w900, fontSize: 15)))]), const SizedBox(height: 10), Text(subtitle, style: TextStyle(color: AppColors.text2, fontSize: 12, height: 1.5)), ...lines.map((e) => Padding(padding: const EdgeInsets.only(top: 5), child: Text(e, style: TextStyle(color: AppColors.text, fontSize: 11.5, fontWeight: FontWeight.w600))))]));

  Widget _intro() => Container(padding: const EdgeInsets.all(16), decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('تحقق الهوية', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)), const SizedBox(height: 5), Text('اثبت هويتك لفتح المزيد من المميزات', style: TextStyle(color: AppColors.text2, fontSize: 11.5))]));

  Widget _progress() => Row(children: List.generate(3, (i) { final active = i <= _step; return Expanded(child: Padding(padding: EdgeInsets.only(left: i == 0 ? 0 : 6), child: Column(children: [Container(height: 5, decoration: BoxDecoration(color: active ? AppColors.primary : AppColors.border, borderRadius: BorderRadius.circular(8))), const SizedBox(height: 6), Text('${i + 1}', style: TextStyle(color: active ? AppColors.primary : AppColors.text2, fontSize: 10, fontWeight: FontWeight.bold))]))); }));

  Widget _stepBody() {
    if (_step == 0) return _typeStep();
    if (_step == 1) return _detailsStep();
    return _imagesStep();
  }

  Widget _typeStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [const Text('اختر نوع الهوية', style: TextStyle(fontWeight: FontWeight.bold)), const SizedBox(height: 10), GridView.builder(shrinkWrap: true, physics: const NeverScrollableScrollPhysics(), itemCount: _types.length, gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, crossAxisSpacing: 10, mainAxisSpacing: 10, childAspectRatio: 1.75), itemBuilder: (_, i) { final t = _types[i]; final selected = _type == t.$1; return InkWell(onTap: () => setState(() { _type = t.$1; if (_type == 'passport') _back = null; }), borderRadius: BorderRadius.circular(16), child: Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: selected ? AppColors.primary.withOpacity(.12) : AppColors.bg2, borderRadius: BorderRadius.circular(16), border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.5 : 1)), child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [Icon(t.$3, color: selected ? AppColors.primary : AppColors.text2, size: 25), const SizedBox(height: 6), Text(t.$2, style: TextStyle(color: selected ? AppColors.primary : AppColors.text, fontSize: 11, fontWeight: FontWeight.bold))]))); })]);

  InputDecoration _dec(String label, IconData icon) => InputDecoration(labelText: label, prefixIcon: Icon(icon, size: 18), filled: true, fillColor: AppColors.bg2, border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.border)), enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.border)), contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 13));
  Widget _detailsStep() => Column(children: [TextFormField(controller: _name, decoration: _dec('الاسم الكامل', Icons.person_rounded), validator: (v) => (v?.trim().length ?? 0) < 3 ? 'الاسم مطلوب (3 أحرف على الأقل)' : null), const SizedBox(height: 10), TextFormField(controller: _id, decoration: _dec(_type == 'passport' ? 'رقم الجواز' : 'الرقم الوطني / رقم الهوية', Icons.numbers_rounded), validator: (v) => (v?.trim().isEmpty ?? true) ? 'هذا الحقل مطلوب' : null), const SizedBox(height: 10), InkWell(onTap: () => _date(true), child: InputDecorator(decoration: _dec('تاريخ الميلاد', Icons.cake_rounded), child: Text(_birthDate == null ? 'اختر التاريخ' : _fmt(_birthDate!), style: TextStyle(color: _birthDate == null ? AppColors.text2 : AppColors.text)))), const SizedBox(height: 10), TextFormField(controller: _birthPlace, decoration: _dec('مكان الميلاد (اختياري)', Icons.location_on_rounded)), const SizedBox(height: 10), InkWell(onTap: () => _date(false), child: InputDecorator(decoration: _dec('تاريخ الإصدار', Icons.event_available_rounded), child: Text(_issue.text.isEmpty ? 'اختر التاريخ' : _issue.text, style: TextStyle(color: _issue.text.isEmpty ? AppColors.text2 : AppColors.text)))), const SizedBox(height: 10), TextFormField(controller: _expiry, readOnly: true, decoration: _dec('تاريخ الانتهاء', Icons.event_busy_rounded), validator: (v) => (v?.isEmpty ?? true) ? 'تاريخ الانتهاء مطلوب' : null)]);

  Widget _imagesStep() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [const Text('صور الهوية', style: TextStyle(fontWeight: FontWeight.bold)), const SizedBox(height: 10), _imageBox(true, _front, 'صورة الوجه الأمامي', true), if (_type != 'passport') ...[const SizedBox(height: 12), _imageBox(false, _back, 'صورة الوجه الخلفي', true)]]);
  Widget _imageBox(bool front, File? file, String title, bool required) => InkWell(onTap: () => _chooseImage(front), borderRadius: BorderRadius.circular(18), child: Container(height: 170, decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(18), border: Border.all(color: file != null ? AppColors.primary : AppColors.border, width: file != null ? 1.5 : 1)), child: file == null ? Column(mainAxisAlignment: MainAxisAlignment.center, children: [Icon(Icons.add_a_photo_rounded, color: AppColors.text2, size: 32), const SizedBox(height: 8), Text(title, style: TextStyle(color: AppColors.text2, fontWeight: FontWeight.bold)), if (required) const SizedBox(height: 4), if (required) Text('مطلوب', style: TextStyle(color: AppColors.red, fontSize: 10))]) : ClipRRect(borderRadius: BorderRadius.circular(17), child: Stack(fit: StackFit.expand, children: [Image.file(file, fit: BoxFit.cover), Positioned(right: 10, top: 10, child: Container(padding: const EdgeInsets.all(7), decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(10)), child: const Icon(Icons.edit_rounded, color: Colors.white, size: 18)))]))));

  Widget _actions() => Row(children: [if (_step > 0) Expanded(child: OutlinedButton(onPressed: _submitting ? null : _backStep, child: const Text('السابق'))), if (_step > 0) const SizedBox(width: 10), Expanded(flex: 2, child: ElevatedButton(onPressed: _submitting ? null : (_step < 2 ? _next : _submit), child: _submitting ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(_step < 2 ? 'التالي' : 'إرسال الطلب')))]);
}
