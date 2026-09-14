import 'dart:convert';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';
import 'api_service.dart';

class GoogleAuthService {
  static final GoogleSignIn _google = GoogleSignIn.instance;
  static bool _initialized = false;

  static Uri _u(String path) => Uri.parse('${ApiConfig.baseUrl}/$path');

  static Future<void> _initialize() async {
    if (_initialized) return;

    final res = await http.get(_u('google_config.php'));
    Map<String, dynamic> data;
    try {
      data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('تعذر قراءة إعدادات تسجيل الدخول عبر Google');
    }

    if (data['ok'] == false) {
      throw ApiException(
        (data['msg'] ?? data['message'] ?? 'تسجيل الدخول عبر Google غير متاح حالياً').toString(),
        data,
      );
    }

    final clientId = (data['client_id'] ?? '').toString().trim();
    if (clientId.isEmpty) {
      throw ApiException('إعداد Google غير مكتمل في السيرفر');
    }

    await _google.initialize(serverClientId: clientId);
    _initialized = true;
  }

  static Future<Map<String, dynamic>> signIn() async {
    await _initialize();

    final account = await _google.authenticate();
    final idToken = account.authentication.idToken;
    if (idToken == null || idToken.isEmpty) {
      throw ApiException('تعذر الحصول على رمز Google، حاول مرة أخرى');
    }

    final deviceId = await StorageService.getDeviceId();
    final res = await http.post(_u('google_auth.php'), body: {
      'action': 'login',
      'id_token': idToken,
      'device_id': deviceId,
    });

    Map<String, dynamic> data;
    try {
      data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('تعذر قراءة رد السيرفر');
    }

    if (data['ok'] == false) {
      throw ApiException(
        (data['msg'] ?? data['message'] ?? 'فشل تسجيل الدخول عبر Google').toString(),
        data,
      );
    }

    if (data['needs_registration'] == true) {
      throw ApiException('لا يوجد حساب مرتبط بهذا البريد الإلكتروني. أنشئ حساباً أولاً.', data);
    }

    final token = data['token']?.toString();
    final user = data['user'];
    if (token == null || token.isEmpty || user is! Map) {
      throw ApiException('السيرفر لم يُرجع بيانات تسجيل الدخول كاملة');
    }

    await StorageService.saveToken(token);
    await StorageService.saveUser(Map<String, dynamic>.from(user));
    return data;
  }
}
