import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';

class QuizApiService {
  static Uri _uri(String action) => Uri.parse('${ApiConfig.baseUrl}/quiz.php').replace(queryParameters: {'action': action});

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
      throw Exception('تعذر قراءة رد المسابقات');
    }
  }

  static Future<Map<String, dynamic>> getActive() async {
    final res = await http.get(_uri('active'), headers: await _headers());
    final data = _decode(res.bodyBytes);
    if (data['ok'] != true) {
      throw QuizApiException(data['error']?.toString() == 'no_active' ? 'لا توجد مسابقة نشطة حالياً' : (data['message']?.toString() ?? data['error']?.toString() ?? 'تعذر تحميل المسابقة'), data);
    }
    return data;
  }

  static Future<Map<String, dynamic>> submit({
    required int quizId,
    required Map<String, String> answers,
    required int timeTaken,
  }) async {
    final res = await http.post(
      _uri('submit'),
      headers: await _headers(),
      body: {
        'quiz_id': '$quizId',
        'answers': jsonEncode(answers),
        'time_taken': '$timeTaken',
      },
    );
    final data = _decode(res.bodyBytes);
    if (data['ok'] != true) {
      throw QuizApiException(data['message']?.toString() ?? data['error']?.toString() ?? 'تعذر إرسال المشاركة', data);
    }
    return data;
  }
}

class QuizApiException implements Exception {
  final String message;
  final Map<String, dynamic> data;
  QuizApiException(this.message, [this.data = const {}]);
  @override
  String toString() => message;
}
