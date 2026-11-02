import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../state/data_stores.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import '../widgets/signal_card.dart';
import 'legal_screen.dart';
import 'bot_screen.dart';
import 'money_screen.dart';
import 'reports_view.dart';
import 'signals_screen.dart';

class ResultsScreen extends StatefulWidget {
  const ResultsScreen({super.key});

  @override
  State<ResultsScreen> createState() => _ResultsScreenState();
}

class _ResultsScreenState extends State<ResultsScreen> {
  int _view = 0;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 12, 20, 0),
            child: Text('Track record', style: AppText.h1),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 4, 20, 14),
            child: Text('Every signal, recorded automatically. Wins and losses.', style: AppText.caption),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
            child: Container(
              padding: const EdgeInsets.all(4),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppColors.border),
              ),
              child: Row(
                children: [
                  for (final (i, label) in [(0, 'Overview'), (1, 'Reports')])
                    Expanded(
                      child: GestureDetector(
                        onTap: () => setState(() => _view = i),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: _view == i ? AppColors.surface2 : Colors.transparent,
                            borderRadius: BorderRadius.circular(11),
                            border: Border.all(color: _view == i ? AppColors.border : Colors.transparent),
                          ),
                          child: Text(label,
                              style: TextStyle(
                                  fontWeight: FontWeight.w600,
                                  color: _view == i ? AppColors.text : AppColors.text3)),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
          Expanded(
            child: IndexedStack(index: _view, children: const [_Overview(), ReportsView()]),
          ),
        ],
      ),
    );
  }
}

class _Overview extends StatelessWidget {
  const _Overview();

  static const _periods = [(7, '7D'), (30, '30D'), (90, '90D'), (0, 'All')];

  @override
  Widget build(BuildContext context) {
    final store = context.watch<StatsStore>();
    final signals = context.watch<SignalsStore>();
    final st = store.current;

    return RefreshIndicator(
        color: AppColors.gold,
        onRefresh: () => store.load(),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 32),
          children: [
            const DemoBanner(),
            GlassCard(
              borderColor: AppColors.gold.withValues(alpha: 0.5),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const MoneyScreen())),
              child: Row(
                children: [
                  const Icon(Icons.payments_outlined, color: AppColors.gold),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Your money', style: AppText.title),
                        SizedBox(height: 2),
                        Text('See what these signals would have made in dollars for your account size and risk, and pick your strategy.',
                            style: AppText.caption),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded, color: AppColors.text3),
                ],
              ),
            ),
            const SizedBox(height: 10),
            GlassCard(
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const BotScreen())),
              child: const Row(
                children: [
                  Icon(Icons.smart_toy_outlined, color: AppColors.gold),
                  SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Auto-trade bot', style: AppText.title),
                        SizedBox(height: 2),
                        Text('Let a free MetaTrader 5 robot place every signal for you, so you never miss one.', style: AppText.caption),
                      ],
                    ),
                  ),
                  Icon(Icons.chevron_right_rounded, color: AppColors.text3),
                ],
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                for (final p in _periods)
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 3),
                      child: GestureDetector(
                        onTap: () => store.select(p.$1),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: store.days == p.$1 ? AppColors.gold : AppColors.surface,
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(
                                color: store.days == p.$1 ? AppColors.gold : AppColors.border),
                          ),
                          child: Text(p.$2,
                              style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: store.days == p.$1 ? AppColors.bg : AppColors.text2)),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 18),
            if (st == null && store.loading) ...[
              const Skeleton(height: 170),
              const SizedBox(height: 14),
              const Skeleton(height: 200),
            ] else if (st == null)
              EmptyState(
                icon: Icons.cloud_off_rounded,
                title: 'Couldn\'t load results',
                message: store.error ?? 'Pull down to try again.',
              )
            else if (st.total == 0)
              const EmptyState(
                icon: Icons.hourglass_empty_rounded,
                title: 'No closed signals in this period',
                message: 'Results appear here as soon as signals hit their target or stop.',
              )
            else ...[
              _Hero(st: st),
              const SizedBox(height: 14),
              _StatGrid(st: st),
              const SizedBox(height: 14),
              GlassCard(
                padding: const EdgeInsets.fromLTRB(18, 16, 12, 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Growth in pips', style: AppText.title),
                    const SizedBox(height: 2),
                    const Text('Cumulative result of every closed signal', style: AppText.caption),
                    const SizedBox(height: 16),
                    EquityChart(points: st.equity),
                  ],
                ),
              ),
              if (st.monthly.length > 1) ...[
                const SizedBox(height: 14),
                GlassCard(
                  padding: const EdgeInsets.fromLTRB(14, 16, 14, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Padding(
                        padding: EdgeInsets.only(left: 4, bottom: 12),
                        child: Text('Monthly pips', style: AppText.title),
                      ),
                      MonthlyBars(months: st.monthly),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 14),
              _ByPair(pairs: st.byPair),
            ],
            const SizedBox(height: 14),
            if (signals.history.isNotEmpty)
              GlassCard(
                padding: const EdgeInsets.fromLTRB(14, 16, 14, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Padding(
                      padding: EdgeInsets.only(left: 4),
                      child: SectionHeader('Latest results'),
                    ),
                    for (final s in signals.history.take(8))
                      SignalRow(s: s, onTap: () => openSignal(context, s.id)),
                  ],
                ),
              ),
            const SizedBox(height: 14),
            InfoBanner(
              icon: Icons.fact_check_outlined,
              text: 'How results are counted: stop loss is checked before targets, the stop moves '
                  'to entry after TP1, and signals are never edited or deleted. Pips are before '
                  'spread and commission. Tap to read the full methodology.',
              onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const LegalScreen(doc: LegalDoc.methodology))),
            ),
          ],
        ),
    );
  }
}

class _Hero extends StatelessWidget {
  final Stats st;
  const _Hero({required this.st});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.all(20),
      borderColor: AppColors.gold.withValues(alpha: 0.25),
      gradient: const LinearGradient(
        colors: [Color(0xFF1E1A10), Color(0xFF10141E)],
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
      ),
      child: Row(
        children: [
          WinRateRing(percent: st.winRate, size: 118),
          const SizedBox(width: 20),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Total pips', style: AppText.label),
                const SizedBox(height: 4),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(Fmt.pips(st.totalPips),
                      style: AppText.mono(30,
                          weight: FontWeight.w800,
                          color: st.totalPips >= 0 ? AppColors.buy : AppColors.sell)),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    _Dot(color: AppColors.buy, text: '${st.wins} wins'),
                    const SizedBox(width: 14),
                    _Dot(color: AppColors.sell, text: '${st.losses} losses'),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Dot extends StatelessWidget {
  final Color color;
  final String text;
  const _Dot({required this.color, required this.text});

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(text, style: const TextStyle(fontSize: 13, color: AppColors.text2)),
        ],
      );
}

class _StatGrid extends StatelessWidget {
  final Stats st;
  const _StatGrid({required this.st});

  @override
  Widget build(BuildContext context) {
    final tiles = [
      ('Signals', '${st.total}', AppColors.text),
      ('Profit factor', st.profitFactor == null ? '∞' : st.profitFactor!.toStringAsFixed(2), AppColors.gold),
      ('Avg win', '+${st.avgWin.toStringAsFixed(1)}', AppColors.buy),
      ('Avg loss', '-${st.avgLoss.toStringAsFixed(1)}', AppColors.sell),
      ('Best pair', st.bestPair ?? '—', AppColors.text),
      ('Running now', '${st.running}', AppColors.info),
    ];
    return GridView.count(
      crossAxisCount: 3,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.25,
      children: [
        for (final t in tiles)
          GlassCard(
            radius: 16,
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(t.$2, style: AppText.mono(18, weight: FontWeight.w800, color: t.$3))),
                const SizedBox(height: 4),
                Text(t.$1, style: AppText.label),
              ],
            ),
          ),
      ],
    );
  }
}

class _ByPair extends StatelessWidget {
  final List<PairStat> pairs;
  const _ByPair({required this.pairs});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHeader('By market'),
          for (final p in pairs)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: Row(
                children: [
                  PairAvatar(pair: p.pair, size: 30),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Text(p.pair, style: const TextStyle(fontWeight: FontWeight.w600)),
                            const Spacer(),
                            Text(Fmt.pips(p.pips),
                                style: AppText.mono(13.5,
                                    weight: FontWeight.w700,
                                    color: p.pips >= 0 ? AppColors.buy : AppColors.sell)),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            Expanded(
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(4),
                                child: LinearProgressIndicator(
                                  value: p.winRate / 100,
                                  minHeight: 5,
                                  backgroundColor: AppColors.sell.withValues(alpha: 0.25),
                                  color: AppColors.buy,
                                ),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Text('${p.winRate.toStringAsFixed(0)}% · ${p.total}',
                                style: AppText.label),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
