import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<dynamic> _notifications = [];
  bool _loading = true;
  bool _unreadOnly = false;
  String? _error;

  List<dynamic> get _visibleNotifications => _unreadOnly
      ? _notifications.where((notification) => _isUnread(notification)).toList()
      : _notifications;

  bool _isUnread(dynamic notification) => notification['is_read'] == 0 || notification['is_read'] == '0';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final notifications = await ApiService.getNotifications();
      if (!mounted) return;
      setState(() => _notifications = notifications);
    } catch (_) {
      if (!mounted) return;
      setState(() => _error = 'تعذر تحميل الإشعارات');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _markAllRead() async {
    try {
      await ApiService.markNotificationRead();
      if (!mounted) return;
      setState(() {
        for (final notification in _notifications) {
          notification['is_read'] = 1;
        }
      });
    } catch (_) {}
  }

  Future<void> _markRead(dynamic notification) async {
    final id = (notification['id'] as num?)?.toInt();
    if (id == null || !_isUnread(notification)) return;
    try {
      await ApiService.markNotificationRead(id);
      if (mounted) setState(() => notification['is_read'] = 1);
    } catch (_) {}
  }

  Future<void> _delete(dynamic notification) async {
    final id = (notification['id'] as num?)?.toInt();
    if (id == null) return;
    try {
      await ApiService.deleteNotification(id);
      if (mounted) setState(() => _notifications.remove(notification));
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final unreadCount = _notifications.where(_isUnread).length;
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            const Text('إشعاراتي'),
            if (unreadCount > 0) ...[
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: AppColors.red.withOpacity(0.16), borderRadius: BorderRadius.circular(12)),
                child: Text('$unreadCount', style: const TextStyle(color: AppColors.red, fontSize: 11, fontWeight: FontWeight.bold)),
              ),
            ],
          ],
        ),
        actions: [
          if (unreadCount > 0)
            IconButton(
              tooltip: 'تعليم الكل كمقروء',
              onPressed: _markAllRead,
              icon: const Icon(Icons.done_all_rounded),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.primary,
        backgroundColor: AppColors.card,
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : _error != null
                ? ListView(children: [const SizedBox(height: 130), Center(child: Text(_error!, style: const TextStyle(color: AppColors.red)))])
                : ListView(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
                    children: [
                      _buildFilter(),
                      const SizedBox(height: 12),
                      if (_visibleNotifications.isEmpty)
                        const Padding(
                          padding: EdgeInsets.only(top: 90),
                          child: Column(
                            children: [
                              Icon(Icons.notifications_off_outlined, color: AppColors.text3, size: 48),
                              SizedBox(height: 12),
                              Text('لا توجد إشعارات بعد', style: TextStyle(color: AppColors.text2)),
                            ],
                          ),
                        )
                      else
                        ..._visibleNotifications.map(_buildNotification),
                    ],
                  ),
      ),
    );
  }

  Widget _buildFilter() {
    return Row(
      children: [
        _filterChip('الكل', false),
        const SizedBox(width: 8),
        _filterChip('غير مقروء', true),
      ],
    );
  }

  Widget _filterChip(String label, bool unreadOnly) {
    final selected = _unreadOnly == unreadOnly;
    return ChoiceChip(
      label: Text(label, style: TextStyle(color: selected ? Colors.white : AppColors.text2, fontSize: 12)),
      selected: selected,
      onSelected: (_) => setState(() => _unreadOnly = unreadOnly),
      selectedColor: AppColors.primary,
      backgroundColor: AppColors.card2,
      side: BorderSide.none,
    );
  }

  Widget _buildNotification(dynamic notification) {
    final unread = _isUnread(notification);
    final color = _parseColor(notification['color']?.toString());
    return Dismissible(
      key: ValueKey(notification['id']),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => _delete(notification),
      background: Container(
        margin: const EdgeInsets.only(bottom: 10),
        alignment: Alignment.centerLeft,
        padding: const EdgeInsets.symmetric(horizontal: 18),
        decoration: BoxDecoration(color: AppColors.red.withOpacity(0.18), borderRadius: BorderRadius.circular(16)),
        child: const Icon(Icons.delete_outline_rounded, color: AppColors.red),
      ),
      child: InkWell(
        onTap: () => _markRead(notification),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: unread ? AppColors.card2 : AppColors.card,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: unread ? AppColors.primary.withOpacity(0.35) : AppColors.border),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(13)),
                child: Icon(Icons.notifications_rounded, color: color, size: 21),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(notification['title']?.toString() ?? 'إشعار', style: const TextStyle(color: AppColors.text, fontSize: 14, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 4),
                    Text(notification['message']?.toString() ?? '', style: const TextStyle(color: AppColors.text2, fontSize: 12, height: 1.4)),
                    const SizedBox(height: 7),
                    Text(notification['time_ago']?.toString() ?? notification['created_at']?.toString() ?? '', style: const TextStyle(color: AppColors.text3, fontSize: 10)),
                  ],
                ),
              ),
              if (unread) Container(width: 7, height: 7, margin: const EdgeInsets.only(top: 5), decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle)),
            ],
          ),
        ),
      ),
    );
  }

  Color _parseColor(String? value) {
    if (value == null) return AppColors.primary;
    final hex = value.replaceFirst('#', '');
    final normalized = hex.length == 6 ? 'FF$hex' : hex;
    final parsed = int.tryParse(normalized, radix: 16);
    return parsed == null ? AppColors.primary : Color(parsed);
  }
}
