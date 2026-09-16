import 'package:flutter/material.dart';
import '../services/kyc_api_service.dart';
import '../services/quiz_api_service.dart';
import '../theme/app_colors.dart';
import 'kyc_screen.dart';

class QuizScreen extends StatefulWidget {
  const QuizScreen({super.key});

  @override
  State<QuizScreen> createState() => _QuizScreenState();
}

class _QuizScreenState extends State<QuizScreen> {
  Map<String, dynamic>? _quiz;
  List<dynamic> _questions = [];
  Map<String, dynamic>? _myEntry;
  Map<String, String> _answers = {};
  bool _loading = true;
  bool _submitting = false;
  bool _verified = false;
  String? _error;
  DateTime? _startedAt;

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
      final kyc = await KycApiService.getStatus();
      final status = kyc['status']?.toString().trim().toLowerCase() ?? '';
      final verified = status == 'approved';
      final data = await QuizApiService.getActive();
      if (!mounted) return;
      setState(() {
        _verified = verified;
        _quiz = data['quiz'] as Map<String, dynamic>?;
        _questions = (data['questions'] as List<dynamic>?) ?? [];
        _myEntry = data['my_entry'] as Map<String, dynamic>?;
        _loading = false;
        if (_startedAt == null && _myEntry == null && verified) _startedAt = DateTime.now();
      });
    } on QuizApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل المسابقات، حاول مرة أخرى';
        _loading = false;
      });
    }
  }

  Future<void> _submit() async {
    final quizId = (_quiz?['id'] as num?)?.toInt() ?? 0;
    if (quizId <= 0 || _submitting || _myEntry != null) return;

    setState(() => _submitting = true);
    try {
      final started = _startedAt ?? DateTime.now();
      final result = await QuizApiService.submit(
        quizId: quizId,
        answers: _answers,
        timeTaken: DateTime.now().difference(started).inSeconds,
      );
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (_) => AlertDialog(
          backgroundColor: AppColors.bg2,
          title: const Row(children: [Icon(Icons.emoji_events_rounded, color: AppColors.gold), SizedBox(width: 8), Text('تم تسجيل مشاركتك')]),
          content: Text(result['message']?.toString() ?? 'تم تسجيل مشاركتك في المسابقة بنجاح.', style: TextStyle(color: AppColors.text2, height: 1.5)),
          actions: [FilledButton(onPressed: () => Navigator.pop(context), child: const Text('حسناً'))],
        ),
      );
      if (mounted) _load();
    } on QuizApiException catch (e) {
      if (!mounted) return;
      final code = e.data['error']?.toString() ?? '';
      if (code == 'kyc') {
        await _showKycRequired();
      } else {
        _showMessage(e.message, AppColors.red);
      }
    } catch (_) {
      if (mounted) _showMessage('تعذر إرسال المشاركة، حاول مرة أخرى', AppColors.red);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _showKycRequired() async {
    await showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bg2,
        title: const Row(children: [Icon(Icons.verified_user_rounded, color: AppColors.gold), SizedBox(width: 8), Text('التوثيق مطلوب')]),
        content: Text('لا يمكنك المشاركة في المسابقات إلا بعد توثيق حسابك.', style: TextStyle(color: AppColors.text2, height: 1.5)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('إلغاء')),
          FilledButton(onPressed: () { Navigator.pop(context); Navigator.of(context).push(MaterialPageRoute(builder: (_) => const KycScreen())).then((_) => _load()); }, child: const Text('توثيق الحساب')),
        ],
      ),
    );
  }

  void _showMessage(String message, Color color) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message), backgroundColor: color, behavior: SnackBarBehavior.floating));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('المسابقات')),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : RefreshIndicator(
              onRefresh: _load,
              color: AppColors.primary,
              backgroundColor: AppColors.card,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                children: [
                  if (_error != null) _errorCard(),
                  if (_error == null && _quiz != null) ...[
                    _quizHeader(),
                    const SizedBox(height: 14),
                    if (!_verified) _verificationCard() else if (_myEntry != null) _resultCard() else ...[
                      ..._questions.map((q) => _questionCard(q)),
                      const SizedBox(height: 8),
                      _submitButton(),
                    ],
                  ] else if (_error == null) _emptyCard(),
                ],
              ),
            ),
    );
  }

  Widget _quizHeader() {
    final title = _quiz?['title']?.toString().trim();
    final description = _quiz?['description']?.toString().trim();
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(22),
        boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(.2), blurRadius: 18, offset: const Offset(0, 8))],
      ),
      child: Row(children: [
        Container(width: 50, height: 50, alignment: Alignment.center, decoration: BoxDecoration(color: Colors.white.withOpacity(.18), borderRadius: BorderRadius.circular(16)), child: const Icon(Icons.emoji_events_rounded, color: Colors.white, size: 26)),
        const SizedBox(width: 13),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title?.isNotEmpty == true ? title! : 'المسابقة الحالية', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 16)),
          if (description?.isNotEmpty == true) Padding(padding: const EdgeInsets.only(top: 4), child: Text(description!, style: const TextStyle(color: Colors.white70, fontSize: 11, height: 1.4))),
          Padding(padding: const EdgeInsets.only(top: 6), child: Text('${_questions.length} سؤال', style: const TextStyle(color: Colors.white70, fontSize: 10))),
        ])),
      ]),
    );
  }

  Widget _verificationCard() => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: AppColors.gold.withOpacity(.08), borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.gold.withOpacity(.25))),
        child: Column(children: [
          Container(width: 52, height: 52, alignment: Alignment.center, decoration: BoxDecoration(color: AppColors.gold.withOpacity(.15), borderRadius: BorderRadius.circular(16)), child: const Icon(Icons.verified_user_rounded, color: AppColors.gold, size: 27)),
          const SizedBox(height: 10),
          Text('توثيق الحساب مطلوب للمشاركة', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.w900, fontSize: 14)),
          const SizedBox(height: 5),
          Text('لا يمكنك المشاركة في المسابقات إلا بعد توثيق حسابك.', textAlign: TextAlign.center, style: TextStyle(color: AppColors.text2, fontSize: 11, height: 1.5)),
          const SizedBox(height: 12),
          FilledButton.icon(onPressed: _showKycRequired, icon: const Icon(Icons.verified_user_rounded, size: 17), label: const Text('توثيق الحساب')),
        ]),
      );

  Widget _resultCard() {
    final correct = _myEntry?['correct_count']?.toString() ?? '0';
    final total = _myEntry?['total_questions']?.toString() ?? '${_questions.length}';
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(color: AppColors.green.withOpacity(.08), borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.green.withOpacity(.25))),
      child: Column(children: [
        const Icon(Icons.check_circle_rounded, color: AppColors.green, size: 42),
        const SizedBox(height: 10),
        Text('تمت المشاركة في هذه المسابقة', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.w900, fontSize: 14)),
        const SizedBox(height: 6),
        Text('نتيجتك: $correct من $total', style: TextStyle(color: AppColors.green, fontWeight: FontWeight.bold, fontSize: 13)),
        const SizedBox(height: 4),
        Text('لا يمكن المشاركة مرة أخرى في نفس المسابقة.', style: TextStyle(color: AppColors.text2, fontSize: 10.5)),
      ]),
    );
  }

  Widget _questionCard(dynamic raw) {
    final q = raw as Map<String, dynamic>;
    final id = q['id']?.toString() ?? '';
    final options = <String, String>{
      'a': q['option_a']?.toString() ?? '',
      'b': q['option_b']?.toString() ?? '',
      'c': q['option_c']?.toString() ?? '',
      'd': q['option_d']?.toString() ?? '',
    };
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${q['sort_order'] ?? ''}. ${q['question'] ?? ''}', style: TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.bold, height: 1.5)),
        const SizedBox(height: 8),
        ...options.entries.where((e) => e.value.trim().isNotEmpty).map((entry) => RadioListTile<String>(dense: true, contentPadding: EdgeInsets.zero, activeColor: AppColors.primary, title: Text(entry.value, style: TextStyle(color: AppColors.text2, fontSize: 11.5)), value: entry.key, groupValue: _answers[id], onChanged: (value) { if (value == null) return; setState(() => _answers[id] = value); })),
      ]),
    );
  }

  Widget _submitButton() => SizedBox(height: 50, child: FilledButton.icon(onPressed: _submitting ? null : _submit, icon: _submitting ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send_rounded), label: Text(_submitting ? 'جارٍ تسجيل المشاركة...' : 'إرسال المشاركة')));

  Widget _emptyCard() => _simpleCard(Icons.emoji_events_outlined, 'لا توجد مسابقة نشطة حالياً', 'عند توفر مسابقة جديدة ستظهر هنا.');
  Widget _errorCard() => _simpleCard(Icons.error_outline_rounded, 'تعذر تحميل المسابقة', _error ?? 'حاول مرة أخرى.');
  Widget _simpleCard(IconData icon, String title, String subtitle) => Container(padding: const EdgeInsets.all(20), decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)), child: Column(children: [Icon(icon, color: AppColors.text2, size: 40), const SizedBox(height: 10), Text(title, style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold, fontSize: 13)), const SizedBox(height: 5), Text(subtitle, textAlign: TextAlign.center, style: TextStyle(color: AppColors.text2, fontSize: 11, height: 1.5)), const SizedBox(height: 12), OutlinedButton.icon(onPressed: _load, icon: const Icon(Icons.refresh_rounded, size: 17), label: const Text('تحديث'))]));
}
