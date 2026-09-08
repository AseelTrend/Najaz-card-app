import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import 'home_screen.dart';
import 'register_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _loginCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _totpCtrl = TextEditingController();
  bool _loading = false;
  bool _need2fa = false;
  String? _error;

  Future<void> _submit() async {
    if (_loginCtrl.text.trim().isEmpty || _passCtrl.text.isEmpty) {
      setState(() => _error = 'أدخل اسم المستخدم وكلمة المرور');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ApiService.login(
        login: _loginCtrl.text.trim(),
        password: _passCtrl.text,
        totpCode: _need2fa ? _totpCtrl.text.trim() : null,
      );
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const HomeScreen()),
        (route) => false,
      );
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        if (e.data['need_2fa'] == true) _need2fa = true;
        if (e.data['device_pending'] == true) {
          _error = '${e.message}\nراجع بريدك الإلكتروني أو واتساب لتفعيل هذا الجهاز.';
        }
      });
    } catch (e) {
      setState(() => _error = 'تعذر الاتصال بالسيرفر، تحقق من الإنترنت');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Center(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 32),
                  Container(
                    width: 84,
                    height: 84,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      gradient: AppColors.balanceGradient,
                      borderRadius: BorderRadius.circular(24),
                      boxShadow: [
                        BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 20, offset: const Offset(0, 10)),
                      ],
                    ),
                    child: const Icon(Icons.bolt_rounded, color: Colors.white, size: 42),
                  ),
                  const SizedBox(height: 20),
                  const Text('تسجيل الدخول',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AppColors.text, fontSize: 22, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 6),
                  const Text('مرحباً بعودتك 👋',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AppColors.text2, fontSize: 13)),
                  const SizedBox(height: 32),
                  _field(_loginCtrl, 'اسم المستخدم أو البريد الإلكتروني', Icons.person_outline_rounded),
                  const SizedBox(height: 14),
                  _field(_passCtrl, 'كلمة المرور', Icons.lock_outline_rounded, obscure: true),
                  if (_need2fa) ...[
                    const SizedBox(height: 14),
                    _field(_totpCtrl, 'رمز المصادقة الثنائية', Icons.security_rounded,
                        keyboardType: TextInputType.number),
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 14),
                    Text(_error!, style: const TextStyle(color: AppColors.red), textAlign: TextAlign.center),
                  ],
                  const SizedBox(height: 24),
                  _gradientButton(
                    label: 'دخول',
                    loading: _loading,
                    onPressed: _submit,
                  ),
                  const SizedBox(height: 16),
                  TextButton(
                    onPressed: () => Navigator.of(context)
                        .push(MaterialPageRoute(builder: (_) => const RegisterScreen())),
                    child: const Text('ليس لديك حساب؟ إنشاء حساب جديد',
                        style: TextStyle(color: AppColors.text2)),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _gradientButton({required String label, required bool loading, required VoidCallback onPressed}) {
    return Container(
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(color: AppColors.accentPurple.withOpacity(0.3), blurRadius: 14, offset: const Offset(0, 6)),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: loading ? null : onPressed,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Center(
              child: loading
                  ? const SizedBox(
                      height: 20, width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(label, style: const TextStyle(fontSize: 16, color: Colors.white, fontWeight: FontWeight.w600)),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field(TextEditingController c, String label, IconData icon,
      {bool obscure = false, TextInputType? keyboardType}) {
    return TextField(
      controller: c,
      obscureText: obscure,
      keyboardType: keyboardType,
      style: const TextStyle(color: AppColors.text),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: const TextStyle(color: AppColors.text2),
        prefixIcon: Icon(icon, color: AppColors.text2),
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
    );
  }
}
