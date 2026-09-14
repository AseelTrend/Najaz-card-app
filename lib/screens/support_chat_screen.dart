import 'dart:async';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/support_chat_service.dart';
import '../theme/app_colors.dart';

class SupportChatScreen extends StatefulWidget {
  const SupportChatScreen({super.key});

  @override
  State<SupportChatScreen> createState() => _SupportChatScreenState();
}

class _SupportChatScreenState extends State<SupportChatScreen> {
  final _inputController = TextEditingController();
  final _scrollController = ScrollController();
  Timer? _pollTimer;
  List<dynamic> _messages = [];
  Map<String, dynamic>? _chat;
  int _lastMessageId = 0;
  bool _loading = true;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _openChat();
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _inputController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _openChat({bool newChat = false}) async {
    _pollTimer?.cancel();
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.openSupportChat(newChat: newChat);
      if (!mounted) return;
      final rawMessages = data['messages'];
      final messages = rawMessages is List ? List<dynamic>.from(rawMessages) : <dynamic>[];
      final rawChat = data['chat'];
      final chat = rawChat is Map ? Map<String, dynamic>.from(rawChat) : null;
      setState(() {
        _chat = chat;
        _messages = messages;
        _lastMessageId = _latestMessageId(messages);
      });
      _startPolling();
      _scrollToBottom();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر فتح المحادثة، حاول مرة أخرى');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  int _latestMessageId(List<dynamic> messages) {
    var latest = 0;
    for (final item in messages) {
      if (item is Map) {
        final id = int.tryParse(item['id']?.toString() ?? '') ?? 0;
        if (id > latest) latest = id;
      }
    }
    return latest;
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
  }

  Future<void> _poll() async {
    final rawChatId = _chat?['id'];
    final chatId = int.tryParse(rawChatId.toString());
    if (chatId == null) return;
    try {
      final messages = await ApiService.pollSupportChat(
        chatId: chatId,
        afterId: _lastMessageId,
      );
      if (!mounted || messages.isEmpty) return;
      final newMessages = <dynamic>[];
      var latestId = _lastMessageId;
      for (final item in messages) {
        if (item is Map) {
          final id = int.tryParse(item['id']?.toString() ?? '') ?? 0;
          if (id <= _lastMessageId) continue;
          newMessages.add(item);
          if (id > latestId) latestId = id;
        }
      }
      if (newMessages.isEmpty) return;
      setState(() {
        _messages.addAll(newMessages);
        _lastMessageId = latestId;
      });
      _scrollToBottom();
    } catch (_) {}
  }

  Future<void> _send() async {
    final text = _inputController.text.trim();
    final rawChatId = _chat?['id'];
    final chatId = int.tryParse(rawChatId.toString());
    if (text.isEmpty || chatId == null || _sending) return;
    setState(() => _sending = true);
    try {
      final data = await ApiService.sendSupportMessage(
        chatId: chatId,
        message: text,
      );
      if (!mounted) return;
      _inputController.clear();
      final message = data['message'];
      final autoReply = data['auto_reply'] == true;

      setState(() {
        if (message is Map) {
          final senderType = message['sender_type']?.toString() ?? '';
          final id = int.tryParse(message['id']?.toString() ?? '') ?? 0;

          // عند وجود رد تلقائي، السيرفر يعيد الرد التلقائي في message
          // وليس رسالة المستخدم؛ لذلك نعرض رسالة المستخدم محلياً أولاً.
          if (senderType != 'user') {
            _messages.add({
              'id': 0,
              'sender_type': 'user',
              'message': text,
              'created_at': DateTime.now().toIso8601String(),
            });
          }

          if (id > _lastMessageId) {
            _messages.add(message);
            _lastMessageId = id;
          }
        } else if (autoReply) {
          _messages.add({
            'id': 0,
            'sender_type': 'user',
            'message': text,
            'created_at': DateTime.now().toIso8601String(),
          });
        }
      });
      _scrollToBottom();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.message)),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('تعذر إرسال الرسالة: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _showHistory() async {
    if (!mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      builder: (sheetContext) {
        return SafeArea(
          child: SizedBox(
            height: MediaQuery.of(sheetContext).size.height * .72,
            child: FutureBuilder<List<dynamic>>(
              future: SupportChatService.getHistory(),
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'تعذر تحميل سجل المحادثات',
                        style: TextStyle(color: AppColors.text2),
                      ),
                    ),
                  );
                }
                final history = snapshot.data ?? [];
                if (history.isEmpty) {
                  return Center(
                    child: Text(
                      'لا توجد محادثات سابقة',
                      style: TextStyle(color: AppColors.text2),
                    ),
                  );
                }
                return Column(
                  children: [
                    const SizedBox(height: 10),
                    Container(
                      width: 42,
                      height: 4,
                      decoration: BoxDecoration(
                        color: AppColors.text2.withOpacity(.35),
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                    const SizedBox(height: 16),
                    const Padding(
                      padding: EdgeInsets.symmetric(horizontal: 18),
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Text(
                          'سجل المحادثات',
                          style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Expanded(
                      child: ListView.separated(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 20),
                        itemCount: history.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final item = history[index] as Map<String, dynamic>;
                          final status = item['status']?.toString() ?? 'pending';
                          final subject = item['subject']?.toString().trim();
                          final lastMessage = item['last_msg']?.toString().trim();
                          final unread = int.tryParse(item['unread_user']?.toString() ?? '0') ?? 0;
                          final id = item['id']?.toString() ?? '';
                          return Material(
                            color: AppColors.card,
                            borderRadius: BorderRadius.circular(16),
                            child: InkWell(
                              borderRadius: BorderRadius.circular(16),
                              onTap: () async {
                                Navigator.of(sheetContext).pop();
                                await _loadHistoryChat(item);
                              },
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Row(
                                  children: [
                                    Container(
                                      width: 44,
                                      height: 44,
                                      decoration: BoxDecoration(
                                        color: AppColors.primary.withOpacity(.12),
                                        borderRadius: BorderRadius.circular(13),
                                      ),
                                      child: const Icon(Icons.support_agent_rounded, color: AppColors.primary),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            (subject?.isNotEmpty == true) ? subject! : 'محادثة الدعم #$id',
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(fontWeight: FontWeight.bold),
                                          ),
                                          const SizedBox(height: 5),
                                          Text(
                                            (lastMessage?.isNotEmpty == true) ? lastMessage! : 'لا توجد رسائل',
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: TextStyle(color: AppColors.text2, fontSize: 12),
                                          ),
                                          const SizedBox(height: 6),
                                          Text(
                                            _statusLabel(status),
                                            style: TextStyle(color: _statusColor(status), fontSize: 11),
                                          ),
                                        ],
                                      ),
                                    ),
                                    if (unread > 0)
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: AppColors.primary,
                                          borderRadius: BorderRadius.circular(20),
                                        ),
                                        child: Text(
                                          '$unread',
                                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                            ),
                          );
                        },
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        );
      },
    );
  }

  Future<void> _loadHistoryChat(Map<String, dynamic> chat) async {
    final rawId = chat['id'];
    final chatId = int.tryParse(rawId.toString());
    if (chatId == null) return;
    _pollTimer?.cancel();
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final messages = await SupportChatService.loadChat(chatId);
      if (!mounted) return;
      setState(() {
        _chat = Map<String, dynamic>.from(chat);
        _messages = messages;
        _lastMessageId = _latestMessageId(messages);
      });
      if ((_chat?['status']?.toString() ?? '') != 'closed') {
        _startPolling();
      }
      _scrollToBottom();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر تحميل المحادثة، حاول مرة أخرى');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _statusLabel(String status) {
    switch (status) {
      case 'open':
        return 'مفتوحة';
      case 'closed':
        return 'مغلقة';
      case 'pending':
        return 'بانتظار الموظف';
      default:
        return status;
    }
  }

  Color _statusColor(String status) {
    switch (status) {
      case 'open':
        return Colors.green;
      case 'closed':
        return AppColors.red;
      default:
        return AppColors.gold;
    }
  }

  String? _assignedStaffName() {
    final assigned = _chat?['assigned_to'];
    if (assigned == null || assigned.toString().isEmpty || assigned.toString() == '0') return null;
    for (final item in _messages.reversed) {
      if (item is Map && item['sender_type']?.toString() == 'staff') {
        final name = item['full_name']?.toString().trim();
        final username = item['username']?.toString().trim();
        if (name?.isNotEmpty == true) return name;
        if (username?.isNotEmpty == true) return username;
      }
    }
    return 'موظف الدعم';
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final closed = (_chat?['status']?.toString() ?? '') == 'closed';
    final staffName = _assignedStaffName();
    return Scaffold(
      appBar: AppBar(
        title: const Text('الدعم الفني'),
        actions: [
          IconButton(
            tooltip: 'سجل المحادثات',
            onPressed: _showHistory,
            icon: const Icon(Icons.history_rounded),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _errorView()
              : Column(
                  children: [
                    if (closed) _closedBanner(),
                    if (staffName != null) _assignedBanner(staffName),
                    Expanded(child: _messagesView()),
                    _inputArea(closed),
                  ],
                ),
    );
  }

  Widget _errorView() => Center(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppColors.red),
              ),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: _openChat,
                child: const Text('إعادة المحاولة'),
              ),
            ],
          ),
        ),
      );

  Widget _assignedBanner(String staffName) => Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        color: AppColors.primary.withOpacity(.08),
        child: Row(
          children: [
            const Icon(Icons.support_agent_rounded, color: AppColors.primary, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'الموظف المسؤول: $staffName',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      );

  Widget _closedBanner() => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        color: AppColors.gold.withOpacity(.12),
        child: Row(
          children: [
            const Icon(Icons.lock_outline_rounded, color: AppColors.gold, size: 18),
            const SizedBox(width: 8),
            const Expanded(
              child: Text(
                'هذه المحادثة مغلقة',
                style: TextStyle(color: AppColors.gold, fontSize: 12),
              ),
            ),
            TextButton(
              onPressed: () => _openChat(newChat: true),
              child: const Text('محادثة جديدة'),
            ),
          ],
        ),
      );

  Widget _messagesView() {
    if (_messages.isEmpty) {
      return Center(
        child: Text(
          'ابدأ محادثتك مع فريق الدعم',
          style: TextStyle(color: AppColors.text2),
        ),
      );
    }
    return ListView.builder(
      controller: _scrollController,
      padding: const EdgeInsets.fromLTRB(14, 18, 14, 18),
      itemCount: _messages.length,
      itemBuilder: (context, index) {
        final message = _messages[index];
        if (message is! Map) return const SizedBox.shrink();
        final senderType = message['sender_type']?.toString() ?? '';
        final isSystem = senderType == 'system';
        final isMine = senderType == 'user';
        final body = message['message']?.toString() ?? '';
        final senderName = message['full_name']?.toString().trim();
        final createdAt = message['created_at']?.toString();

        if (isSystem) {
          return Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            decoration: BoxDecoration(
              color: AppColors.gold.withOpacity(.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Text(
              body,
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.gold, fontSize: 12),
            ),
          );
        }

        return Align(
          alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
          child: Container(
            constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * .82,
            ),
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: isMine ? AppColors.primary : AppColors.card,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              crossAxisAlignment: isMine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
              children: [
                if (!isMine && senderName?.isNotEmpty == true) ...[
                  Text(
                    senderName!,
                    style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 3),
                ],
                Text(body),
                if (createdAt != null && createdAt.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    _formatMessageTime(createdAt),
                    style: TextStyle(
                      fontSize: 9,
                      color: isMine ? Colors.white70 : AppColors.text2,
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  String _formatMessageTime(String value) {
    final dt = DateTime.tryParse(value);
    if (dt == null) return value;
    final local = dt.toLocal();
    final hour = local.hour % 12 == 0 ? 12 : local.hour % 12;
    final minute = local.minute.toString().padLeft(2, '0');
    final period = local.hour >= 12 ? 'م' : 'ص';
    return '$hour:$minute $period';
  }

  Widget _inputArea(bool closed) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 12),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _inputController,
                  enabled: !closed && !_sending,
                  minLines: 1,
                  maxLines: 4,
                  decoration: const InputDecoration(
                    hintText: 'اكتب رسالتك...',
                  ),
                  onSubmitted: (_) => _send(),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                onPressed: closed || _sending ? null : _send,
                icon: _sending
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send_rounded),
              ),
            ],
          ),
        ),
      );
}
