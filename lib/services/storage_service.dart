import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

class StorageService {
  static const _storage = FlutterSecureStorage();

  // ── توكن الدخول ──
  static Future<void> saveToken(String token) =>
      _storage.write(key: 'auth_token', value: token);

  static Future<String?> getToken() => _storage.read(key: 'auth_token');

  static Future<void> deleteToken() => _storage.delete(key: 'auth_token');

  static Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

  // ── معرف الجهاز الثابت (يُولَّد مرة وحدة ويبقى ثابت) ──
  static Future<String> getDeviceId() async {
    String? id = await _storage.read(key: 'device_id');
    if (id == null || id.isEmpty) {
      id = const Uuid().v4();
      await _storage.write(key: 'device_id', value: id);
    }
    return id;
  }

  // ── بيانات المستخدم المحفوظة محلياً (اسم + رصيد) للعرض السريع ──
  static Future<void> saveUser(Map<String, dynamic> user) async {
    await _storage.write(key: 'user_name', value: (user['name'] ?? '').toString());
    await _storage.write(key: 'user_balance', value: (user['balance'] ?? '0').toString());
    await _storage.write(key: 'user_uid', value: (user['uid'] ?? '').toString());
  }

  static Future<Map<String, String>> getUser() async {
    return {
      'name': await _storage.read(key: 'user_name') ?? '',
      'balance': await _storage.read(key: 'user_balance') ?? '0',
      'uid': await _storage.read(key: 'user_uid') ?? '',
    };
  }

  static Future<void> clearAll() async {
    await _storage.deleteAll();
  }
}
