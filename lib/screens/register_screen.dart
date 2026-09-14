import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
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
  bool _obscurePassword = true;
  String? _error;

  final List<Map<String, String>> _countries = const [
    {'f': '🇸🇦', 'n': 'المملكة العربية السعودية', 'd': '+966'},
    {'f': '🇦🇪', 'n': 'الإمارات العربية المتحدة', 'd': '+971'},
    {'f': '🇰🇼', 'n': 'الكويت', 'd': '+965'},
    {'f': '🇶🇦', 'n': 'قطر', 'd': '+974'},
    {'f': '🇧🇭', 'n': 'البحرين', 'd': '+973'},
    {'f': '🇴🇲', 'n': 'عُمان', 'd': '+968'},
    {'f': '🇾🇲', 'n': 'اليمن', 'd': '+967'},
    {'f': '🇮🇶', 'n': 'العراق', 'd': '+964'},
    {'f': '🇸🇾', 'n': 'سوريا', 'd': '+963'},
    {'f': '🇯🇴', 'n': 'الأردن', 'd': '+962'},
    {'f': '🇱🇧', 'n': 'لبنان', 'd': '+961'},
    {'f': '🇵🇸', 'n': 'فلسطين', 'd': '+970'},
    {'f': '🇪🇬', 'n': 'مصر', 'd': '+20'},
    {'f': '🇱🇾', 'n': 'ليبيا', 'd': '+218'},
    {'f': '🇹🇳', 'n': 'تونس', 'd': '+216'},
    {'f': '🇩🇿', 'n': 'الجزائر', 'd': '+213'},
    {'f': '🇲🇦', 'n': 'المغرب', 'd': '+212'},
    {'f': '🇸🇩', 'n': 'السودان', 'd': '+249'},
    {'f': '🇸🇴', 'n': 'الصومال', 'd': '+252'},
    {'f': '🇩🇯', 'n': 'جيبوتي', 'd': '+253'},
    {'f': '🇰🇲', 'n': 'جزر القمر', 'd': '+269'},
    {'f': '🇲🇷', 'n': 'موريتانيا', 'd': '+222'},
    {'f': '🇹🇩', 'n': 'تشاد', 'd': '+235'},
    {'f': '🇪🇷', 'n': 'إريتريا', 'd': '+291'},
    {'f': '🇸🇸', 'n': 'جنوب السودان', 'd': '+211'},
    {'f': '🇹🇷', 'n': 'تركيا', 'd': '+90'},
    {'f': '🇮🇷', 'n': 'إيران', 'd': '+98'},
    {'f': '🇦🇫', 'n': 'أفغانستان', 'd': '+93'},
    {'f': '🇵🇰', 'n': 'باكستان', 'd': '+92'},
    {'f': '🇮🇳', 'n': 'الهند', 'd': '+91'},
    {'f': '🇧🇩', 'n': 'بنغلاديش', 'd': '+880'},
    {'f': '🇱🇰', 'n': 'سريلانكا', 'd': '+94'},
    {'f': '🇳🇵', 'n': 'نيبال', 'd': '+977'},
    {'f': '🇲🇲', 'n': 'ميانمار', 'd': '+95'},
    {'f': '🇹🇭', 'n': 'تايلاند', 'd': '+66'},
    {'f': '🇻🇳', 'n': 'فيتنام', 'd': '+84'},
    {'f': '🇮🇩', 'n': 'إندونيسيا', 'd': '+62'},
    {'f': '🇲🇾', 'n': 'ماليزيا', 'd': '+60'},
    {'f': '🇵🇭', 'n': 'الفلبين', 'd': '+63'},
    {'f': '🇸🇬', 'n': 'سنغافورة', 'd': '+65'},
    {'f': '🇰🇭', 'n': 'كمبوديا', 'd': '+855'},
    {'f': '🇲🇳', 'n': 'منغوليا', 'd': '+976'},
    {'f': '🇨🇳', 'n': 'الصين', 'd': '+86'},
    {'f': '🇯🇵', 'n': 'اليابان', 'd': '+81'},
    {'f': '🇰🇷', 'n': 'كوريا الجنوبية', 'd': '+82'},
    {'f': '🇹🇼', 'n': 'تايوان', 'd': '+886'},
    {'f': '🇰🇿', 'n': 'كازاخستان', 'd': '+7'},
    {'f': '🇺🇿', 'n': 'أوزبكستان', 'd': '+998'},
    {'f': '🇹🇲', 'n': 'تركمانستان', 'd': '+993'},
    {'f': '🇰🇬', 'n': 'قيرغيزستان', 'd': '+996'},
    {'f': '🇹🇯', 'n': 'طاجيكستان', 'd': '+992'},
    {'f': '🇦🇿', 'n': 'أذربيجان', 'd': '+994'},
    {'f': '🇦🇲', 'n': 'أرمينيا', 'd': '+374'},
    {'f': '🇬🇪', 'n': 'جورجيا', 'd': '+995'},
    {'f': '🇮🇱', 'n': 'إسرائيل', 'd': '+972'},
    {'f': '🇺🇸', 'n': 'الولايات المتحدة', 'd': '+1'},
  ];

  late Map<String, String> _selectedCountry;

  @override
  void initState() {
    super.initState();
    _selectedCountry = _countries.first;
  }

  Future<void> _openGoogleRegistration() async {
    final uri = Uri.parse('https://njaz.net/auth/google/redirect.php');
    final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!opened && mounted) {
      setState(() => _error = 'تعذر فتح التسجيل عبر Google');
    }
  }

  Future<void> _submit() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final fullPhone = _selectedCountry['d']! + _phone.text.trim();
      await ApiService.register(
        username: _username.text.trim(),
        email: _email.text.trim(),
        password: _password.text,
        password2: _password2.text,
        fullName: _fullName.text.trim(),
        phone: fullPhone,
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
    final isDark = AppColors.isDark;
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        centerTitle: true,
        title: Text('إنشاء حساب جديد', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold, fontSize: 18)),
        leading: IconButton(
          icon: Icon(Icons.arrow_back_ios_new_rounded, color: AppColors.text, size: 20),
          onPressed: () => Navigator.pop(context),
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.all(22),
                decoration: BoxDecoration(
                  color: AppColors.card,
                  borderRadius: BorderRadius.circular(28),
                  border: Border.all(color: AppColors.border, width: 1.2),
                  boxShadow: [BoxShadow(color: isDark ? Colors.black.withOpacity(0.3) : Colors.black.withOpacity(0.04), blurRadius: 30, offset: const Offset(0, 15))],
                ),
                child: Column(
                  children: [
                    _field(controller: _username, label: 'اسم المستخدم (إنجليزي وأرقام)', icon: Icons.person_outline_rounded),
                    const SizedBox(height: 16),
                    _field(controller: _email, label: 'البريد الإلكتروني', icon: Icons.email_outlined, keyboardType: TextInputType.emailAddress),
                    const SizedBox(height: 16),
                    _field(controller: _fullName, label: 'الاسم الكامل', icon: Icons.badge_outlined),
                    const SizedBox(height: 16),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _countryPicker(),
                        const SizedBox(width: 10),
                        Expanded(child: _field(controller: _phone, label: 'رقم الهاتف', icon: Icons.phone_outlined, keyboardType: TextInputType.phone)),
                      ],
                    ),
                    const SizedBox(height: 16),
                    _field(controller: _password, label: 'كلمة المرور', icon: Icons.lock_outline_rounded, obscure: _obscurePassword, isPassword: true, onToggleObscure: () => setState(() => _obscurePassword = !_obscurePassword)),
                    const SizedBox(height: 16),
                    _field(controller: _password2, label: 'تأكيد كلمة المرور', icon: Icons.lock_reset_rounded, obscure: _obscurePassword),
                    const SizedBox(height: 16),
                    _field(controller: _referral, label: 'كود الإحالة (اختياري)', icon: Icons.card_giftcard_outlined),
                    if (_error != null) ...[
                      const SizedBox(height: 20),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.red.withOpacity(0.08),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: AppColors.red.withOpacity(0.2)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.error_outline_rounded, color: AppColors.red, size: 20),
                            const SizedBox(width: 10),
                            Expanded(child: Text(_error!, style: const TextStyle(color: AppColors.red, fontSize: 12.5, fontWeight: FontWeight.w600))),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: 28),
                    _gradientButton(label: 'إنشاء الحساب', loading: _loading, onPressed: _submit),
                    const SizedBox(height: 14),
                    OutlinedButton.icon(
                      onPressed: _loading ? null : _openGoogleRegistration,
                      icon: const Icon(Icons.account_circle_outlined),
                      label: const Text('التسجيل باستخدام Google'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppColors.text,
                        side: BorderSide(color: AppColors.border),
                        minimumSize: const Size.fromHeight(52),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text('لديك حساب بالفعل؟', style: TextStyle(color: AppColors.text2, fontSize: 13.5)),
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('تسجيل الدخول', style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _countryPicker() {
    return GestureDetector(
      onTap: _showCountrySheet,
      child: Container(
        height: 58,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(16)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_selectedCountry['f']!, style: const TextStyle(fontSize: 20)),
            const SizedBox(width: 6),
            Text(_selectedCountry['d']!, style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold, fontSize: 14)),
            Icon(Icons.arrow_drop_down_rounded, color: AppColors.text2),
          ],
        ),
      ),
    );
  }

  void _showCountrySheet() {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.bg,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (context) => Column(
        children: [
          const SizedBox(height: 12),
          Container(width: 40, height: 4, decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(2))),
          const SizedBox(height: 20),
          Text('اختر الدولة', style: TextStyle(color: AppColors.text, fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: _countries.length,
              itemBuilder: (context, i) {
                final c = _countries[i];
                final isSelected = _selectedCountry['d'] == c['d'];
                return ListTile(
                  onTap: () {
                    setState(() => _selectedCountry = c);
                    Navigator.pop(context);
                  },
                  leading: Text(c['f']!, style: const TextStyle(fontSize: 24)),
                  title: Text(c['n']!, style: TextStyle(color: AppColors.text, fontSize: 14)),
                  trailing: Text(c['d']!, style: TextStyle(color: AppColors.text2, fontWeight: FontWeight.bold)),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  selected: isSelected,
                  selectedTileColor: AppColors.primary.withOpacity(0.08),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _gradientButton({required String label, required bool loading, required VoidCallback onPressed}) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 16, offset: const Offset(0, 8))],
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
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                  : Text(label, style: const TextStyle(fontSize: 16, color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field({required TextEditingController controller, required String label, required IconData icon, bool obscure = false, bool isPassword = false, TextInputType? keyboardType, VoidCallback? onToggleObscure}) {
    return TextField(
      controller: controller,
      obscureText: obscure,
      keyboardType: keyboardType,
      style: TextStyle(color: AppColors.text, fontSize: 14),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: TextStyle(color: AppColors.text2, fontSize: 13),
        prefixIcon: Icon(icon, color: AppColors.text2, size: 20),
        suffixIcon: isPassword ? IconButton(onPressed: onToggleObscure, icon: Icon(obscure ? Icons.visibility_off_outlined : Icons.visibility_outlined, color: AppColors.text2, size: 20), splashRadius: 20) : null,
        filled: true,
        fillColor: AppColors.card2,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: AppColors.primary, width: 1.5)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      ),
    );
  }
}
