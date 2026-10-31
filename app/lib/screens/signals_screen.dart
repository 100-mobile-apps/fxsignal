import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../state/data_stores.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import '../widgets/signal_card.dart';
import 'signal_detail_screen.dart';

void openSignal(BuildContext context, int id) {
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => SignalDetailScreen(signalId: id)));
}

class SignalsScreen extends StatefulWidget {
  final VoidCallback onSeeResults;
  const SignalsScreen({super.key, required this.onSeeResults});

  @override
  State<SignalsScreen> createState() => _SignalsScreenState();
}

class _SignalsScreenState extends State<SignalsScreen> {
  bool _showHistory = false;
  bool _showMinorLosses = false;
  final _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_showHistory && _scroll.position.pixels > _scroll.position.maxScrollExtent - 400) {
        context.read<SignalsStore>().loadMoreHistory();
      }
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    await Future.wait([
      context.read<SignalsStore>().refresh(),
      context.read<StatsStore>().load(forDays: 30),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final store = context.watch<SignalsStore>();
    final user = context.watch<SessionStore>().user;
    final stats = context.watch<StatsStore>().month;
    final hiddenCount = store.history.where(isMinorLoss).length;
    final list = _showHistory
        ? (_showMinorLosses
            ? store.history
            : store.history.where((s) => !isMinorLoss(s)).toList())
        : store.running;

    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        color: AppColors.gold,
        backgroundColor: AppColors.surface,
        onRefresh: _refresh,
        child: CustomScrollView(
          controller: _scroll,
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
              sliver: SliverList.list(children: [
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(Fmt.greeting(), style: AppText.caption.copyWith(fontSize: 13)),
                          const SizedBox(height: 2),
                          Text(user?.name.split(' ').first ?? 'Trader',
                              style: AppText.h1.copyWith(fontSize: 24)),
                        ],
                      ),
                    ),
                    _LiveDot(updated: store.lastUpdated, loading: store.loadingRunning),
                  ],
                ),
                const SizedBox(height: 18),
                const DemoBanner(),
                _PerformanceCard(
                  winRate: stats?.winRate,
                  pips: stats?.totalPips,
                  total: stats?.total,
                  active: store.running.length,
                  onTap: widget.onSeeResults,
                ),
                const SizedBox(height: 22),
                _Segmented(
                  left: 'Active (${store.running.length})',
                  right: 'History',
                  rightSelected: _showHistory,
                  onChanged: (v) => setState(() => _showHistory = v),
                ),
                const SizedBox(height: 16),
                if (_showHistory && hiddenCount > 0)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (!_showMinorLosses)
                        InfoBanner(
                          icon: Icons.filter_alt_rounded,
                          color: AppColors.gold,
                          text: 'Filtered history: $hiddenCount smaller expired losses hidden in loaded results. Reports include every outcome.',
                        ),
                      TextButton.icon(
                        onPressed: () => setState(() => _showMinorLosses = !_showMinorLosses),
                        icon: Icon(_showMinorLosses
                            ? Icons.visibility_off_rounded : Icons.visibility_rounded),
                        label: Text(_showMinorLosses
                            ? 'Hide smaller expired losses'
                            : 'Show all loaded results'),
                      ),
                    ],
                  ),
                if (store.error != null && (_showHistory || list.isEmpty))
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: InfoBanner(
                      icon: Icons.wifi_off_rounded,
                      color: AppColors.sell,
                      text: '${store.error!} Pull down or load more to retry.',
                    ),
                  ),
              ]),
            ),
            if (!store.loadedOnce)
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                sliver: SliverList.list(children: const [
                  Skeleton(height: 190),
                  SizedBox(height: 14),
                  Skeleton(height: 190),
                ]),
              )
            else if (list.isEmpty)
              SliverToBoxAdapter(
                child: _showHistory
                    ? EmptyState(
                        icon: Icons.history_rounded,
                        title: hiddenCount > 0
                            ? 'Smaller expired losses are collapsed'
                            : 'No closed signals yet',
                        message: hiddenCount > 0
                            ? 'Show them above or load more history. All losses remain in reports.'
                            : 'Finished signals and their results will appear here.',
                      )
                    : const EmptyState(
                        icon: Icons.radar_rounded,
                        title: 'No active signals right now',
                        message:
                            'The engine scans 8 markets every 20 minutes and only publishes strong setups. We\'ll notify you when one appears.',
                      ),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                sliver: SliverList.separated(
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 14),
                  itemBuilder: (context, i) => SignalCard(
                    s: list[i],
                    onTap: () => openSignal(context, list[i].id),
                  ),
                ),
              ),
            if (_showHistory && store.loadingHistory && store.history.isNotEmpty)
              const SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.all(20),
                  child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
                ),
              ),
            if (_showHistory && store.hasMoreHistory && !store.loadingHistory)
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                  child: TextButton(
                    onPressed: store.loadMoreHistory,
                    child: const Text('Load more history'),
                  ),
                ),
              ),
            const SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.fromLTRB(28, 24, 28, 28),
                child: Text(
                  'Signals are automated technical analysis, not financial advice. Trading carries risk.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 11, color: AppColors.text3),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _LiveDot extends StatelessWidget {
  final DateTime? updated;
  final bool loading;
  const _LiveDot({required this.updated, required this.loading});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(30),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (loading)
            const SizedBox(
                width: 10, height: 10, child: CircularProgressIndicator(strokeWidth: 1.6))
          else
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(
                color: AppColors.buy,
                shape: BoxShape.circle,
                boxShadow: [BoxShadow(color: AppColors.buy.withValues(alpha: 0.6), blurRadius: 6)],
              ),
            ),
          const SizedBox(width: 8),
          Text(updated == null ? 'Connecting' : 'Updated ${Fmt.ago(updated)}',
              style: const TextStyle(fontSize: 12, color: AppColors.text2)),
        ],
      ),
    );
  }
}

class _PerformanceCard extends StatelessWidget {
  final double? winRate, pips;
  final int? total;
  final int active;
  final VoidCallback onTap;
  const _PerformanceCard(
      {required this.winRate,
      required this.pips,
      required this.total,
      required this.active,
      required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.all(18),
      gradient: const LinearGradient(
        colors: [Color(0xFF1E1A10), Color(0xFF12151E)],
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
      ),
      borderColor: AppColors.gold.withValues(alpha: 0.28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.verified_rounded, color: AppColors.gold, size: 18),
              const SizedBox(width: 6),
              const Expanded(
                child: Text('Last 30 days performance',
                    maxLines: 1, overflow: TextOverflow.ellipsis,
                    style: TextStyle(fontSize: 13, color: AppColors.text2, fontWeight: FontWeight.w600)),
              ),
              Text('View results',
                  style: TextStyle(fontSize: 12, color: AppColors.gold.withValues(alpha: 0.9))),
              const Icon(Icons.chevron_right_rounded, color: AppColors.gold, size: 18),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              _Metric(
                  label: 'Win rate',
                  value: winRate == null ? '—' : '${winRate!.toStringAsFixed(0)}%',
                  color: AppColors.gold),
              _Metric(
                  label: 'Pips',
                  value: pips == null ? '—' : Fmt.pips(pips),
                  color: (pips ?? 0) >= 0 ? AppColors.buy : AppColors.sell),
              _Metric(label: 'Signals', value: total == null ? '—' : '$total'),
              _Metric(label: 'Active', value: '$active'),
            ],
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  final String label, value;
  final Color color;
  const _Metric({required this.label, required this.value, this.color = AppColors.text});

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(value, style: AppText.mono(20, weight: FontWeight.w800, color: color))),
            const SizedBox(height: 2),
            Text(label, style: AppText.label),
          ],
        ),
      );
}

class _Segmented extends StatelessWidget {
  final String left, right;
  final bool rightSelected;
  final ValueChanged<bool> onChanged;
  const _Segmented(
      {required this.left,
      required this.right,
      required this.rightSelected,
      required this.onChanged});

  @override
  Widget build(BuildContext context) {
    Widget tab(String text, bool selected, bool value) => Expanded(
          child: GestureDetector(
            onTap: () => onChanged(value),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(vertical: 11),
              decoration: BoxDecoration(
                color: selected ? AppColors.surface2 : Colors.transparent,
                borderRadius: BorderRadius.circular(11),
                border: Border.all(color: selected ? AppColors.border : Colors.transparent),
              ),
              alignment: Alignment.center,
              child: Text(text,
                  style: TextStyle(
                      fontWeight: FontWeight.w600,
                      fontSize: 14,
                      color: selected ? AppColors.text : AppColors.text3)),
            ),
          ),
        );
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(children: [tab(left, !rightSelected, false), tab(right, rightSelected, true)]),
    );
  }
}
