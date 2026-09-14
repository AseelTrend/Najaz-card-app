import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config.dart';
import 'storage_service.dart';
import 'api_service.dart';

class SupportChatService {
  static Uri _u(String path, [Map<String, String>? query]) =>
      Uri.parse('${ApiConfig.baseUrl}/$path').replace(queryParameters: query);

  static Future<Map<String, String>> _headers() async {
    final token = await StorageService.getToken();
    return {
      if (token != null) 'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    };
  }

  static Future<Map<String, dynamic>> _parse(http.Response res) async {
    try {
      final data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
      if (data['ok'] == false) {
        final msg = data['msg'] ?? data['message'] ?? data['error'] ?? 'حدث خطأ غير متوقع';
        throw ApiException(msg.toString(), data);
      }
      return data;
    } catch (e) {
      if (e is ApiException) rethrow;
      throw ApiException('تعذر الاتصال بالسيرفر، حاول لاحقاً');
    }
  }

  static Future<List<dynamic>> getHistory() async {
    final res = await http.get(
      _u('chat.php', {'action': 'user_history'}),
      headers: await _headers(),
    );
    final data = await _parse(res);
    return (data['chats'] as List<dynamic>?) ?? [];
  }

  static Future<List<dynamic>> loadChat(int chatId) async {
    final res = await http.get(
      _u('chat.php', {
        'action': 'load_chat',
        'chat_id': '$chatId',
      }),
      headers: await _headers(),
    );
    final data = await _parse(res);
    return (data['messages'] as List<dynamic>?) ?? [];
  }
}
