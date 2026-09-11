import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../utils/format.dart';
import 'topup_screen.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});
  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  bool _loading = true;
  String? _error;
  double _balance = 0;
  String _currSymbol = '\$';
  double _totalCredit = 0;
  double _totalDebit = 0;
  List<dynamic> _transactions = [];
  String _filter = 'all'; // all | credit | debit

  static const _positiveTypes = ['credit', 'topup', 'refund', 'prize', 'referral', 'referral_welcome'];

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
      final data = await ApiService.getWallet();
      setState(() {
        _balance = double.tryParse('${data['balance']}') ?? 0;
        _currSymbol = data['currency_symbol']?.toString() ?? '\$';
        _totalCredit = double.tryParse('${data['total_credit']}') ?? 0;
        _totalDebit = double.tryParse('${data['total_debit']}') ?? 0;
        _transactions = data['transactions'] as List<dynamic>? ?? [];
      });
    } catch (e) {
      setState(() => _error = 'تعذر تحميل بيانات المحفظة');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  bool _isPositive(String type) => _positiveTypes.contains(type);

  List<dynamic> get _filteredTx {
    if (_filter == 'all') return _transactions;
    return _transactions.where((t) {
      final positive = _isPositive(t['type']?.toString() ?? '');
      return _filter == 'credit' ? positive : !positive;
    }).toList();
  }

  Future<void> _openTopup([int tabIndex = 0]) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => TopupScreen(initialTab: tabIndex)),
    );
    if (changed == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        backgroundColor: AppColors.bg,
        elevation: 0,
        title: const Text('محفظتي', style: TextStyle(color: AppColors.text)),
        iconTheme: const IconThemeData(color: AppColors.text),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          color: AppColors.primary,
          backgroundColor: AppColors.card,
          child: _loading
              ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Text(_error!, style: const TextStyle(color: AppColors.red), textAlign: TextAlign.center),
                      ),
                    _buildBalanceCard(),
                    const SizedBox(height: 14),
                    Row(
                      children: [
                        Expanded(child: _statCard('إجمالي الإيداعات', _totalCredit, AppColors.green, true)),
                        const SizedBox(width: 10),
                        Expanded(child: _statCard('إجمالي المصروف', _totalDebit, AppColors.red, false)),
                      ],
                    ),
                    const SizedBox(height: 20),
                    Row(
                      children: [
                        const Text('سجل المعاملات',
                            style: TextStyle(color: AppColors.text, fontSize: 15, fontWeight: FontWeight.bold)),
                        const Spacer(),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(color: AppColors.card2, borderRadius: BorderRadius.circular(20)),
                          child: Text('${_transactions.length} عملية',
                              style: const TextStyle(color: AppColors.text2, fontSize: 11)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    if (_transactions.isNotEmpty) _buildFilterRow(),
                    const SizedBox(height: 10),
                    if (_transactions.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 40),
                        child: Center(
                          child: Column(
                            children: [
                              Icon(Icons.swap_horiz_rounded, color: AppColors.text3, size: 40),
                              SizedBox(height: 8),
                              Text('لا توجد معاملات حتى الآن', style: TextStyle(color: AppColors.text2)),
                            ],
                          ),
                        ),
                      )
                    else
                      ..._filteredTx.map(_buildTxItem),
                  ],
                ),
        ),
      ),
    );
  }

  Widget _buildBalanceCard() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: AppColors.balanceGradient,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(color: AppColors.accentPurple.withOpacity(0.35), blurRadius: 20, offset: const Offset(0, 10)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('رصيدي الحالي', style: TextStyle(color: Colors.white70, fontSize: 12)),
          const SizedBox(height: 6),
          Text('${formatMoney(_balance)} $_currSymbol',
              style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(child: _actionBtn('شحن الرصيد', Icons.add_circle_outline_rounded, () => _openTopup(0))),
              const SizedBox(width: 10),
              Expanded(child: _actionBtn('شحن بكود', Icons.confirmation_num_outlined, () => _openTopup(2))),
            ],
          ),
        ],
      ),
    );
  }

  Widget _actionBtn(String label, IconData icon, VoidCallback onTap) {
    return Material(
      color: Colors.white.withOpacity(0.14),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: Column(
            children: [
              Icon(icon, color: Colors.white, size: 18),
              const SizedBox(height: 4),
              Text(label, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _statCard(String label, double value, Color color, bool positive) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(positive ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded, color: color, size: 14),
              const SizedBox(width: 5),
              Expanded(child: Text(label, style: const TextStyle(color: AppColors.text2, fontSize: 11))),
            ],
          ),
          const SizedBox(height: 6),
          Text('${positive ? '+' : '-'}${formatMoney(value)}',
              style: TextStyle(color: color, fontSize: 17, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }

  Widget _buildFilterRow() {
    Widget chip(String key, String label) {
      final active = _filter == key;
      return Padding(
        padding: const EdgeInsets.only(left: 8),
        child: ChoiceChip(
          label: Text(label, style: TextStyle(color: active ? Colors.white : AppColors.text2, fontSize: 12)),
          selected: active,
          onSelected: (_) => setState(() => _filter = key),
          selectedColor: AppColors.primary,
          backgroundColor: AppColors.card2,
          side: BorderSide.none,
        ),
      );
    }

    return Row(children: [chip('all', 'الكل'), chip('credit', 'إيداع'), chip('debit', 'خصم')]);
  }

  Widget _buildTxItem(dynamic t) {
    final type = t['type']?.toString() ?? '';
    final positive = _isPositive(type);
    final color = positive ? AppColors.green : AppColors.red;
    final amount = double.tryParse('${t['amount']}') ?? 0;
    final desc = (t['description']?.toString().isNotEmpty ?? false) ? t['description'].toString() : (positive ? 'إيداع رصيد' : 'خصم رصيد');
    final createdAt = t['created_at']?.toString() ?? '';

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(11)),
            child: Icon(positive ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded, color: color, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(desc,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: AppColors.text, fontSize: 13, fontWeight: FontWeight.w600)),
                const SizedBox(height: 2),
                Text(createdAt, style: const TextStyle(color: AppColors.text3, fontSize: 10)),
              ],
            ),
          ),
          Text('${positive ? '+' : '-'}${formatMoney(amount)}',
              style: TextStyle(color: color, fontSize: 14, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}
