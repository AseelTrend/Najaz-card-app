import 'package:flutter/material.dart';
import '../services/api_service.dart';

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
        return const Color(0xFF34D399);
      case 'pending':
      case 'processing':
        return const Color(0xFFFBBF24);
      case 'rejected':
      case 'cancelled':
      case 'failed':
        return const Color(0xFFF87171);
      default:
        return const Color(0xFF7C93B5);
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
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Row(
            children: const [
              Text('طلباتي', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            color: const Color(0xFF3B82F6),
            backgroundColor: const Color(0xFF0E1525),
            child: _loading
                ? const Center(child: CircularProgressIndicator(color: Color(0xFF3B82F6)))
                : _error != null
                    ? Center(child: Text(_error!, style: const TextStyle(color: Color(0xFFF87171))))
                    : _orders.isEmpty
                        ? ListView(
                            children: const [
                              SizedBox(height: 100),
                              Center(
                                child: Text('لا توجد طلبات بعد', style: TextStyle(color: Color(0xFF7C93B5))),
                              ),
                            ],
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.all(16),
                            itemCount: _orders.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 10),
                            itemBuilder: (context, i) {
                              final o = _orders[i];
                              return Container(
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: const Color(0xFF0E1525),
                                  borderRadius: BorderRadius.circular(14),
                                  border: Border.all(color: Colors.white.withOpacity(0.06)),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text(o['service_name'] ?? '',
                                              style: const TextStyle(
                                                  color: Colors.white, fontWeight: FontWeight.w600, fontSize: 14)),
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
                                            style: const TextStyle(color: Color(0xFF7C93B5), fontSize: 12)),
                                        const Spacer(),
                                        Text('\$${o['total_price']}',
                                            style: const TextStyle(
                                                color: Color(0xFF3B82F6), fontWeight: FontWeight.bold, fontSize: 13)),
                                      ],
                                    ),
                                    if (o['ref_id'] != null) ...[
                                      const SizedBox(height: 4),
                                      Text('رقم الطلب: ${o['ref_id']}',
                                          style: const TextStyle(color: Color(0xFF7C93B5), fontSize: 11)),
                                    ],
                                  ],
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
