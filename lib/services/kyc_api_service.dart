import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';

class KycApiService {
  static Uri _uri(String action) => Uri.parse('${ApiConfig.baseUrl}/kyc.php').replace(queryParameters: {'action': action});

  static Future<Map<String, String>> _headers() async {
    final token = await StorageService.getToken();
    return {
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
  }

  static Map<String, dynamic> _decode(List<int> bytes) {
    try {
      final data = jsonDecode(utf8.decode(bytes));
      if (data is Map<String, dynamic>) return data;
      throw const FormatException();
    } catch (_) {
      throw Exception('تعذر قراءة رد السيرفر');
    }
  }

  static Future<Map<String, dynamic>> getStatus() async {
    final res = await http.get(_uri('status'), headers: await _headers());
    final data = _decode(res.bodyBytes);
    if (data['ok'] != true) throw Exception(data['msg']?.toString() ?? 'تعذر جلب حالة التحقق');
    return data['kyc'] as Map<String, dynamic>? ?? {};
  }

  static Future<Map<String, dynamic>> submit({
    required String idType,
    required String fullName,
    required String nationalId,
    required String birthDate,
    required String birthPlace,
    required String issueDate,
    required String expiryDate,
    required File imageFront,
    File? imageBack,
  }) async {
    final request = http.MultipartRequest('POST', _uri('submit'));
    request.headers.addAll(await _headers());
    request.fields.addAll({
      'id_type': idType,
      'full_name': fullName,
      'national_id': nationalId,
      'birth_date': birthDate,
      'birth_place': birthPlace,
      'issue_date': issueDate,
      'expiry_date': expiryDate,
    });
    request.files.add(await http.MultipartFile.fromPath('image_front', imageFront.path));
    if (imageBack != null) request.files.add(await http.MultipartFile.fromPath('image_back', imageBack.path));
    final streamed = await request.send();
    final res = await http.Response.fromStream(streamed);
    final data = _decode(res.bodyBytes);
    if (data['ok'] != true) throw Exception(data['msg']?.toString() ?? 'تعذر إرسال طلب التحقق');
    return data;
  }
}
