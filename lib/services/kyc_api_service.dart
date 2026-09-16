import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';

class KycApiService {
  static Uri _uri([String? action]) {
    final base = Uri.parse('${ApiConfig.baseUrl}/kyc.php');
    if (action == null || action.isEmpty) return base;
    return base.replace(queryParameters: {'action': action});
  }

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
    final headers = await _headers();

    // السيرفر الحالي يجعل status هو الإجراء الافتراضي عند عدم إرسال action.
    // استخدام الرابط الأساسي هنا يتوافق أيضاً مع النسخ المنشورة التي لا تتعامل
    // مع query parameter في طلب حالة التوثيق.
    var res = await http.get(_uri(), headers: headers);
    var data = _decode(res.bodyBytes);

    // توافق إضافي مع النسخ التي تشترط action=status.
    if (data['ok'] != true && data['msg']?.toString() == 'طلب غير صالح') {
      res = await http.get(_uri('status'), headers: headers);
      data = _decode(res.bodyBytes);
    }

    if (data['ok'] != true) {
      throw Exception(data['msg']?.toString() ?? data['message']?.toString() ?? 'تعذر جلب حالة التحقق');
    }

    final kyc = data['kyc'];
    if (kyc is Map<String, dynamic>) return kyc;

    if (data.containsKey('status')) {
      return {
        'status': data['status'],
        if (data.containsKey('reviewed_at')) 'reviewed_at': data['reviewed_at'],
        if (data.containsKey('full_name')) 'full_name': data['full_name'],
        if (data.containsKey('id_type')) 'id_type': data['id_type'],
      };
    }

    final nestedData = data['data'];
    if (nestedData is Map<String, dynamic>) {
      final nestedKyc = nestedData['kyc'];
      if (nestedKyc is Map<String, dynamic>) return nestedKyc;
      if (nestedData.containsKey('status')) return {'status': nestedData['status']};
    }

    return {};
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
    if (data['ok'] != true) throw Exception(data['msg']?.toString() ?? data['message']?.toString() ?? 'تعذر إرسال طلب التحقق');
    return data;
  }
}
