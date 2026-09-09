import 'dart:convert';
import 'dart:io';
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

  static Future<List<dynamic>> getCategories({int? parentId}) async {
    final query = parentId != null ? {'parent_id': '$parentId'} : null;
    final res = await http.get(_u('categories.php', query));
    final data = _parse(res);
    return data['categories'] as List<dynamic>;
  }

  static Future<List<dynamic>> getBanners() async {
    final res = await http.get(_u('banners.php'));
    final data = _parse(res);
    return (data['banners'] as List<dynamic>?) ?? [];
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

  static Future<bool> serviceHasCoupons(int serviceId) async {
    final res = await http.get(_u('coupons.php', {'service_id': '$serviceId'}));
    final data = _parse(res);
    return data['has_coupons'] == true;
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

  static Future<Map<String, dynamic>> getOrderDetail(int orderId) async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('order_detail.php', {'id': '$orderId'}), headers: headers);
    final data = _parse(res);
    return data;
  }

  static Future<void> submitOrderObjection({required int orderId, required String reason}) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('order_detail.php', {'id': '$orderId'}), headers: headers, body: {
      'submit_objection': '1',
      'reason': reason,
    });
    _parse(res);
  }

  // ══════════════════ الإشعارات ══════════════════

  static Future<List<dynamic>> getNotifications({int limit = 50}) async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('notifications.php', {
      'action': 'list',
      'limit': '$limit',
    }), headers: headers);
    final data = _parse(res);
    return (data['notifications'] as List<dynamic>?) ?? [];
  }

  static Future<int> getUnreadNotificationCount() async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('notifications.php', {'action': 'unread_count'}), headers: headers);
    final data = _parse(res);
    return (data['count'] as num?)?.toInt() ?? 0;
  }

  static Future<void> markNotificationRead([int? id]) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('notifications.php'), headers: headers, body: {
      'action': 'mark_read',
      if (id != null) 'id': '$id',
    });
    _parse(res);
  }

  static Future<void> deleteNotification(int id) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('notifications.php'), headers: headers, body: {
      'action': 'delete',
      'id': '$id',
    });
    _parse(res);
  }

  // ══════════════════ الملف الشخصي والأجهزة ══════════════════

  static Future<Map<String, dynamic>> getProfile() async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('profile.php', {'action': 'profile'}), headers: headers);
    final data = _parse(res);
    return data['user'] as Map<String, dynamic>;
  }

  static Future<String> updateProfileName(String name) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('profile.php'), headers: headers, body: {
      'action': 'update_name',
      'full_name': name,
    });
    final data = _parse(res);
    return data['name']?.toString() ?? name;
  }

  static Future<List<dynamic>> getDevices() async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('profile.php', {'action': 'devices'}), headers: headers);
    final data = _parse(res);
    return (data['devices'] as List<dynamic>?) ?? [];
  }

  static Future<void> setDeviceBlocked({required int deviceId, required bool blocked}) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('profile.php'), headers: headers, body: {
      'action': blocked ? 'block_device' : 'unblock_device',
      'device_id': '$deviceId',
    });
    _parse(res);
  }

  // ══════════════════ الدعم والمحادثة ══════════════════

  static Future<Map<String, dynamic>> openSupportChat({bool newChat = false}) async {
    final headers = await _authHeaders(required: true);
    headers['Accept'] = 'application/json';
    final res = await http.post(_u('chat.php'), headers: headers, body: {
      'action': newChat ? 'new_chat' : 'open_chat',
      'subject': 'استفسار من تطبيق نجاز كارد بلاس',
    });
    return _parse(res);
  }

  static Future<List<dynamic>> pollSupportChat({required int chatId, required int afterId}) async {
    final headers = await _authHeaders(required: true);
    final res = await http.get(_u('chat.php', {
      'action': 'poll',
      'chat_id': '$chatId',
      'after_id': '$afterId',
    }), headers: headers);
    final data = _parse(res);
    return (data['messages'] as List<dynamic>?) ?? [];
  }

  static Future<Map<String, dynamic>> sendSupportMessage({required int chatId, required String message}) async {
    final headers = await _authHeaders(required: true);
    final res = await http.post(_u('chat.php'), headers: headers, body: {
      'action': 'send',
      'chat_id': '$chatId',
      'message': message,
    });
    return _parse(res);
  }

  // ══════════════════ المحفظة ══════════════════

  static Future<Map<String, dynamic>> getWallet() async {
    final headers = await _authHeaders();
    final res = await http.get(_u('wallet.php'), headers: headers);
    return _parse(res);
  }

  // ══════════════════ شحن الرصيد — خيارات الصفحة ══════════════════

  static Future<Map<String, dynamic>> getTopupOptions() async {
    final headers = await _authHeaders();
    final res = await http.get(_u('topup_options.php'), headers: headers);
    return _parse(res);
  }

  // ── تحويل يدوي (بإيصال) ──
  static Future<Map<String, dynamic>> submitManualTopup({
    required int methodId,
    required String currencyCode,
    required double amountSent,
    String notes = '',
    File? receiptFile,
  }) async {
    final headers = await _authHeaders();
    final request = http.MultipartRequest('POST', _u('topup_manual.php'))
      ..headers.addAll(headers)
      ..fields['method_id'] = '$methodId'
      ..fields['currency_code'] = currencyCode
      ..fields['amount_sent'] = '$amountSent'
      ..fields['notes'] = notes;
    if (receiptFile != null) {
      request.files.add(await http.MultipartFile.fromPath('receipt', receiptFile.path));
    }
    final streamed = await request.send();
    final res = await http.Response.fromStream(streamed);
    return _parse(res);
  }

  // ── شحن بكود بطاقة ──
  static Future<Map<String, dynamic>> redeemCard(String code) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('card_redeem.php'), headers: headers, body: {'code': code});
    return _parse(res);
  }

  // ── USDT BEP20 (مباشر) ──
  static Future<Map<String, dynamic>> createUsdtRequest(double amount) async {
    final headers = await _authHeaders();
    headers['Content-Type'] = 'application/json';
    final res = await http.post(_u('usdt_request.php'), headers: headers, body: jsonEncode({'amount': amount}));
    final data = _parse(res);
    return data['request'] as Map<String, dynamic>;
  }

  static Future<String> verifyUsdtTx({required int requestId, required String txId}) async {
    final headers = await _authHeaders();
    headers['Content-Type'] = 'application/json';
    final res = await http.post(
      _u('usdt_verify.php'),
      headers: headers,
      body: jsonEncode({'request_id': requestId, 'tx_id': txId}),
    );
    final data = _parse(res);
    return (data['amount'] ?? '0').toString();
  }

  // ── Binance Pay (مباشر) ──
  static Future<Map<String, dynamic>> binanceCreate(String amount) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('binance_deposit.php'), headers: headers, body: {
      'action': 'create',
      'amount': amount,
    });
    return _parse(res);
  }

  static Future<Map<String, dynamic>> binanceVerify({required int requestId, required String transactionId}) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('binance_deposit.php'), headers: headers, body: {
      'action': 'verify',
      'request_id': '$requestId',
      'transaction_id': transactionId,
    });
    return _parse(res);
  }

  static Future<List<dynamic>> binanceHistory() async {
    final headers = await _authHeaders();
    final res = await http.post(_u('binance_deposit.php'), headers: headers, body: {'action': 'list'});
    final data = _parse(res);
    return (data['requests'] as List<dynamic>?) ?? [];
  }

  // ── تحويل عبر شرائح الاتصال (تحقق فوري) ──
  static Future<Map<String, dynamic>> smsVerifyTopup({
    required String phone,
    required double amount,
    required int providerId,
  }) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('sms_verify.php'), headers: headers, body: {
      'phone': phone,
      'amount': '$amount',
      'provider_id': '$providerId',
    });
    return _parse(res);
  }

  // ── محفظة فلوسك (OTP) ──
  static Future<Map<String, dynamic>> floosakInitiate({required double amount, required String phone}) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('floosak.php'), headers: headers, body: {
      'action': 'floosak_initiate',
      'amount': '$amount',
      'phone': phone,
    });
    return _parse(res, successKey: 'status');
  }

  static Future<Map<String, dynamic>> floosakConfirm({required int purchaseId, required String otp}) async {
    final headers = await _authHeaders();
    final res = await http.post(_u('floosak.php'), headers: headers, body: {
      'action': 'floosak_confirm',
      'purchase_id': '$purchaseId',
      'otp': otp,
    });
    return _parse(res, successKey: 'status');
  }
}
