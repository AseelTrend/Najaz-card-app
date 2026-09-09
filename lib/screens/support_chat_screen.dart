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
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر فتح محادثة الدعم');
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
    _pollTimer = Timer.periodic(const Duration(seconds: 5), (_) => _poll());
  }

  Future<void> _poll() async {
    final chatId = (_chat?['id'] as num?)?.toInt();
    if (chatId == null) return;
    try {
      final messages = await ApiService.pollSupportChat(chatId: chatId, afterId: _lastMessageId);
      if (!mounted || messages.isEmpty) return;
      setState(() {
        _messages.addAll(messages);
        _lastMessageId = _latestMessageId(messages);
      });
      _scrollToBottom();
    } catch (_) {}
  }

  Future<void> _send() async {
    final message = _inputController.text.trim();
    final chatId = (_chat?['id'] as num?)?.toInt();
    if (message.isEmpty || chatId == null || _sending || _chat?['status'] == 'closed') return;
    _inputController.clear();
    setState(() => _sending = true);
    try {
      await ApiService.sendSupportMessage(chatId: chatId, message: message);
      await _poll();
    } on ApiException catch (e) {
      if (mounted) _showMessage(e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) _scrollController.animateTo(_scrollController.position.maxScrollExtent, duration: const Duration(milliseconds: 220), curve: Curves.easeOut);
    });
  }

  void _showMessage(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  @override
  Widget build(BuildContext context) {
    final closed = _chat?['status'] == 'closed';
    return Scaffold(
      appBar: AppBar(title: const Text('الدعم والمحادثة'), actions: [IconButton(onPressed: () => _openChat(), icon: const Icon(Icons.refresh_rounded))]),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : _error != null
              ? _errorView()
              : Column(children: [
                  if (closed) _closedBanner(),
                  Expanded(child: _messagesView()),
                  _inputArea(closed),
                ]),
    );
  }

  Widget _errorView() => Center(child: Column(mainAxisSize: MainAxisSize.min, children: [Text(_error!, style: const TextStyle(color: AppColors.red)), const SizedBox(height: 12), FilledButton(onPressed: _openChat, child: const Text('إعادة المحاولة'))]));

  Widget _closedBanner() => Container(width: double.infinity, padding: const EdgeInsets.all(12), color: AppColors.gold.withOpacity(.12), child: Row(children: [const Icon(Icons.lock_outline_rounded, color: AppColors.gold, size: 18), const SizedBox(width: 8), const Expanded(child: Text('هذه المحادثة مغلقة', style: TextStyle(color: AppColors.gold, fontSize: 12))), TextButton(onPressed: () => _openChat(newChat: true), child: const Text('محادثة جديدة'))]));

  Widget _messagesView() {
    if (_messages.isEmpty) return const Center(child: Text('ابدأ محادثتك مع فريق الدعم', style: TextStyle(color: AppColors.text2)));
    return ListView.builder(
      controller: _scrollController,
      padding: const EdgeInsets.fromLTRB(14, 18, 14, 18),
      itemCount: _messages.length,
      itemBuilder: (context, index) {
        final message = _messages[index];
        final outgoing = message['sender_type'] == 'user';
        final system = message['sender_type'] == 'system';
        final color = outgoing ? AppColors.primary : AppColors.card2;
        return Align(
          alignment: outgoing ? Alignment.centerLeft : Alignment.centerRight,
          child: Container(
            constraints: const BoxConstraints(maxWidth: 320),
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
            decoration: BoxDecoration(color: system ? AppColors.gold.withOpacity(.12) : color, borderRadius: BorderRadius.circular(16), border: system ? Border.all(color: AppColors.gold.withOpacity(.25)) : null),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [if (!outgoing) Text(system ? 'النظام' : 'فريق الدعم', style: TextStyle(color: system ? AppColors.gold : AppColors.cyan, fontSize: 10, fontWeight: FontWeight.bold)), Text(message['message']?.toString() ?? '', style: TextStyle(color: system ? AppColors.gold : Colors.white, fontSize: 13, height: 1.4)), const SizedBox(height: 4), Text(message['created_at']?.toString() ?? '', style: TextStyle(color: outgoing ? Colors.white70 : AppColors.text3, fontSize: 9))]),
          ),
        );
      },
    );
  }

  Widget _inputArea(bool closed) {
    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
        decoration: BoxDecoration(color: AppColors.card, border: Border(top: BorderSide(color: AppColors.border))),
        child: Row(children: [Expanded(child: TextField(controller: _inputController, enabled: !closed && !_sending, minLines: 1, maxLines: 4, textInputAction: TextInputAction.newline, decoration: InputDecoration(hintText: closed ? 'المحادثة مغلقة' : 'اكتب رسالتك...', prefixIcon: const Icon(Icons.chat_bubble_outline_rounded)))), const SizedBox(width: 8), IconButton.filled(onPressed: closed || _sending ? null : _send, icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send_rounded))]),
      ),
    );
  }
}
