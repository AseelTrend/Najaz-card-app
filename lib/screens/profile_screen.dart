import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';

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

  Future<void> _load() async {
    setState(() {
      _loading = true;
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
    } catch (_) {
      final user = await StorageService.getUser();
      if (!mounted) return;
      setState(() {
        _profile = {'name': user['name'], 'balance': user['balance'], 'uid': user['uid']};
        _error = 'تعذر تحميل بعض بيانات الحساب';
      });
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _editName() async {
    final controller = TextEditingController(text: _profile['name']?.toString() ?? '');
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('تعديل الاسم'),
        content: TextField(controller: controller, autofocus: true, maxLength: 80, decoration: const InputDecoration(labelText: 'الاسم الكامل')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('حفظ')),
        ],
      ),
    );
    controller.dispose();
    if (name == null || name.isEmpty) return;
    try {
      final updatedName = await ApiService.updateProfileName(name);
      await StorageService.saveUser({'name': updatedName, 'balance': _profile['balance'], 'uid': _profile['uid']});
      if (mounted) setState(() => _profile['name'] = updatedName);
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message);
    }
  }

  Future<void> _saveSetting(String key, String value) async {
    await StorageService.saveSetting(key, value);
    if (mounted) _showMessage('تم حفظ الإعداد');
  }

  Future<void> _toggleDevice(dynamic device) async {
    final blocked = device['status']?.toString() == 'blocked';
    try {
      await ApiService.setDeviceBlocked(deviceId: (device['id'] as num).toInt(), blocked: !blocked);
      if (mounted) setState(() => device['status'] = blocked ? 'approved' : 'blocked');
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message);
    }
  }

  void _showMessage(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('حسابي')),
      body: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.primary,
        backgroundColor: AppColors.card,
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                children: [
                  if (_error != null) _messageBox(_error!, AppColors.gold),
                  _profileCard(),
                  const SizedBox(height: 14),
                  _sectionTitle('الإعدادات'),
                  _settingsCard(),
                  const SizedBox(height: 14),
                  _sectionTitle('الأجهزة المصرّحة'),
                  _devicesCard(),
                  const SizedBox(height: 14),
                  OutlinedButton.icon(
                    onPressed: () async {
                      await widget.onLogout();
                    },
                    icon: const Icon(Icons.logout_rounded),
                    label: const Text('تسجيل الخروج'),
                    style: OutlinedButton.styleFrom(foregroundColor: AppColors.red, side: BorderSide(color: AppColors.red.withOpacity(.4)), padding: const EdgeInsets.symmetric(vertical: 14)),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _profileCard() {
    final name = _profile['name']?.toString() ?? '';
    final email = _profile['email']?.toString() ?? '';
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(gradient: AppColors.balanceGradient, borderRadius: BorderRadius.circular(22), boxShadow: [BoxShadow(color: AppColors.accentPurple.withOpacity(.25), blurRadius: 18, offset: const Offset(0, 8))]),
      child: Row(
        children: [
          CircleAvatar(radius: 32, backgroundColor: Colors.white.withOpacity(.18), child: const Icon(Icons.person_rounded, color: Colors.white, size: 34)),
          const SizedBox(width: 14),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(name.isEmpty ? 'مستخدم نجاز' : name, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 17)), if (email.isNotEmpty) Text(email, style: const TextStyle(color: Colors.white70, fontSize: 11)), Text('الرصيد: ${_profile['balance'] ?? '0'}', style: const TextStyle(color: Colors.white70, fontSize: 12))])),
          IconButton(onPressed: _editName, icon: const Icon(Icons.edit_rounded, color: Colors.white)),
        ],
      ),
    );
  }

  Widget _settingsCard() {
    return Container(
      decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
      child: Column(
        children: [
          SwitchListTile.adaptive(title: const Text('الوضع الداكن'), subtitle: const Text('مظهر التطبيق الحالي'), value: _darkMode, onChanged: (value) { setState(() => _darkMode = value); _saveSetting('dark_mode', '$value'); }),
          SwitchListTile.adaptive(title: const Text('الإشعارات'), subtitle: const Text('السماح بتنبيهات التطبيق'), value: _pushEnabled, onChanged: (value) { setState(() => _pushEnabled = value); _saveSetting('push_enabled', '$value'); }),
          ListTile(leading: const Icon(Icons.language_rounded, color: AppColors.cyan), title: const Text('اللغة'), trailing: DropdownButton<String>(value: _language, underline: const SizedBox.shrink(), items: const [DropdownMenuItem(value: 'ar', child: Text('العربية')), DropdownMenuItem(value: 'en', child: Text('English'))], onChanged: (value) { if (value == null) return; setState(() => _language = value); _saveSetting('language', value); })),
          ListTile(leading: const Icon(Icons.schedule_rounded, color: AppColors.gold), title: const Text('المنطقة الزمنية'), trailing: DropdownButton<String>(value: _timezone, underline: const SizedBox.shrink(), items: const [DropdownMenuItem(value: '3', child: Text('UTC+3 اليمن')), DropdownMenuItem(value: '2', child: Text('UTC+2')), DropdownMenuItem(value: '4', child: Text('UTC+4'))], onChanged: (value) { if (value == null) return; setState(() => _timezone = value); _saveSetting('timezone', value); })),
        ],
      ),
    );
  }

  Widget _devicesCard() {
    if (_devices.isEmpty) return _messageBox('لا توجد أجهزة مسجلة', AppColors.text2);
    return Container(
      decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.border)),
      child: Column(children: _devices.map((device) {
        final blocked = device['status']?.toString() == 'blocked';
        final first = device['is_first_device'] == 1 || device['is_first_device'] == '1';
        return ListTile(
          leading: Icon(first ? Icons.phone_android_rounded : Icons.devices_other_rounded, color: blocked ? AppColors.red : AppColors.green),
          title: Text(device['device_name']?.toString().isNotEmpty == true ? device['device_name'] : 'جهاز ${device['device_type'] ?? ''}'),
          subtitle: Text(first ? 'الجهاز الأساسي' : (device['last_seen']?.toString() ?? '')), 
          trailing: first ? const Icon(Icons.verified_rounded, color: AppColors.green, size: 20) : IconButton(onPressed: () => _toggleDevice(device), icon: Icon(blocked ? Icons.lock_open_rounded : Icons.block_rounded, color: blocked ? AppColors.green : AppColors.red)),
        );
      }).toList()),
    );
  }

  Widget _sectionTitle(String title) => Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(title, style: const TextStyle(color: AppColors.text, fontSize: 16, fontWeight: FontWeight.bold)));

  Widget _messageBox(String message, Color color) => Container(padding: const EdgeInsets.all(14), margin: const EdgeInsets.only(bottom: 12), decoration: BoxDecoration(color: color.withOpacity(.1), borderRadius: BorderRadius.circular(14), border: Border.all(color: color.withOpacity(.25))), child: Text(message, style: TextStyle(color: color, fontSize: 12)));
}
