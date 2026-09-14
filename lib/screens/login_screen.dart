import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
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
  bool _obscurePassword = true;
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
    final isDark = AppColors.isDark;

    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Stack(
        children: [
          // ── الخلفية المزخرفة (أوراب ضوئية ناعمة) ──
          Positioned(
            top: -100,
            right: -80,
            child: Container(
              width: 300,
              height: 300,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: AppColors.primary.withOpacity(isDark ? 0.12 : 0.06),
                    blurRadius: 100,
                    spreadRadius: 50,
                  ),
                ],
              ),
            ),
          ),
          Positioned(
            bottom: -120,
            left: -80,
            child: Container(
              width: 300,
              height: 300,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: AppColors.accentPurple.withOpacity(isDark ? 0.10 : 0.05),
                    blurRadius: 120,
                    spreadRadius: 60,
                  ),
                ],
              ),
            ),
          ),

          // ── المحتوى الرئيسي ──
          SafeArea(
            child: CustomScrollView(
              slivers: [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // زر تبديل الوضع الليلي والنهاري في الأعلى
                        Align(
                          alignment: Alignment.topLeft,
                          child: Material(
                            color: Colors.transparent,
                            child: InkWell(
                              borderRadius: BorderRadius.circular(16),
                              onTap: () async {
                                final nextMode = !AppColors.isDark;
                                AppColors.setDark(nextMode);
                                await StorageService.saveSetting('dark_mode', '$nextMode');
                                setState(() {});
                              },
                              child: Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: AppColors.card,
                                  borderRadius: BorderRadius.circular(16),
                                  border: Border.all(color: AppColors.border),
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withOpacity(0.04),
                                      blurRadius: 10,
                                      offset: const Offset(0, 4),
                                    ),
                                  ],
                                ),
                                child: AnimatedSwitcher(
                                  duration: const Duration(milliseconds: 300),
                                  transitionBuilder: (child, anim) => ScaleTransition(scale: anim, child: child),
                                  child: Icon(
                                    isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded,
                                    key: ValueKey<bool>(isDark),
                                    color: isDark ? AppColors.gold : AppColors.primary,
                                    size: 22,
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),

                        const Spacer(),

                        // شعار التطبيق المطور
                        Center(
                          child: Container(
                            padding: const EdgeInsets.all(4),
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              border: Border.all(color: AppColors.primary.withOpacity(0.12), width: 2),
                            ),
                            child: Container(
                              width: 86,
                              height: 86,
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                gradient: AppColors.balanceGradient,
                                borderRadius: BorderRadius.circular(24),
                                boxShadow: [
                                  BoxShadow(
                                    color: AppColors.accentPurple.withOpacity(0.35),
                                    blurRadius: 20,
                                    offset: const Offset(0, 10),
                                  ),
                                ],
                              ),
                              child: const Icon(Icons.bolt_rounded, color: Colors.white, size: 44),
                            ),
                          ),
                        ),
                        const SizedBox(height: 24),

                        // نصوص الترحيب
                        Text(
                          'تسجيل الدخول',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: AppColors.text,
                            fontSize: 26,
                            fontWeight: FontWeight.bold,
                            letterSpacing: -0.5,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'مرحباً بعودتك 👋 سجّل دخولك للوصول إلى حسابك',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: AppColors.text2,
                            fontSize: 13.5,
                            height: 1.4,
                          ),
                        ),
                        const SizedBox(height: 32),

                        // بطاقة إدخال البيانات المحدثة بالكامل
                        Container(
                          padding: const EdgeInsets.all(22),
                          decoration: BoxDecoration(
                            color: AppColors.card,
                            borderRadius: BorderRadius.circular(28),
                            border: Border.all(color: AppColors.border, width: 1.2),
                            boxShadow: [
                              BoxShadow(
                                color: isDark ? Colors.black.withOpacity(0.3) : Colors.black.withOpacity(0.04),
                                blurRadius: 30,
                                offset: const Offset(0, 15),
                              ),
                            ],
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _field(
                                controller: _loginCtrl,
                                label: 'اسم المستخدم أو البريد الإلكتروني',
                                icon: Icons.person_outline_rounded,
                              ),
                              const SizedBox(height: 16),
                              _field(
                                controller: _passCtrl,
                                label: 'كلمة المرور',
                                icon: Icons.lock_outline_rounded,
                                obscure: _obscurePassword,
                                isPassword: true,
                                onToggleObscure: () {
                                  setState(() {
                                    _obscurePassword = !_obscurePassword;
                                  });
                                },
                              ),
                              if (_need2fa) ...[
                                const SizedBox(height: 16),
                                _field(
                                  controller: _totpCtrl,
                                  label: 'رمز المصادقة الثنائية',
                                  icon: Icons.security_rounded,
                                  keyboardType: TextInputType.number,
                                ),
                              ],
                              if (_error != null) ...[
                                const SizedBox(height: 16),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                                  decoration: BoxDecoration(
                                    color: AppColors.red.withOpacity(0.08),
                                    borderRadius: BorderRadius.circular(14),
                                    border: Border.all(color: AppColors.red.withOpacity(0.25)),
                                  ),
                                  child: Row(
                                    children: [
                                      const Icon(Icons.error_outline_rounded, color: AppColors.red, size: 20),
                                      const SizedBox(width: 10),
                                      Expanded(
                                        child: Text(
                                          _error!,
                                          style: const TextStyle(
                                            color: AppColors.red,
                                            fontSize: 12.5,
                                            height: 1.4,
                                            fontWeight: FontWeight.w600,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                              const SizedBox(height: 24),
                              _gradientButton(
                                label: 'دخول',
                                loading: _loading,
                                onPressed: _submit,
                              ),
                            ],
                          ),
                        ),

                        const Spacer(),
                        const SizedBox(height: 24),

                        // رابط إنشاء حساب جديد منسق
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(
                              'ليس لديك حساب؟',
                              style: TextStyle(color: AppColors.text2, fontSize: 13.5),
                            ),
                            TextButton(
                              onPressed: () => Navigator.of(context).push(
                                MaterialPageRoute(builder: (_) => const RegisterScreen()),
                              ),
                              style: TextButton.styleFrom(
                                padding: const EdgeInsets.symmetric(horizontal: 8),
                              ),
                              child: const Text(
                                'إنشاء حساب جديد',
                                style: TextStyle(
                                  color: AppColors.primary,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13.5,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 16),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _gradientButton({required String label, required bool loading, required VoidCallback onPressed}) {
    return Container(
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: AppColors.accentPurple.withOpacity(0.35),
            blurRadius: 16,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: loading ? null : onPressed,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Center(
              child: loading
                  ? const SizedBox(
                      height: 22,
                      width: 22,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.5,
                        color: Colors.white,
                      ),
                    )
                  : Text(
                      label,
                      style: const TextStyle(
                        fontSize: 16,
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 0.5,
                      ),
                    ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    bool obscure = false,
    bool isPassword = false,
    TextInputType? keyboardType,
    VoidCallback? onToggleObscure,
  }) {
    return TextField(
      controller: controller,
      obscureText: obscure,
      keyboardType: keyboardType,
      style: TextStyle(color: AppColors.text, fontSize: 14),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: TextStyle(color: AppColors.text2, fontSize: 13),
        prefixIcon: Icon(icon, color: AppColors.text2, size: 20),
        suffixIcon: isPassword
            ? IconButton(
                onPressed: onToggleObscure,
                icon: Icon(
                  obscure ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                  color: AppColors.text2,
                  size: 20,
                ),
                splashRadius: 20,
              )
            : null,
        filled: true,
        fillColor: AppColors.card2,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.5),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      ),
    );
  }
}
