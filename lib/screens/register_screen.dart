import 'package:flutter/material.dart';
import '../services/api_service.dart';
import 'home_screen.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});
  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _username = TextEditingController();
  final _email = TextEditingController();
  final _fullName = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _password2 = TextEditingController();
  final _referral = TextEditingController();
  bool _loading = false;
  String? _error;

  Future<void> _submit() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ApiService.register(
        username: _username.text.trim(),
        email: _email.text.trim(),
        password: _password.text,
        password2: _password2.text,
        fullName: _fullName.text.trim(),
        phone: _phone.text.trim(),
        referralCode: _referral.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const HomeScreen()),
        (route) => false,
      );
    } on ApiException catch (e) {
      setState(() => _error = e.message);
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
      appBar: AppBar(
        backgroundColor: const Color(0xFF090D1A),
        elevation: 0,
        title: const Text('إنشاء حساب', style: TextStyle(color: Colors.white)),
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: 8),
                _field(_username, 'اسم المستخدم (إنجليزي وأرقام فقط)', Icons.person_outline),
                const SizedBox(height: 14),
                _field(_email, 'البريد الإلكتروني', Icons.email_outlined, keyboardType: TextInputType.emailAddress),
                const SizedBox(height: 14),
                _field(_fullName, 'الاسم الكامل', Icons.badge_outlined),
                const SizedBox(height: 14),
                _field(_phone, 'رقم الهاتف', Icons.phone_outlined, keyboardType: TextInputType.phone),
                const SizedBox(height: 14),
                _field(_password, 'كلمة المرور', Icons.lock_outline, obscure: true),
                const SizedBox(height: 14),
                _field(_password2, 'تأكيد كلمة المرور', Icons.lock_outline, obscure: true),
                const SizedBox(height: 14),
                _field(_referral, 'كود الإحالة (اختياري)', Icons.card_giftcard_outlined),
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
                      : const Text('إنشاء الحساب', style: TextStyle(fontSize: 16, color: Colors.white)),
                ),
                const SizedBox(height: 24),
              ],
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
