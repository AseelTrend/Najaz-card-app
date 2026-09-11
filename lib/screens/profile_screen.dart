import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';
import 'topup_screen.dart';
import 'wallet_screen.dart';

// [UI PORT] مطابق لتصميم ProfileView.tsx المرجعي: بطاقة حساب متدرّجة مع
// شارات (UID/الدور) وأزرار إجراءات سريعة (شحن/كشف حساب)، قسم إعدادات
// بأيقونات ملوّنة، قسم أجهزة مصرّحة أغنى، وقسم معلومات ودعم فني (واتساب
// + إصدار التطبيق)، بالإضافة لتأكيد قبل تسجيل الخروج.
class ProfileScreen extends StatefulWidget {
  final Future<void> Function() onLogout;
  const ProfileScreen({super.key, required this.onLogout});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Map<String, dynamic> _profile = {};
  List<dynamic> _devices = [];
  bool _loading = true;
  bool _refreshing = false;
  bool _darkMode = true;
  bool _pushEnabled = true;
  String _language = 'ar';
  String _timezone = '3';
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool manual = false}) async {
    setState(() {
      if (manual) {
        _refreshing = true;
      } else {
        _loading = true;
      }
      _error = null;
    });
    try {
      final values = await Future.wait([ApiService.getProfile(), ApiService.getDevices()]);
      final darkMode = await StorageService.getSetting('dark_mode');
      final pushEnabled = await StorageService.getSetting('push_enabled');
      final language = await StorageService.getSetting('language');
      final timezone = await StorageService.getSetting('timezone');
      if (!mounted) return;
      setState(() {
        _profile = values[0] as Map<String, dynamic>;
        _devices = values[1] as List<dynamic>;
        _darkMode = darkMode != 'false';
        _pushEnabled = pushEnabled != 'false';
        _language = language ?? 'ar';
        _timezone = timezone ?? '3';
      });
      if (manual && mounted) _showMessage('تم تحديث بيانات الحساب والأجهزة بنجاح', AppColors.green);
    } catch (_) {
      final user = await StorageService.getUser();
      if (!mounted) return;
      setState(() {
        _profile = {'name': user['name'], 'balance': user['balance'], 'uid': user['uid']};
        _error = 'تعذر تحميل بعض بيانات الحساب من السيرفر';
      });
    } finally {
      if (mounted) setState(() { _loading = false; _refreshing = false; });
    }
  }

  Future<void> _editName() async {
    final controller = TextEditingController(text: _profile['name']?.toString() ?? '');
    String? errorText;
    final name = await showDialog<String>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          backgroundColor: AppColors.bg2,
          title: const Row(children: [Icon(Icons.edit_rounded, color: AppColors.primary, size: 18), SizedBox(width: 8), Text('تعديل الاسم الكامل')]),
          content: TextField(
            controller: controller,
            autofocus: true,
            maxLength: 80,
            decoration: InputDecoration(
              labelText: 'الاسم المعروض في الحساب والطلبات',
              errorText: errorText,
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('إلغاء')),
            FilledButton.icon(
              icon: const Icon(Icons.save_rounded, size: 16),
              label: const Text('حفظ التعديل'),
              onPressed: () {
                final trimmed = controller.text.trim();
                if (trimmed.length < 2) {
                  setDialogState(() => errorText = 'الاسم قصير جداً (أقل من حرفين)');
                  return;
                }
                Navigator.pop(context, trimmed);
              },
            ),
          ],
        ),
      ),
    );
    controller.dispose();
    if (name == null || name.isEmpty) return;
    try {
      final updatedName = await ApiService.updateProfileName(name);
      await StorageService.saveUser({'name': updatedName, 'balance': _profile['balance'], 'uid': _profile['uid']});
      if (mounted) {
        setState(() => _profile['name'] = updatedName);
        _showMessage('تم تحديث اسم الحساب بنجاح', AppColors.green);
      }
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message, AppColors.red);
    }
  }

  Future<void> _saveSetting(String key, String value, String successMessage) async {
    await StorageService.saveSetting(key, value);
    if (mounted) _showMessage(successMessage, AppColors.primary);
  }

  Future<void> _toggleDevice(dynamic device) async {
    final blocked = device['status']?.toString() == 'blocked';
    try {
      await ApiService.setDeviceBlocked(deviceId: (device['id'] as num).toInt(), blocked: !blocked);
      final name = device['device_name']?.toString() ?? '';
      if (mounted) {
        setState(() => device['status'] = blocked ? 'approved' : 'blocked');
        _showMessage(blocked ? 'تم إلغاء حظر جهاز $name' : 'تم حظر جهاز $name', blocked ? AppColors.green : AppColors.gold);
      }
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message, AppColors.red);
    }
  }

  void _showMessage(String message, Color color) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(message, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      backgroundColor: color.withOpacity(0.95),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ));
  }

  Future<void> _openUrl(String url, String failMessage) async {
    final uri = Uri.parse(url);
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && mounted) {
      _showMessage(failMessage, AppColors.red);
    }
  }

  Future<void> _confirmLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: AppColors.bg2,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(color: AppColors.red.withOpacity(0.15), borderRadius: BorderRadius.circular(16)),
              child: const Icon(Icons.logout_rounded, color: AppColors.red, size: 24),
            ),
            const SizedBox(height: 14),
            const Text('هل أنت متأكد من تسجيل الخروج؟', style: TextStyle(color: AppColors.text, fontWeight: FontWeight.bold, fontSize: 14), textAlign: TextAlign.center),
            const SizedBox(height: 6),
            const Text('سيتعين عليك تسجيل الدخول مجدداً للوصول إلى محفظتك وسجل الطلبات.', style: TextStyle(color: AppColors.text2, fontSize: 12), textAlign: TextAlign.center),
          ],
        ),
        actionsAlignment: MainAxisAlignment.center,
        actions: [
          Expanded(child: OutlinedButton(onPressed: () => Navigator.pop(context, false), child: const Text('تراجع'))),
          const SizedBox(width: 8),
          Expanded(
            child: FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AppColors.red),
              onPressed: () => Navigator.pop(context, true),
              child: const Text('تأكيد الخروج'),
            ),
          ),
        ],
      ),
    );
    if (confirmed == true) await widget.onLogout();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('حسابي والملف الشخصي'),
        actions: [
          IconButton(
            tooltip: 'تحديث البيانات',
            onPressed: _refreshing ? null : () => _load(manual: true),
            icon: _refreshing
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary))
                : const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(manual: true),
        color: AppColors.primary,
        backgroundColor: AppColors.card,
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                children: [
                  if (_error != null) _messageBox(_error!, AppColors.gold),
                  _profileCard(),
                  const SizedBox(height: 18),
                  _sectionTitle('إعدادات التطبيق والتفضيلات'),
                  _settingsCard(),
                  const SizedBox(height: 18),
                  _devicesSectionHeader(),
                  _devicesCard(),
                  const SizedBox(height: 18),
                  _sectionTitle('المعلومات والدعم الفني'),
                  _infoCard(),
                  const SizedBox(height: 20),
                  OutlinedButton.icon(
                    onPressed: _confirmLogout,
                    icon: const Icon(Icons.logout_rounded),
                    label: const Text('تسجيل الخروج من الحساب'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.red,
                      side: BorderSide(color: AppColors.red.withOpacity(.35)),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  // ── 1. بطاقة الحساب الرئيسية ─────────────────────────────────────────────
  Widget _profileCard() {
    final name = _profile['name']?.toString() ?? '';
    final email = _profile['email']?.toString() ?? '';
    final uid = _profile['uid']?.toString() ?? '';
    final role = _profile['role']?.toString() ?? '';
    final balance = _profile['balance']?.toString() ?? '0';
    final initial = name.isNotEmpty ? name.substring(0, 1).toUpperCase() : 'ن';

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Colors.white.withOpacity(0.1)),
        boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(.25), blurRadius: 20, offset: const Offset(0, 10))],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 56,
                height: 56,
                alignment: Alignment.center,
                decoration: BoxDecoration(color: Colors.white.withOpacity(.2), borderRadius: BorderRadius.circular(18), border: Border.all(color: Colors.white.withOpacity(.25))),
                child: Text(initial, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 22)),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Flexible(child: Text(name.isEmpty ? 'مستخدم نجاز' : name, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16))),
                      const SizedBox(width: 6),
                      InkWell(
                        onTap: _editName,
                        borderRadius: BorderRadius.circular(8),
                        child: Container(padding: const EdgeInsets.all(5), decoration: BoxDecoration(color: Colors.white.withOpacity(.15), borderRadius: BorderRadius.circular(8)), child: const Icon(Icons.edit_rounded, color: Colors.white, size: 13)),
                      ),
                    ]),
                    if (email.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(email, style: const TextStyle(color: Colors.white70, fontSize: 11))),
                    Padding(
                      padding: const EdgeInsets.only(top: 5),
                      child: Wrap(spacing: 6, runSpacing: 4, children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(color: Colors.black.withOpacity(.25), borderRadius: BorderRadius.circular(20)),
                          child: Text('معرّف الحساب: ${uid.isEmpty ? '—' : uid}', style: const TextStyle(color: Colors.white70, fontSize: 9.5)),
                        ),
                        if (role.isNotEmpty)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(color: AppColors.green.withOpacity(.3), borderRadius: BorderRadius.circular(20)),
                            child: Text(role, style: const TextStyle(color: Colors.white, fontSize: 9.5, fontWeight: FontWeight.bold)),
                          ),
                      ]),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Container(height: 1, color: Colors.white.withOpacity(.15)),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('الرصيد المتاح الحالي:', style: TextStyle(color: Colors.white70, fontSize: 10.5)),
                    const SizedBox(height: 2),
                    Text(balance, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 21)),
                  ],
                ),
              ),
              ElevatedButton.icon(
                onPressed: () async {
                  await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const TopupScreen(initialTab: 0)));
                  _load();
                },
                icon: const Icon(Icons.credit_card_rounded, size: 15),
                label: const Text('شحن رصيد'),
                style: ElevatedButton.styleFrom(backgroundColor: Colors.white, foregroundColor: AppColors.primaryDark, elevation: 0, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10), textStyle: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const WalletScreen())),
                icon: const Icon(Icons.account_balance_wallet_rounded, size: 15, color: Colors.white),
                label: const Text('كشف الحساب', style: TextStyle(color: Colors.white)),
                style: OutlinedButton.styleFrom(side: BorderSide(color: Colors.white.withOpacity(.3)), backgroundColor: Colors.black.withOpacity(.2), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10), textStyle: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ── 2. قسم الإعدادات ──────────────────────────────────────────────────────
  Widget _settingsCard() {
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      child: Column(children: [
        _settingRow(
          icon: _darkMode ? Icons.dark_mode_rounded : Icons.light_mode_rounded,
          iconColor: AppColors.primary,
          title: 'الوضع الليلي (الداكن)',
          subtitle: 'مظهر التطبيق الليلي المريح للعين',
          trailing: Switch.adaptive(
            value: _darkMode,
            activeColor: AppColors.primary,
            onChanged: (v) {
              setState(() => _darkMode = v);
              _saveSetting('dark_mode', '$v', v ? 'تم تفعيل الوضع الداكن' : 'تم تفعيل الوضع الفاتح');
            },
          ),
        ),
        _divider(),
        _settingRow(
          icon: Icons.notifications_active_rounded,
          iconColor: AppColors.green,
          title: 'إشعارات التطبيق',
          subtitle: 'تنبيهات اكتمال الطلبات وعمليات الشحن',
          trailing: Switch.adaptive(
            value: _pushEnabled,
            activeColor: AppColors.green,
            onChanged: (v) {
              setState(() => _pushEnabled = v);
              _saveSetting('push_enabled', '$v', v ? 'تم تفعيل إشعارات التطبيق' : 'تم تعطيل إشعارات التطبيق');
            },
          ),
        ),
        _divider(),
        _settingRow(
          icon: Icons.language_rounded,
          iconColor: AppColors.cyan,
          title: 'لغة الواجهة',
          subtitle: 'اختر لغة العرض الأساسية',
          trailing: DropdownButton<String>(
            value: _language,
            underline: const SizedBox.shrink(),
            dropdownColor: AppColors.card2,
            style: const TextStyle(color: AppColors.text, fontSize: 12, fontWeight: FontWeight.bold),
            items: const [DropdownMenuItem(value: 'ar', child: Text('العربية')), DropdownMenuItem(value: 'en', child: Text('English'))],
            onChanged: (value) {
              if (value == null) return;
              setState(() => _language = value);
              _saveSetting('language', value, value == 'ar' ? 'تم اختيار اللغة العربية' : 'Language set to English');
            },
          ),
        ),
        _divider(),
        _settingRow(
          icon: Icons.schedule_rounded,
          iconColor: AppColors.gold,
          title: 'المنطقة الزمنية',
          subtitle: 'توقيت تسجيل الحركات وسجل الطلبات',
          trailing: DropdownButton<String>(
            value: _timezone,
            underline: const SizedBox.shrink(),
            dropdownColor: AppColors.card2,
            style: const TextStyle(color: AppColors.text, fontSize: 12, fontWeight: FontWeight.bold),
            items: const [
              DropdownMenuItem(value: '3', child: Text('UTC+3 اليمن')),
              DropdownMenuItem(value: '2', child: Text('UTC+2 مصر/الشام')),
              DropdownMenuItem(value: '4', child: Text('UTC+4 الإمارات')),
              DropdownMenuItem(value: '0', child: Text('UTC+0 غرينتش')),
            ],
            onChanged: (value) {
              if (value == null) return;
              setState(() => _timezone = value);
              _saveSetting('timezone', value, 'تم حفظ المنطقة الزمنية');
            },
          ),
        ),
      ]),
    );
  }

  Widget _settingRow({required IconData icon, required Color iconColor, required String title, required String subtitle, required Widget trailing}) {
    return Padding(
      padding: const EdgeInsets.all(14),
      child: Row(children: [
        Container(width: 38, height: 38, alignment: Alignment.center, decoration: BoxDecoration(color: iconColor.withOpacity(.15), borderRadius: BorderRadius.circular(12)), child: Icon(icon, color: iconColor, size: 18)),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Text(subtitle, style: const TextStyle(color: AppColors.text2, fontSize: 10.5)),
          ]),
        ),
        const SizedBox(width: 8),
        trailing,
      ]),
    );
  }

  // ── 3. قسم الأجهزة المصرّحة ───────────────────────────────────────────────
  Widget _devicesSectionHeader() {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        _sectionTitle('الأجهزة المصرّحة والدخول', padded: false),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
          decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
          child: Text('${_devices.length} أجهزة مسجلة', style: const TextStyle(color: AppColors.text2, fontSize: 10)),
        ),
      ]),
    );
  }

  Widget _devicesCard() {
    if (_devices.isEmpty) return _messageBox('لا توجد أجهزة مسجلة', AppColors.text2);
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      padding: const EdgeInsets.all(10),
      child: Column(
        children: _devices.map((device) {
          final blocked = device['status']?.toString() == 'blocked';
          final first = device['is_first_device'] == 1 || device['is_first_device'] == '1' || device['is_first_device'] == true;
          final type = device['device_type']?.toString() ?? '';
          final icon = type == 'mobile' ? Icons.smartphone_rounded : (type == 'tablet' ? Icons.tablet_mac_rounded : Icons.desktop_windows_rounded);
          final iconBg = blocked ? AppColors.red : (first ? AppColors.green : AppColors.primary);
          final deviceName = device['device_name']?.toString();
          final subtitleParts = [
            (device['browser']?.toString().isNotEmpty == true ? device['browser'].toString() : device['os']?.toString()) ?? 'تطبيق نجاز',
            'آخر ظهور: ${device['last_seen']?.toString().isNotEmpty == true ? device['last_seen'] : 'غير محدد'}',
          ];
          return Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: blocked ? AppColors.red.withOpacity(.08) : AppColors.card.withOpacity(.6),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: blocked ? AppColors.red.withOpacity(.25) : AppColors.border),
            ),
            child: Row(children: [
              Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: iconBg.withOpacity(.18), borderRadius: BorderRadius.circular(12)), child: Icon(icon, color: iconBg, size: 19)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    Text(deviceName?.isNotEmpty == true ? deviceName! : 'جهاز ${type.isEmpty ? 'مجهول' : type}', style: const TextStyle(color: AppColors.text, fontSize: 12, fontWeight: FontWeight.bold)),
                    if (first)
                      Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2), decoration: BoxDecoration(color: AppColors.green.withOpacity(.18), borderRadius: BorderRadius.circular(20)), child: const Text('الجهاز الأساسي', style: TextStyle(color: AppColors.green, fontSize: 9, fontWeight: FontWeight.bold))),
                    if (blocked)
                      Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2), decoration: BoxDecoration(color: AppColors.red.withOpacity(.18), borderRadius: BorderRadius.circular(20)), child: const Text('محظور', style: TextStyle(color: AppColors.red, fontSize: 9, fontWeight: FontWeight.bold))),
                  ]),
                  const SizedBox(height: 3),
                  Text(subtitleParts.join('  •  '), style: const TextStyle(color: AppColors.text2, fontSize: 9.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                ]),
              ),
              first
                  ? const Padding(padding: EdgeInsets.all(6), child: Icon(Icons.verified_user_rounded, color: AppColors.green, size: 18))
                  : TextButton.icon(
                      onPressed: () => _toggleDevice(device),
                      icon: Icon(blocked ? Icons.lock_open_rounded : Icons.block_rounded, size: 14, color: blocked ? AppColors.green : AppColors.red),
                      label: Text(blocked ? 'فك الحظر' : 'حظر', style: TextStyle(color: blocked ? AppColors.green : AppColors.red, fontSize: 11, fontWeight: FontWeight.bold)),
                      style: TextButton.styleFrom(backgroundColor: (blocked ? AppColors.green : AppColors.red).withOpacity(.12), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                    ),
            ]),
          );
        }).toList(),
      ),
    );
  }

  // ── 4. قسم المعلومات والدعم الفني ─────────────────────────────────────────
  Widget _infoCard() {
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      child: Column(children: [
        _infoRow(
          icon: Icons.privacy_tip_rounded,
          iconColor: AppColors.cyan,
          title: 'سياسة الخصوصية',
          subtitle: 'تعرّف على كيفية حماية بياناتك وأمان معاملاتك',
          trailing: const Icon(Icons.open_in_new_rounded, color: AppColors.text2, size: 17),
          onTap: () => _openUrl('https://njaz.net/page.php?slug=privacy', 'تعذر فتح سياسة الخصوصية'),
        ),
        _divider(),
        _infoRow(
          icon: Icons.support_agent_rounded,
          iconColor: const Color(0xFF25D366),
          title: 'الدعم الفني المباشر',
          subtitle: 'تواصل معنا على مدار الساعة عبر واتساب',
          trailing: const Icon(Icons.open_in_new_rounded, color: AppColors.text2, size: 17),
          onTap: () => _openUrl('https://wa.me/967775199244', 'تعذر فتح واتساب'),
        ),
        _divider(),
        Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(width: 38, height: 38, alignment: Alignment.center, decoration: BoxDecoration(color: AppColors.primary.withOpacity(.15), borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.info_rounded, color: AppColors.primary, size: 18)),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('إصدار تطبيق نجاز كارد', style: TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.bold)),
                SizedBox(height: 2),
                Text('الإصدار 1.0.0 • مرتبط بالسيرفر الحي njaz.net', style: TextStyle(color: AppColors.text2, fontSize: 10.5)),
              ]),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
              decoration: BoxDecoration(color: AppColors.green.withOpacity(.15), borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.green.withOpacity(.3))),
              child: const Text('متصل', style: TextStyle(color: AppColors.green, fontSize: 10, fontWeight: FontWeight.bold)),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _infoRow({required IconData icon, required Color iconColor, required String title, required String subtitle, required Widget trailing, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(width: 38, height: 38, alignment: Alignment.center, decoration: BoxDecoration(color: iconColor.withOpacity(.15), borderRadius: BorderRadius.circular(12)), child: Icon(icon, color: iconColor, size: 18)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: const TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.bold)),
              const SizedBox(height: 2),
              Text(subtitle, style: const TextStyle(color: AppColors.text2, fontSize: 10.5)),
            ]),
          ),
          trailing,
        ]),
      ),
    );
  }

  Widget _divider() => Divider(height: 1, color: AppColors.border, indent: 14, endIndent: 14);

  Widget _sectionTitle(String title, {bool padded = true}) => Padding(
        padding: padded ? const EdgeInsets.only(bottom: 8, right: 2) : EdgeInsets.zero,
        child: Text(title, style: const TextStyle(color: AppColors.text2, fontSize: 11.5, fontWeight: FontWeight.bold, letterSpacing: 0.3)),
      );

  Widget _messageBox(String message, Color color) => Container(
        padding: const EdgeInsets.all(14),
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(color: color.withOpacity(.1), borderRadius: BorderRadius.circular(14), border: Border.all(color: color.withOpacity(.25))),
        child: Text(message, style: TextStyle(color: color, fontSize: 12)),
      );
}
