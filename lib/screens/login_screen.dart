import 'package:flutter/material.dart';
import '../services/api_service.dart';
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
      backgroundColor: const Color(0xFF090D1A),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Center(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 40),
                  const Icon(Icons.bolt_rounded, color: Color(0xFF3B82F6), size: 56),
                  const SizedBox(height: 12),
                  const Text('تسجيل الدخول',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 32),
                  _field(_loginCtrl, 'اسم المستخدم أو البريد الإلكتروني', Icons.person_outline),
                  const SizedBox(height: 14),
                  _field(_passCtrl, 'كلمة المرور', Icons.lock_outline, obscure: true),
                  if (_need2fa) ...[
                    const SizedBox(height: 14),
                    _field(_totpCtrl, 'رمز المصادقة الثنائية', Icons.security, keyboardType: TextInputType.number),
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 14),
                    Text(_error!, style: const TextStyle(color: Color(0xFFF87171)), textAlign: TextAlign.center),
                  ],
                  const SizedBox(height: 24),
                  ElevatedButton(
                    onPressed: _loading ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF3B82F6),
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    child: _loading
                        ? const SizedBox(
                            height: 20, width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Text('دخول', style: TextStyle(fontSize: 16, color: Colors.white)),
                  ),
                  const SizedBox(height: 16),
                  TextButton(
                    onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const RegisterScreen())),
                    child: const Text('ليس لديك حساب؟ إنشاء حساب جديد',
                        style: TextStyle(color: Color(0xFF7C93B5))),
                  ),
                ],
              ),
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
      style: const TextStyle(color: Colors.white),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: const TextStyle(color: Color(0xFF7C93B5)),
        prefixIcon: Icon(icon, color: const Color(0xFF7C93B5)),
        filled: true,
        fillColor: const Color(0xFF151F35),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide.none,
        ),
      ),
    );
  }
}
