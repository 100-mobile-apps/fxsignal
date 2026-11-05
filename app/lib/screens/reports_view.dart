import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../data/api.dart';
import '../models/models.dart';
import '../widgets/common.dart';
import '../widgets/signal_card.dart';
import 'signals_screen.dart';

/// Daily / weekly / monthly performance reports (inside the Results tab).
class ReportsView extends StatefulWidget {
  const ReportsView({super.key});

  @override
  State<ReportsView> createState() => _ReportsViewState();
}

class _ReportsViewState extends State<ReportsView> {
  String _period = 'daily';
  final Map<String, ReportsData> _cache = {};
  String? _error;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool force = false}) async {
    if (!force && _cache.containsKey(_period)) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    final period = _period;
    try {
      final data = await context.read<Api>().reports(period);
      if (mounted) setState(() => _cache[period] = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _cache[_period];
    return RefreshIndicator(
      color: AppColors.gold,
      onRefresh: () => _load(force: true),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
        children: [
          if (data != null) ...[
            _SummaryStrip(summary: data.summary),
            const SizedBox(height: 18),
          ],
          Row(
            children: [
              for (final (p, label) in [('daily', 'Daily'), ('weekly', 'Weekly'), ('monthly', 'Monthly')])
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 3),
                    child: GestureDetector(
                      onTap: () {
                        setState(() => _period = p);
                        _load();
                      },
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: _period == p ? AppColors.gold : AppColors.surface,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: _period == p ? AppColors.gold : AppColors.border),
                        ),
                        child: Text(label,
                            style: TextStyle(
                                fontWeight: FontWeight.w700,
                                color: _period == p ? AppColors.bg : AppColors.text2)),
                      ),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 16),
          if (data == null && _loading) ...[
            const Skeleton(height: 90),
            const SizedBox(height: 10),
            const Skeleton(height: 90),
          ] else if (data == null)
            EmptyState(
              icon: Icons.cloud_off_rounded,
              title: 'Couldn\'t load reports',
              message: _error ?? 'Pull down to try again.',
            )
          else ...[
            for (final r in data.items)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _ReportRowCard(row: r, period: _period),
              ),
            const SizedBox(height: 6),
            Text(
              'Periods use ${data.timezone} time. Daily reports cover trading days (Mon–Fri). '
              'Results count signals closed in the period; "new" counts signals published.',
              textAlign: TextAlign.center,
              style: AppText.label,
            ),
          ],
        ],
      ),
    );
  }
}

class _SummaryStrip extends StatelessWidget {
  final ReportSummary summary;
  const _SummaryStrip({required this.summary});

  @override
  Widget build(BuildContext context) {
    Widget tile(String label, ReportRow r) => Expanded(
          child: GlassCard(
            radius: 16,
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: AppText.label),
                const SizedBox(height: 6),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(Fmt.pips(r.pips),
                      style: AppText.mono(18,
                          weight: FontWeight.w800,
                          color: r.pips >= 0 ? AppColors.buy : AppColors.sell)),
                ),
                const SizedBox(height: 2),
                Text('${r.wins}W · ${r.losses}L', style: AppText.caption),
              ],
            ),
          ),
        );
    return Column(
      children: [
        Row(children: [
          tile('Today', summary.today),
          const SizedBox(width: 8),
          tile('This week', summary.week),
          const SizedBox(width: 8),
          tile('This month', summary.month),
        ]),
        const SizedBox(height: 10),
        GlassCard(
          radius: 16,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              const Icon(Icons.verified_rounded, color: AppColors.gold, size: 18),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'All time: ${summary.allClosed} signals · ${summary.allWinRate.toStringAsFixed(0)}% win rate'
                  '${summary.since != null ? ' · since ${Fmt.date(summary.since)}' : ''}',
                  style: const TextStyle(fontSize: 12.5, color: AppColors.text2),
                ),
              ),
              Text(Fmt.pips(summary.allPips),
                  style: AppText.mono(14,
                      weight: FontWeight.w800,
                      color: summary.allPips >= 0 ? AppColors.buy : AppColors.sell)),
            ],
          ),
        ),
      ],
    );
  }
}

class _ReportRowCard extends StatelessWidget {
  final ReportRow row;
  final String period;
  const _ReportRowCard({required this.row, required this.period});

  @override
  Widget build(BuildContext context) {
    final color = row.pips > 0 ? AppColors.buy : (row.pips < 0 ? AppColors.sell : AppColors.text3);
    final total = row.wins + row.losses;
    return GlassCard(
      radius: 16,
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      onTap: row.closed == 0
          ? null
          : () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => ReportPeriodScreen(row: row, period: period))),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(row.label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                    const SizedBox(height: 3),
                    Text(
                      row.closed == 0
                          ? (row.opened == 0 ? 'No signals' : '${row.opened} new · none closed yet')
                          : '${row.wins} won · ${row.losses} lost · ${row.opened} new',
                      style: AppText.caption,
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(row.closed == 0 ? '—' : Fmt.pips(row.pips),
                      style: AppText.mono(16, weight: FontWeight.w800, color: color)),
                  if (row.closed > 0)
                    Text('${row.winRate.toStringAsFixed(0)}% win', style: AppText.label),
                ],
              ),
              if (row.closed > 0) ...[
                const SizedBox(width: 4),
                const Icon(Icons.chevron_right_rounded, color: AppColors.text3, size: 20),
              ],
            ],
          ),
          if (total > 0) ...[
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: Row(
                children: [
                  if (row.wins > 0)
                    Expanded(flex: row.wins, child: Container(height: 5, color: AppColors.buy)),
                  if (row.losses > 0)
                    Expanded(flex: row.losses, child: Container(height: 5, color: AppColors.sell)),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Every signal closed in one report period.
class ReportPeriodScreen extends StatefulWidget {
  final ReportRow row;
  final String period;
  const ReportPeriodScreen({super.key, required this.row, required this.period});

  @override
  State<ReportPeriodScreen> createState() => _ReportPeriodScreenState();
}

class _ReportPeriodScreenState extends State<ReportPeriodScreen> {
  final List<TradeSignal> _items = [];
  bool _hasMore = true;
  bool _loading = false;
  int _page = 0;
  String? _error;

  @override
  void initState() {
    super.initState();
    _more();
  }

  Future<void> _more() async {
    if (_loading || !_hasMore) return;
    setState(() => _loading = true);
    try {
      final p = await context.read<Api>().signals(
          running: false, page: _page + 1, from: widget.row.start, to: widget.row.end,
          // Reports total every market, so the trades behind them do too.
          allMarkets: true);
      if (!mounted) return;
      setState(() {
        _page++;
        _items.addAll(p.items);
        _hasMore = p.hasMore;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.row;
    final title = {'daily': 'Daily report', 'weekly': 'Weekly report', 'monthly': 'Monthly report'}[widget.period]!;
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.pixels > n.metrics.maxScrollExtent - 300) _more();
          return false;
        },
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
          children: [
            Text(r.label, style: AppText.h2),
            const SizedBox(height: 14),
            GlassCard(
              borderColor: AppColors.gold.withValues(alpha: 0.28),
              gradient: const LinearGradient(colors: [Color(0xFF1E1A10), Color(0xFF12151E)]),
              child: Row(
                children: [
                  for (final (label, value, color) in [
                    ('Pips', Fmt.pips(r.pips), r.pips >= 0 ? AppColors.buy : AppColors.sell),
                    ('Win rate', '${r.winRate.toStringAsFixed(0)}%', AppColors.gold),
                    ('Won / lost', '${r.wins}/${r.losses}', AppColors.text),
                    ('New', '${r.opened}', AppColors.text),
                  ])
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          FittedBox(
                              fit: BoxFit.scaleDown,
                              child: Text(value, style: AppText.mono(19, weight: FontWeight.w800, color: color))),
                          const SizedBox(height: 2),
                          Text(label, style: AppText.label),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            if (r.bestPair != null) ...[
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: InfoBanner(
                      icon: Icons.trending_up_rounded,
                      color: AppColors.buy,
                      text: 'Best: ${r.bestPair} ${Fmt.pips(r.bestPips)} pips'),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: InfoBanner(
                      icon: Icons.trending_down_rounded,
                      color: AppColors.sell,
                      text: 'Worst: ${r.worstPair} ${Fmt.pips(r.worstPips)} pips'),
                ),
              ]),
            ],
            const SizedBox(height: 18),
            const SectionHeader('Closed signals'),
            if (_error != null && _items.isEmpty)
              InfoBanner(icon: Icons.error_outline, color: AppColors.sell, text: _error!),
            GlassCard(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Column(
                children: [
                  for (final s in _items) SignalRow(s: s, onTap: () => openSignal(context, s.id)),
                  if (_loading)
                    const Padding(
                      padding: EdgeInsets.all(16),
                      child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
