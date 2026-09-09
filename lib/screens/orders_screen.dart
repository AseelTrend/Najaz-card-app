import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import 'order_detail_screen.dart';

class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});
  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  List<dynamic> _orders = [];
  bool _loading = true;
  String? _error;

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
      final orders = await ApiService.getOrders();
      setState(() => _orders = orders);
    } catch (e) {
      setState(() => _error = 'تعذر تحميل الطلبات');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Color _statusColor(String? status) {
    switch (status) {
      case 'completed':
        return AppColors.green;
      case 'pending':
      case 'processing':
        return AppColors.gold;
      case 'rejected':
      case 'cancelled':
      case 'failed':
        return AppColors.red;
      default:
        return AppColors.text2;
    }
  }

  String _statusLabel(String? status) {
    switch (status) {
      case 'completed':
        return 'مكتمل';
      case 'pending':
        return 'قيد الانتظار';
      case 'processing':
        return 'قيد التنفيذ';
      case 'rejected':
        return 'مرفوض';
      case 'cancelled':
        return 'ملغى';
      case 'failed':
        return 'فشل';
      default:
        return status ?? '';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Row(
            children: [
              Text('طلباتي', style: TextStyle(color: AppColors.text, fontSize: 18, fontWeight: FontWeight.bold)),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            color: AppColors.primary,
            backgroundColor: AppColors.card,
            child: _loading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _error != null
                    ? Center(child: Text(_error!, style: const TextStyle(color: AppColors.red)))
                    : _orders.isEmpty
                        ? ListView(
                            children: const [
                              SizedBox(height: 100),
                              Center(
                                child: Text('لا توجد طلبات بعد', style: TextStyle(color: AppColors.text2)),
                              ),
                            ],
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.all(16),
                            itemCount: _orders.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 10),
                            itemBuilder: (context, i) {
                              final o = _orders[i];
                              return InkWell(
                                onTap: () {
                                  final id = (o['id'] as num?)?.toInt();
                                  if (id != null) {
                                    Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: id)));
                                  }
                                },
                                borderRadius: BorderRadius.circular(16),
                                child: Container(
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    color: AppColors.card,
                                    borderRadius: BorderRadius.circular(16),
                                    border: Border.all(color: AppColors.border),
                                  ),
                                  child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text(o['service_name'] ?? '',
                                              style: const TextStyle(
                                                  color: AppColors.text, fontWeight: FontWeight.w600, fontSize: 14)),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                          decoration: BoxDecoration(
                                            color: _statusColor(o['status']).withOpacity(0.15),
                                            borderRadius: BorderRadius.circular(20),
                                          ),
                                          child: Text(_statusLabel(o['status']),
                                              style: TextStyle(color: _statusColor(o['status']), fontSize: 11)),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Row(
                                      children: [
                                        Text('الكمية: ${o['quantity']}',
                                            style: const TextStyle(color: AppColors.text2, fontSize: 12)),
                                        const Spacer(),
                                        Text('\$${o['total_price']}',
                                            style: const TextStyle(
                                                color: AppColors.primary, fontWeight: FontWeight.bold, fontSize: 13)),
                                      ],
                                    ),
                                    if (o['ref_id'] != null) ...[
                                      const SizedBox(height: 4),
                                      Text('رقم الطلب: ${o['ref_id']}',
                                          style: const TextStyle(color: AppColors.text2, fontSize: 11)),
                                    ],
                                  ],
                                  ),
                                ),
                              );
                            },
                          ),
          ),
        ),
      ],
    );
  }
}
