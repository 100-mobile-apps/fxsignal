import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../data/api.dart';
import '../models/gold_test.dart';
import '../widgets/common.dart';

/// The gold live test: real signals on gold, judged against pass marks fixed before it began.
class GoldTestScreen extends StatefulWidget {
  const GoldTestScreen({super.key});

  @override
  State<GoldTestScreen> createState() => _GoldTestScreenState();
}

class _GoldTestScreenState extends State<GoldTestScreen> {
  GoldTestData? _data;
  String? _error;
  bool _loading = true;

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
      final d = await context.read<Api>().goldTest();
      if (mounted) setState(() => _data = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Couldn\'t load the test. Pull down to try again.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return Scaffold(
      appBar: AppBar(title: const Text('Gold live test')),
      body: RefreshIndicator(
        color: AppColors.gold,
        backgroundColor: AppColors.surface,
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
          children: [
            const Text('Real signals on gold, tracked automatically. Signals are free until the test passes.',
                style: AppText.caption),
            const SizedBox(height: 14),
            if (_error != null) InfoBanner(icon: Icons.error_outline, color: AppColors.sell, text: _error!),
            if (d == null && _loading) const Padding(padding: EdgeInsets.all(24), child: LinearProgressIndicator()),
            if (d != null) ...[
              _StatusCard(d: d),
              const SizedBox(height: 18),
              const SectionHeader('Pass marks, fixed before the test'),
              GlassCard(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                child: Column(
                  children: [
                    for (var i = 0; i < d.checks.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      _CheckRow(c: d.checks[i], riskPct: d.riskPct),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 18),
              const SectionHeader('Strategies in the test'),
              for (final s in d.strategies) ...[
                GlassCard(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  child: Row(
                    children: [
                      Expanded(child: Text('${s.name} · gold · 4-hour', style: const TextStyle(fontWeight: FontWeight.w600))),
                      Text(
                        !s.live && s.closed == 0
                            ? 'Starts soon'
                            : '${s.closed} closed${s.running > 0 ? ' · ${s.running} open' : ''}',
                        style: AppText.caption,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 8),
              ],
              if (d.recent.isNotEmpty) ...[
                const SizedBox(height: 10),
                const SectionHeader('Latest closed trades'),
                GlassCard(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                  child: Column(
                    children: [
                      for (var i = 0; i < d.recent.length; i++) ...[
                        if (i > 0) const Divider(height: 1),
                        _TradeRow(t: d.recent[i], strategies: d.strategies),
                      ],
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 14),
              Text(_verdict(d), style: AppText.caption),
              const SizedBox(height: 6),
              Text(
                'Figures use ${d.riskPct.toStringAsFixed(0)}% risk per trade on ${Fmt.money(d.account).replaceAll('.00', '')}, '
                'two half trades per signal and typical trading costs. Past results do not guarantee future results.',
                style: AppText.label,
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _verdict(GoldTestData d) {
    switch (d.status) {
      case 'passed':
        return 'Passed. The signals met every pass mark over at least ${d.minDays} days and ${d.minTrades} trades.';
      case 'failed':
        return 'Failed. The signals did not meet the pass marks, so they stay free and we keep working on them.';
      default:
        if (d.trades < 10) return 'Too early to judge. A handful of trades says nothing yet.';
        return 'Still running. The verdict comes after ${d.minDays} days and ${d.minTrades} trades.';
    }
  }
}

class _StatusCard extends StatelessWidget {
  final GoldTestData d;
  const _StatusCard({required this.d});

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (d.status) {
      'passed' => ('Passed', AppColors.buy),
      'failed' => ('Failed', AppColors.sell),
      _ => ('Running', AppColors.gold),
    };
    final share = d.minTrades > 0 ? (d.trades / d.minTrades).clamp(0.0, 1.0) : 0.0;
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(999)),
                child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
              ),
              const Spacer(),
              Text('Day ${d.day} of ${d.minDays}', style: AppText.caption),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              const Expanded(child: Text('Closed trades', style: AppText.caption)),
              Text('${d.trades} of ${d.minTrades}', style: const TextStyle(fontWeight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(3),
            child: LinearProgressIndicator(
              value: share,
              minHeight: 6,
              backgroundColor: AppColors.border,
              color: AppColors.gold,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Ends ${Fmt.date(d.earliestEnd)} or at ${d.minTrades} trades, whichever comes later.'
            '${d.running > 0 ? ' ${d.running} trade${d.running == 1 ? '' : 's'} open now.' : ''}',
            style: AppText.label,
          ),
        ],
      ),
    );
  }
}

class _CheckRow extends StatelessWidget {
  final GoldCheck c;
  final double riskPct;
  const _CheckRow({required this.c, required this.riskPct});

  @override
  Widget build(BuildContext context) {
    final String value;
    if (c.value == null) {
      value = c.key == 'profit_factor' ? 'No losses yet' : '—';
    } else if (c.unit == '%') {
      value = '${c.key == 'profit' && c.value! > 0 ? '+' : ''}${c.value!.toStringAsFixed(1)}%';
    } else {
      value = c.value!.toStringAsFixed(2);
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(c.label, style: const TextStyle(fontWeight: FontWeight.w600)),
                Text('Needs: ${c.need}', style: AppText.caption),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(value, style: TextStyle(fontWeight: FontWeight.w800, color: c.ok ? AppColors.buy : AppColors.sell)),
              if (c.pips != null) Text('${Fmt.pips(c.pips)} pips', style: AppText.caption),
            ],
          ),
        ],
      ),
    );
  }
}

class _TradeRow extends StatelessWidget {
  final GoldTrade t;
  final List<GoldStrategy> strategies;
  const _TradeRow({required this.t, required this.strategies});

  @override
  Widget build(BuildContext context) {
    final name = strategies.where((s) => s.key == t.strategy).map((s) => s.name).firstOrNull ?? t.strategy;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${t.direction} · $name', style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(Fmt.dateTime(t.closedAt), style: AppText.caption),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text('${Fmt.pips(t.pips)} pips',
                  style: TextStyle(fontWeight: FontWeight.w700, color: t.pips >= 0 ? AppColors.buy : AppColors.sell)),
              Text(Fmt.money(t.usd), style: AppText.caption),
            ],
          ),
        ],
      ),
    );
  }
}
