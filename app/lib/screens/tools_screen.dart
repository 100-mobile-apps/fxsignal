import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../state/data_stores.dart';
import '../widgets/common.dart';

const _fallbackPrice = {
  'USD/JPY': 150.0,
  'USD/CAD': 1.36,
  'USD/CHF': 0.88,
};

/// USD value of one pip for one standard lot, for a USD account.
double pipValuePerLot(String pair, double? price) {
  if (pair.startsWith('XAU')) return 10; // 100 oz × 0.10
  if (pair.startsWith('BTC')) return 1; // 1 coin × \$1
  if (pair.startsWith('ETH')) return 0.1; // 1 coin × \$0.10
  if (pair.endsWith('/USD')) return 10; // 100,000 × 0.0001
  final p = price ?? _fallbackPrice[pair] ?? 1;
  return 100000 * Fmt.pipSize(pair) / p; // USD/XXX pairs
}

class ToolsScreen extends StatefulWidget {
  const ToolsScreen({super.key});

  @override
  State<ToolsScreen> createState() => _ToolsScreenState();
}

class _ToolsScreenState extends State<ToolsScreen> {
  int _tool = 0;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 12, 20, 4),
            child: Text('Tools', style: AppText.h1),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: Text('Size every trade so one loss never hurts', style: AppText.caption),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                for (final (i, label, icon) in [
                  (0, 'Position size', Icons.pie_chart_outline_rounded),
                  (1, 'Pip value', Icons.attach_money_rounded),
                ])
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(right: i == 0 ? 8 : 0),
                      child: GlassCard(
                        radius: 14,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        borderColor: _tool == i ? AppColors.gold : null,
                        onTap: () => setState(() => _tool = i),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(icon, size: 18, color: _tool == i ? AppColors.gold : AppColors.text3),
                            const SizedBox(width: 8),
                            Text(label,
                                style: TextStyle(
                                    fontWeight: FontWeight.w600,
                                    color: _tool == i ? AppColors.text : AppColors.text2)),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: _tool == 0 ? const LotSizeCalculator() : const PipValueCalculator(),
          ),
        ],
      ),
    );
  }
}

class _NumField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final String? suffix;
  final IconData icon;
  const _NumField(
      {required this.controller, required this.label, required this.icon, this.suffix});

  @override
  Widget build(BuildContext context) => TextField(
        controller: controller,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
        style: AppText.mono(16),
        decoration: InputDecoration(
          labelText: label,
          suffixText: suffix,
          prefixIcon: Icon(icon, color: AppColors.text3, size: 20),
        ),
      );
}

class _PairPicker extends StatelessWidget {
  final String value;
  final ValueChanged<String> onChanged;
  const _PairPicker({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) => DropdownButtonFormField<String>(
        value: value,
        dropdownColor: AppColors.surface2,
        borderRadius: BorderRadius.circular(14),
        decoration: const InputDecoration(
          labelText: 'Market',
          prefixIcon: Icon(Icons.currency_exchange_rounded, color: AppColors.text3, size: 20),
        ),
        items: [
          for (final p in AppConfig.pairs)
            DropdownMenuItem(
              value: p,
              child: Row(children: [PairAvatar(pair: p, size: 24), const SizedBox(width: 10), Text(p)]),
            ),
        ],
        onChanged: (v) => onChanged(v ?? value),
      );
}

class _ResultRow extends StatelessWidget {
  final String label, value;
  final Color color;
  final bool big;
  const _ResultRow(this.label, this.value, {this.color = AppColors.text, this.big = false});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Text(label, style: const TextStyle(color: AppColors.text2)),
            const Spacer(),
            Text(value, style: AppText.mono(big ? 26 : 16, weight: FontWeight.w800, color: color)),
          ],
        ),
      );
}

class LotSizeCalculator extends StatefulWidget {
  final String? initialPair;
  final double? initialStopPips;
  const LotSizeCalculator({super.key, this.initialPair, this.initialStopPips});

  @override
  State<LotSizeCalculator> createState() => _LotSizeCalculatorState();
}

class _LotSizeCalculatorState extends State<LotSizeCalculator> {
  final _balance = TextEditingController(text: '1000');
  final _risk = TextEditingController(text: '1');
  late final _stop =
      TextEditingController(text: (widget.initialStopPips ?? 30).toStringAsFixed(1));
  late String _pair = widget.initialPair ?? 'EUR/USD';

  @override
  void initState() {
    super.initState();
    for (final c in [_balance, _risk, _stop]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    _balance.dispose();
    _risk.dispose();
    _stop.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final price = context.watch<MarketStore>().quote(_pair)?.price;
    final balance = double.tryParse(_balance.text) ?? 0;
    final riskPct = double.tryParse(_risk.text) ?? 0;
    final stop = double.tryParse(_stop.text) ?? 0;
    final riskUsd = balance * riskPct / 100;
    final pv = pipValuePerLot(_pair, price);
    final lots = stop > 0 ? riskUsd / (stop * pv) : 0.0;
    final highRisk = riskPct > 2;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
      children: [
        _PairPicker(value: _pair, onChanged: (v) => setState(() => _pair = v)),
        const SizedBox(height: 12),
        _NumField(controller: _balance, label: 'Account balance', suffix: 'USD', icon: Icons.account_balance_wallet_outlined),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: _NumField(controller: _risk, label: 'Risk', suffix: '%', icon: Icons.percent_rounded)),
          const SizedBox(width: 12),
          Expanded(child: _NumField(controller: _stop, label: 'Stop loss', suffix: 'pips', icon: Icons.block_rounded)),
        ]),
        const SizedBox(height: 18),
        GlassCard(
          borderColor: AppColors.gold.withValues(alpha: 0.3),
          padding: const EdgeInsets.all(18),
          child: Column(
            children: [
              _ResultRow('Position size', '${lots.toStringAsFixed(2)} lots', color: AppColors.gold, big: true),
              const Divider(height: 20),
              _ResultRow('Amount at risk', Fmt.money(riskUsd), color: AppColors.sell),
              _ResultRow('Pip value (1 lot)', Fmt.money(pv)),
              _ResultRow('Pip value (your size)', Fmt.money(pv * lots)),
              _ResultRow('Units', (lots * (_pair.startsWith('XAU') ? 100 : 100000)).toStringAsFixed(0)),
            ],
          ),
        ),
        const SizedBox(height: 14),
        InfoBanner(
          icon: highRisk ? Icons.warning_amber_rounded : Icons.tips_and_updates_outlined,
          color: highRisk ? AppColors.warn : AppColors.info,
          text: highRisk
              ? 'Risking more than 2% per trade can drain an account quickly during a losing streak.'
              : 'Most professionals risk 0.5–2% of their account per trade. Round the lot size down to what your broker allows.',
        ),
      ],
    );
  }
}

class PipValueCalculator extends StatefulWidget {
  const PipValueCalculator({super.key});

  @override
  State<PipValueCalculator> createState() => _PipValueCalculatorState();
}

class _PipValueCalculatorState extends State<PipValueCalculator> {
  final _lots = TextEditingController(text: '0.10');
  final _pips = TextEditingController(text: '20');
  String _pair = 'EUR/USD';

  @override
  void initState() {
    super.initState();
    _lots.addListener(() => setState(() {}));
    _pips.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _lots.dispose();
    _pips.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final price = context.watch<MarketStore>().quote(_pair)?.price;
    final lots = double.tryParse(_lots.text) ?? 0;
    final pips = double.tryParse(_pips.text) ?? 0;
    final pv = pipValuePerLot(_pair, price) * lots;
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
      children: [
        _PairPicker(value: _pair, onChanged: (v) => setState(() => _pair = v)),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: _NumField(controller: _lots, label: 'Lot size', suffix: 'lots', icon: Icons.layers_outlined)),
          const SizedBox(width: 12),
          Expanded(child: _NumField(controller: _pips, label: 'Move', suffix: 'pips', icon: Icons.swap_vert_rounded)),
        ]),
        const SizedBox(height: 18),
        GlassCard(
          borderColor: AppColors.gold.withValues(alpha: 0.3),
          padding: const EdgeInsets.all(18),
          child: Column(
            children: [
              _ResultRow('Value per pip', Fmt.money(pv), color: AppColors.gold, big: true),
              const Divider(height: 20),
              _ResultRow('Profit / loss for ${pips.toStringAsFixed(0)} pips', Fmt.money(pv * pips)),
              _ResultRow('1 pip on $_pair', Fmt.pipSize(_pair).toString()),
            ],
          ),
        ),
        const SizedBox(height: 14),
        const InfoBanner(
          icon: Icons.info_outline_rounded,
          text: 'Values are for a USD account and use the latest price. Your broker\'s contract size may differ.',
        ),
      ],
    );
  }
}
