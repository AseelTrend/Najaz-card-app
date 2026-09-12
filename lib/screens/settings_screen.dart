import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';
import '../theme/app_colors.dart';

// [UI PORT] شاشة إعدادات منفصلة — منقولة من قسم "حسابي": المنطقة الزمنية
// والأجهزة المصرّحة بالدخول، مع إضافة دعم تصريح الأجهزة الجديدة (الحالة
// "بانتظار التصريح") كما بصفحة إعدادات الحساب بالموقع، إلى جانب حظر أي
// جهاز غير أساسي كالسابق.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  List<dynamic> _devices = [];
  bool _loading = true;
  bool _refreshing = false;
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
      final devices = await ApiService.getDevices();
      final timezone = await StorageService.getSetting('timezone');
      if (!mounted) return;
      setState(() {
        _devices = devices;
        _timezone = timezone ?? '3';
      });
      if (manual && mounted) _showMessage('تم تحديث قائمة الأجهزة بنجاح', AppColors.green);
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر تحميل الأجهزة المصرّحة من السيرفر');
    } finally {
      if (mounted) setState(() { _loading = false; _refreshing = false; });
    }
  }

  Future<void> _saveTimezone(String value) async {
    await StorageService.saveSetting('timezone', value);
    if (mounted) _showMessage('تم حفظ المنطقة الزمنية', AppColors.primary);
  }

  // action: 'approve' يُصرّح الجهاز (يشمل الأجهزة المعلّقة الجديدة)،
  // 'block' يحظر جهازاً غير أساسي.
  Future<void> _setDeviceStatus(dynamic device, String action) async {
    try {
      await ApiService.setDeviceBlocked(deviceId: (device['id'] as num).toInt(), blocked: action == 'block');
      final name = device['device_name']?.toString() ?? '';
      if (mounted) {
        setState(() => device['status'] = action == 'block' ? 'blocked' : 'approved');
        _showMessage(
          action == 'block' ? 'تم حظر جهاز $name' : 'تم تصريح جهاز $name بنجاح',
          action == 'block' ? AppColors.gold : AppColors.green,
        );
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('الإعدادات'),
        actions: [
          IconButton(
            tooltip: 'تحديث الأجهزة',
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
                  if (_error != null) _messageBox(_error!),
                  _sectionTitle('المنطقة الزمنية'),
                  _timezoneCard(),
                  const SizedBox(height: 18),
                  _devicesSectionHeader(),
                  _devicesCard(),
                ],
              ),
      ),
    );
  }

  Widget _timezoneCard() {
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(width: 38, height: 38, alignment: Alignment.center, decoration: BoxDecoration(color: AppColors.gold.withOpacity(.15), borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.schedule_rounded, color: AppColors.gold, size: 18)),
          const SizedBox(width: 12),
          const Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('توقيت التطبيق', style: TextStyle(color: AppColors.text, fontSize: 12.5, fontWeight: FontWeight.bold)),
              SizedBox(height: 2),
              Text('توقيت تسجيل الحركات وسجل الطلبات', style: TextStyle(color: AppColors.text2, fontSize: 10.5)),
            ]),
          ),
          const SizedBox(width: 8),
          DropdownButton<String>(
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
              _saveTimezone(value);
            },
          ),
        ]),
      ),
    );
  }

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
    if (_devices.isEmpty) return _messageBox('لا توجد أجهزة مسجلة', color: AppColors.text2);
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.border)),
      padding: const EdgeInsets.all(10),
      child: Column(
        children: _devices.map((device) {
          final status = device['status']?.toString() ?? 'approved';
          final blocked = status == 'blocked';
          final pending = status == 'pending';
          final first = device['is_first_device'] == 1 || device['is_first_device'] == '1' || device['is_first_device'] == true;
          final type = device['device_type']?.toString() ?? '';
          final icon = type == 'mobile' ? Icons.smartphone_rounded : (type == 'tablet' ? Icons.tablet_mac_rounded : Icons.desktop_windows_rounded);
          final iconBg = blocked ? AppColors.red : (pending ? AppColors.gold : (first ? AppColors.green : AppColors.primary));
          final deviceName = device['device_name']?.toString();
          final subtitleParts = [
            (device['browser']?.toString().isNotEmpty == true ? device['browser'].toString() : device['os']?.toString()) ?? 'تطبيق نجاز',
            'آخر ظهور: ${device['last_seen']?.toString().isNotEmpty == true ? device['last_seen'] : 'غير محدد'}',
          ];
          return Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: blocked ? AppColors.red.withOpacity(.08) : (pending ? AppColors.gold.withOpacity(.08) : AppColors.card.withOpacity(.6)),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: blocked ? AppColors.red.withOpacity(.25) : (pending ? AppColors.gold.withOpacity(.3) : AppColors.border)),
            ),
            child: Column(children: [
              Row(children: [
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
                      if (pending)
                        Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2), decoration: BoxDecoration(color: AppColors.gold.withOpacity(.2), borderRadius: BorderRadius.circular(20)), child: const Text('بانتظار التصريح', style: TextStyle(color: AppColors.gold, fontSize: 9, fontWeight: FontWeight.bold))),
                    ]),
                    const SizedBox(height: 3),
                    Text(subtitleParts.join('  •  '), style: const TextStyle(color: AppColors.text2, fontSize: 9.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                  ]),
                ),
                if (first)
                  const Padding(padding: EdgeInsets.all(6), child: Icon(Icons.verified_user_rounded, color: AppColors.green, size: 18))
                else if (!pending)
                  TextButton.icon(
                    onPressed: () => _setDeviceStatus(device, blocked ? 'approve' : 'block'),
                    icon: Icon(blocked ? Icons.lock_open_rounded : Icons.block_rounded, size: 14, color: blocked ? AppColors.green : AppColors.red),
                    label: Text(blocked ? 'فك الحظر' : 'حظر', style: TextStyle(color: blocked ? AppColors.green : AppColors.red, fontSize: 11, fontWeight: FontWeight.bold)),
                    style: TextButton.styleFrom(backgroundColor: (blocked ? AppColors.green : AppColors.red).withOpacity(.12), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                  ),
              ]),
              // [UI PORT] تصريح جهاز جديد: الأجهزة "بانتظار التصريح" تحصل على
              // زرَي تصريح/رفض بدل زر الحظر الواحد — كما بصفحة إعدادات الحساب
              // بالموقع عند دخول جهاز غير معروف لأول مرة.
              if (pending)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Row(children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: () => _setDeviceStatus(device, 'block'),
                        icon: const Icon(Icons.close_rounded, size: 15, color: AppColors.red),
                        label: const Text('رفض وحظر', style: TextStyle(color: AppColors.red, fontSize: 11.5, fontWeight: FontWeight.bold)),
                        style: OutlinedButton.styleFrom(side: BorderSide(color: AppColors.red.withOpacity(.4)), padding: const EdgeInsets.symmetric(vertical: 9)),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: () => _setDeviceStatus(device, 'approve'),
                        icon: const Icon(Icons.check_rounded, size: 15),
                        label: const Text('تصريح الجهاز', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold)),
                        style: FilledButton.styleFrom(backgroundColor: AppColors.green, padding: const EdgeInsets.symmetric(vertical: 9)),
                      ),
                    ),
                  ]),
                ),
            ]),
          );
        }).toList(),
      ),
    );
  }

  Widget _sectionTitle(String title, {bool padded = true}) => Padding(
        padding: padded ? const EdgeInsets.only(bottom: 8, right: 2) : EdgeInsets.zero,
        child: Text(title, style: const TextStyle(color: AppColors.text2, fontSize: 11.5, fontWeight: FontWeight.bold, letterSpacing: 0.3)),
      );

  Widget _messageBox(String message, {Color color = AppColors.gold}) => Container(
        padding: const EdgeInsets.all(14),
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(color: color.withOpacity(.1), borderRadius: BorderRadius.circular(14), border: Border.all(color: color.withOpacity(.25))),
        child: Text(message, style: TextStyle(color: color, fontSize: 12)),
      );
}
