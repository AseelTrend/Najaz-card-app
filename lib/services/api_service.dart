import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';

class ApiException implements Exception {
  final String message;
  // بيانات إضافية أرجعها السيرفر مع الفشل، مثل need_2fa أو device_pending
  final Map<String, dynamic> data;
  ApiException(this.message, [this.data = const {}]);
  @override
  String toString() => message;
}

class ApiService {
  static Uri _u(String path, [Map<String, String>? query]) =>
      Uri.parse('${ApiConfig.baseUrl}/$path').replace(queryParameters: query);

  static Future<Map<String, String>> _authHeaders({bool required = false}) async {
    final token = await StorageService.getToken();
    final headers = <String, String>{};
    if (token != null) headers['Authorization'] = 'Bearer $token';
    return headers;
  }

  /// يقرأ رد السيرفر ويتحقق من النجاح.
  /// successKey: بعض ملفات الموقع الأصلية ترجع 'ok' وبعضها 'status' —
  /// القيمة false فقط تعتبر فشل، أي قيمة أخرى (true أو نص) تعتبر نجاح،
  /// لأن ملف الطلبات الأصلي يستبدل 'status' بحالة الطلب النصية عند النجاح.
  static Map<String, dynamic> _parse(http.Response res, {String successKey = 'ok'}) {
    Map<String, dynamic> data;
    try {
      data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    } catch (e) {
      throw ApiException('تعذر الاتصال بالسيرفر، حاول لاحقاً');
    }
    final raw = data[successKey];
    final isSuccess = raw != false;
    if (!isSuccess) {
      final msg = data['msg'] ?? data['message'] ?? data['error'] ?? 'حدث خطأ غير متوقع';
      throw ApiException(msg.toString(), data);
    }
    return data;
  }

  // ══════════════════ Auth ══════════════════

  static Future<Map<String, dynamic>> login({
    required String login,
    required String password,
    String? totpCode,
  }) async {
    final deviceId = await StorageService.getDeviceId();
    final res = await http.post(_u('login.php'), body: {
      'login': login,
      'password': password,
      'device_id': deviceId,
      if (totpCode != null && totpCode.isNotEmpty) 'totp_code': totpCode,
    });
    final data = _parse(res);
    await StorageService.saveToken(data['token']);
    await StorageService.saveUser(data['user']);
    return data;
  }

  static Future<Map<String, dynamic>> register({
    required String username,
    required String email,
    required String password,
    required String password2,
    String fullName = '',
    String phone = '',
    String referralCode = '',
  }) async {
    final deviceId = await StorageService.getDeviceId();
    final res = await http.post(_u('register.php'), body: {
      'username': username,
      'email': email,
      'password': password,
      'password2': password2,
      'full_name': fullName,
      'phone': phone,
      'referral_code': referralCode,
      'device_id': deviceId,
    });
    final data = _parse(res);
    await StorageService.saveToken(data['token']);
    await StorageService.saveUser(data['user']);
    return data;
  }

  static Future<void> logout() => StorageService.deleteToken();

  // ══════════════════ Categories & Services ══════════════════

  static Future<List<dynamic>> getCategories() async {
    final res = await http.get(_u('categories.php'));
    final data = _parse(res);
    return data['categories'] as List<dynamic>;
  }

  static Future<List<dynamic>> getServices({int? categoryId}) async {
    final headers = await _authHeaders();
    final res = await http.get(
      _u('services.php', categoryId != null ? {'category_id': '$categoryId'} : null),
      headers: headers,
    );
    final data = _parse(res);
    return data['services'] as List<dynamic>;
  }

  static Future<Map<String, dynamic>> getServiceDetail(int id) async {
    final headers = await _authHeaders();
    final res = await http.get(_u('service_detail.php', {'id': '$id'}), headers: headers);
    final data = _parse(res);
    return data['service'] as Map<String, dynamic>;
  }

  // ══════════════════ Orders ══════════════════

  static Future<Map<String, dynamic>> placeOrder({
    required int serviceId,
    required int quantity,
    required Map<String, String> fields,
    String couponCode = '',
  }) async {
    final headers = await _authHeaders();
    headers['Content-Type'] = 'application/json';
    final res = await http.post(
      _u('place_order.php'),
      headers: headers,
      body: jsonEncode({
        'service_id': serviceId,
        'quantity': quantity,
        'fields': fields,
        'coupon_code': couponCode,
      }),
    );
    // الملف الأصلي يرجع مفتاح status (قد يكون نص حالة الطلب عند النجاح)
    return _parse(res, successKey: 'status');
  }

  static Future<List<dynamic>> getOrders({int page = 1}) async {
    final headers = await _authHeaders();
    final res = await http.get(_u('orders.php', {'page': '$page'}), headers: headers);
    final data = _parse(res);
    return data['orders'] as List<dynamic>;
  }
}
