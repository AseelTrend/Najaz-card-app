import 'dart:async';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
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
      final messages = (data['messages'] as List<dynamic>?) ?? [];
      setState(() {
        _chat = data['chat'] as Map<String, dynamic>?;
        _messages = messages;
        _lastMessageId = _latestMessageId(messages);
      });
      _startPolling();
      _scrollToBottom();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (e, stack) {
      if (mounted) {
        setState(() => _error = 'خطأ: $e\n\n$stack');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  int _latestMessageId(List<dynamic> messages) {
    if (messages.isEmpty) return 0;
    return (messages.last['id'] as num?)?.toInt() ?? 0;
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
  }

  Future<void> _poll() async {
    final chatId = (_chat?['id'] as num?)?.toInt();
    if (chatId == null) return;
    try {
      final messages = await ApiService.pollSupportChat(
        chatId: chatId,
        afterId: _lastMessageId,
      );
      if (!mounted || messages.isEmpty) return;
      setState(() {
        _messages.addAll(messages);
        _lastMessageId = _latestMessageId(_messages);
      });
      _scrollToBottom();
    } catch (_) {}
  }

  Future<void> _send() async {
    final text = _inputController.text.trim();
    final chatId = (_chat?['id'] as num?)?.toInt();
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
      final autoReply = data['auto_reply'];
      setState(() {
        if (message != null) _messages.add(message);
        if (autoReply != null) _messages.add(autoReply);
        _lastMessageId = _latestMessageId(_messages);
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
    return Scaffold(
      appBar: AppBar(title: const Text('الدعم الفني')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _errorView()
              : Column(
                  children: [
                    if (closed) _closedBanner(),
                    Expanded(child: _messagesView()),
                    _inputArea(closed),
                  ],
                ),
    );
  }

  Widget _errorView() => Center(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  _error!,
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
        final isMine = message['sender_type']?.toString() == 'user';
        final body = message['message']?.toString() ?? '';
        return Align(
          alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
          child: Container(
            constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * .82,
            ),
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: isMine ? AppColors.primary : AppColors.surface,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(body),
          ),
        );
      },
    );
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
              IconButton(
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
