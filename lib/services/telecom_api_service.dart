import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';
import 'api_service.dart';

class TelecomApiService {
  static Uri _u(String path, [Map<String, String>? q]) => Uri.parse('${ApiConfig.baseUrl}/$path').replace(queryParameters: q);

  static Future<Map<String, String>> _h() async {
    final t = await StorageService.getToken();
    return {'Accept': 'application/json', if (t != null) 'Authorization': 'Bearer $t'};
  }

  static Map<String, dynamic> _p(http.Response r) {
    try {
      final d = jsonDecode(utf8.decode(r.bodyBytes)) as Map<String, dynamic>;
      if (d['status'] == false || d['ok'] == false) {
        throw ApiException('${d['message'] ?? d['msg'] ?? d['error'] ?? 'حدث خطأ غير متوقع'}', d);
      }
      return d;
    } catch (e) {
      if (e is ApiException) rethrow;
      throw ApiException('تعذر الاتصال بالسيرفر، حاول لاحقاً');
    }
  }

  static Future<Map<String, dynamic>> detectNetwork(String phone) async {
    final r = await http.get(_u('telecom.php', {'action': 'detect_network', 'phone': phone}), headers: await _h());
    return _p(r);
  }

  static Future<List<dynamic>> quickAmounts(int networkId) async {
    final r = await http.get(_u('telecom.php', {'action': 'get_quick_amounts', 'network_id': '$networkId'}), headers: await _h());
    return (_p(r)['amounts'] as List<dynamic>?) ?? [];
  }

  static Future<List<dynamic>> bunches(int networkId) async {
    final r = await http.get(_u('telecom.php', {'action': 'get_bunches', 'network_id': '$networkId'}), headers: await _h());
    return (_p(r)['bunches'] as List<dynamic>?) ?? [];
  }

  static Future<Map<String, dynamic>> checkService({required String phone, required int networkId, String bunchId = '340'}) async {
    final r = await http.post(
      _u('telecom.php'),
      headers: await _h(),
      body: {'action': 'check_service', 'phone': phone, 'network_id': '$networkId', 'bunch_id': bunchId},
    );
    return _p(r);
  }

  static Future<Map<String, dynamic>> topup({required String phone, required int networkId, required String bunchId, required double amountYer, bool withSolfa = false}) async {
    final r = await http.post(
      _u('telecom.php'),
      headers: await _h(),
      body: {
        'action': 'topup',
        'phone': phone,
        'network_id': '$networkId',
        'bunch_id': bunchId,
        'amount': amountYer.toString(),
        'with_solfa': withSolfa ? '1' : '0',
      },
    );
    return _p(r);
  }

  static Future<Map<String, dynamic>> payBalance({required String phone, required int networkId, required double amountYer}) async {
    final r = await http.post(
      _u('telecom.php'),
      headers: await _h(),
      body: {'action': 'pay_balance', 'phone': phone, 'network_id': '$networkId', 'amount': amountYer.toString()},
    );
    return _p(r);
  }
}
