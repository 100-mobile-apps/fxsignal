import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../data/api.dart';
import '../models/models.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import '../widgets/signal_card.dart';
import '../services/review_service.dart';
import 'legal_screen.dart';
import 'paywall_screen.dart';
import 'tools_screen.dart';

class SignalDetailScreen extends StatefulWidget {
  final int signalId;
  const SignalDetailScreen({super.key, required this.signalId});

  @override
  State<SignalDetailScreen> createState() => _SignalDetailScreenState();
}

class _SignalDetailScreenState extends State<SignalDetailScreen> {
  TradeSignal? _s;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool _paywallShown = false;

  Future<void> _unlock() async {
    final unlocked = await openPaywall(context);
    if (unlocked && mounted) {
      setState(() => _s = null);
      await _load();
    }
  }

  Future<void> _load() async {
    try {
      final s = await context.read<Api>().signal(widget.signalId);
      if (mounted) setState(() => _s = s);
      // Opened from a notification without a subscription: go straight to the paywall once.
      if (s.locked && !_paywallShown && mounted) {
        _paywallShown = true;
        WidgetsBinding.instance.addPostFrameCallback((_) => _unlock());
        return;
      }
      // A good moment to ask for a rating: the user is looking at a winning trade.
      if (!s.isRunning && s.isWin) {
        Future.delayed(const Duration(seconds: 2), ReviewService.maybeAsk);
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _copy(String text, String what) {
    Clipboard.setData(ClipboardData(text: text));
    showSnack(context, '$what copied');
  }

  String _allLevels(TradeSignal s) => '${s.direction} ${s.pair} @ ${Fmt.price(s.pair, s.entry)}\n'
      'SL ${Fmt.price(s.pair, s.stopLoss)}\n'
      'TP1 ${Fmt.price(s.pair, s.tp1)}\n'
      'TP2 ${Fmt.price(s.pair, s.tp2)}';

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return Scaffold(
      appBar: AppBar(
        title: Text(s == null ? 'Signal' : '${s.pair} · ${s.timeframeLabel}'),
        actions: [
          if (s != null && !s.locked)
            IconButton(
              tooltip: 'Copy levels',
              icon: const Icon(Icons.copy_all_rounded),
              onPressed: () => _copy(_allLevels(s), 'Trade levels'),
            ),
        ],
      ),
      body: s != null && s.locked
          ? _LockedSignal(s: s, onUnlock: _unlock)
          : s == null
          ? Center(
              child: _error != null
                  ? EmptyState(
                      icon: Icons.error_outline,
                      title: 'Couldn\'t load this signal',
                      message: _error!,
                      action: OutlineButton2(
                          label: 'Try again',
                          icon: Icons.refresh,
                          onPressed: () {
                            setState(() => _error = null);
                            _load();
                          }),
                    )
                  : const CircularProgressIndicator())
          : RefreshIndicator(
              color: AppColors.gold,
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
                children: [
                  _Header(s: s),
                  const SizedBox(height: 14),
                  if (s.isRunning && s.tp1Hit)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 14),
                      child: InfoBanner(
                        icon: Icons.shield_outlined,
                        color: AppColors.buy,
                        text: 'TP1 reached. Move your stop loss to the entry price to make this trade risk-free.',
                      ),
                    ),
                  GlassCard(
                    padding: const EdgeInsets.fromLTRB(10, 14, 6, 10),
                    child: SignalChart(signal: s),
                  ),
                  const SizedBox(height: 14),
                  GlassCard(
                    padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Trade progress', style: AppText.title),
                        const SizedBox(height: 14),
                        LevelTrack(s: s),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  _Levels(s: s, onCopy: _copy),
                  const SizedBox(height: 14),
                  _Timeline(s: s),
                  const SizedBox(height: 14),
                  _Analysis(s: s),
                  const SizedBox(height: 18),
                  GoldButton(
                    label: 'Calculate position size',
                    icon: Icons.calculate_outlined,
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => Scaffold(
                        appBar: AppBar(title: const Text('Position size')),
                        body: LotSizeCalculator(
                            initialPair: s.pair, initialStopPips: s.slPips.abs()),
                      ),
                    )),
                  ),
                  const SizedBox(height: 16),
                  GestureDetector(
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => const LegalScreen(doc: LegalDoc.risk))),
                    child: const Text(
                      'This is automated technical analysis, not financial advice. Risk only what you can afford to lose. Read the risk disclaimer.',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 11.5, color: AppColors.text3, height: 1.5),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}

class _Header extends StatelessWidget {
  final TradeSignal s;
  const _Header({required this.s});

  @override
  Widget build(BuildContext context) {
    final pips = s.displayPips;
    final color = pips >= 0 ? AppColors.buy : AppColors.sell;
    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(
        children: [
          Row(
            children: [
              PairAvatar(pair: s.pair, size: 48),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s.pair, style: AppText.h2),
                    const SizedBox(height: 6),
                    Row(children: [
                      DirectionPill(isBuy: s.isBuy),
                      const SizedBox(width: 6),
                      StatusPill(s: s),
                    ]),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(s.isRunning ? 'Floating result' : 'Final result', style: AppText.label),
                  const SizedBox(height: 4),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.baseline,
                    textBaseline: TextBaseline.alphabetic,
                    children: [
                      Text(Fmt.pips(pips),
                          style: AppText.mono(34, weight: FontWeight.w800, color: color)),
                      const SizedBox(width: 6),
                      const Text('pips', style: AppText.caption),
                    ],
                  ),
                ],
              ),
              const Spacer(),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(s.isRunning ? 'Current price' : 'Close price', style: AppText.label),
                  const SizedBox(height: 4),
                  Text(Fmt.price(s.pair, s.isRunning ? s.currentPrice : s.closePrice),
                      style: AppText.mono(17, weight: FontWeight.w700)),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Levels extends StatelessWidget {
  final TradeSignal s;
  final void Function(String, String) onCopy;
  const _Levels({required this.s, required this.onCopy});

  @override
  Widget build(BuildContext context) {
    Widget row(String label, double price, Color color, String? extra, {bool last = false}) {
      return Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          border: last ? null : const Border(bottom: BorderSide(color: AppColors.border)),
        ),
        child: Row(
          children: [
            Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
                  if (extra != null) Text(extra, style: AppText.caption),
                ],
              ),
            ),
            Text(Fmt.price(s.pair, price), style: AppText.mono(16, weight: FontWeight.w700, color: color)),
            IconButton(
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.copy_rounded, size: 16, color: AppColors.text3),
              onPressed: () => onCopy(Fmt.price(s.pair, price), label),
            ),
          ],
        ),
      );
    }

    return GlassCard(
      padding: const EdgeInsets.fromLTRB(18, 14, 8, 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(bottom: 4),
            child: Text('Trade levels', style: AppText.title),
          ),
          row('Entry', s.entry, AppColors.gold, '${s.direction} at market'),
          row('Stop loss', s.stopLoss, AppColors.sell,
              '${Fmt.pips(s.slPips)} pips${s.tp1Hit ? ' · moved to entry' : ''}'),
          row('Take profit 1', s.tp1, AppColors.buy, '${Fmt.pips(s.tp1Pips)} pips · R:R 1:1'),
          row('Take profit 2', s.tp2, AppColors.buy, '${Fmt.pips(s.tp2Pips)} pips · R:R 1:2', last: true),
        ],
      ),
    );
  }
}

class _Timeline extends StatelessWidget {
  final TradeSignal s;
  const _Timeline({required this.s});

  @override
  Widget build(BuildContext context) {
    final steps = <(String, String, bool, Color)>[
      ('Signal published', Fmt.dateTime(s.openedAt), true, AppColors.gold),
      (
        'TP1 reached',
        s.tp1Hit ? Fmt.dateTime(s.tp1HitAt) : (s.isRunning ? 'Waiting' : 'Not reached'),
        s.tp1Hit,
        AppColors.buy
      ),
      if (s.isRunning)
        (
          'Auto-close',
          s.expiresAt == null
              ? '—'
              : 'In ${Fmt.duration(s.expiresAt!.difference(DateTime.now()).isNegative ? Duration.zero : s.expiresAt!.difference(DateTime.now()))} if still open',
          false,
          AppColors.text3
        )
      else
        (
          'Closed · ${s.resultLabel}',
          '${Fmt.dateTime(s.closedAt)} · ${Fmt.pips(s.pips)} pips',
          true,
          resultColor(s)
        ),
    ];
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Timeline', style: AppText.title),
          const SizedBox(height: 14),
          for (var i = 0; i < steps.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Column(
                    children: [
                      Container(
                        width: 14,
                        height: 14,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: steps[i].$3 ? steps[i].$4 : Colors.transparent,
                          border: Border.all(color: steps[i].$4, width: 2),
                        ),
                      ),
                      if (i < steps.length - 1)
                        Expanded(child: Container(width: 2, color: AppColors.border)),
                    ],
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(steps[i].$1, style: const TextStyle(fontWeight: FontWeight.w600, height: 1)),
                          const SizedBox(height: 4),
                          Text(steps[i].$2, style: AppText.caption),
                        ],
                      ),
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

class _Analysis extends StatelessWidget {
  final TradeSignal s;
  const _Analysis({required this.s});

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('Why this signal', style: AppText.title),
              const Spacer(),
              GestureDetector(
                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => const LegalScreen(doc: LegalDoc.methodology))),
                child: const Text('Methodology',
                    style: TextStyle(color: AppColors.gold, fontSize: 13, fontWeight: FontWeight.w600)),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              const Text('Signal strength', style: AppText.caption),
              const Spacer(),
              Text('${s.confidence}%', style: AppText.mono(14, weight: FontWeight.w700, color: AppColors.gold)),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: s.confidence / 100),
              duration: const Duration(milliseconds: 800),
              builder: (_, v, __) => LinearProgressIndicator(
                value: v,
                minHeight: 8,
                backgroundColor: AppColors.surface2,
                color: AppColors.gold,
              ),
            ),
          ),
          const SizedBox(height: 16),
          for (final r in s.reasons)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    r.startsWith('Caution') ? Icons.warning_amber_rounded : Icons.check_circle_rounded,
                    size: 18,
                    color: r.startsWith('Caution') ? AppColors.warn : (s.isBuy ? AppColors.buy : AppColors.sell),
                  ),
                  const SizedBox(width: 10),
                  Expanded(child: Text(r, style: const TextStyle(fontSize: 14, height: 1.35))),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Shown instead of the levels when a live signal is opened without a subscription.
class _LockedSignal extends StatelessWidget {
  final TradeSignal s;
  final VoidCallback onUnlock;
  const _LockedSignal({required this.s, required this.onUnlock});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        GlassCard(
          padding: const EdgeInsets.all(20),
          child: Column(
            children: [
              Row(children: [
                PairAvatar(pair: s.pair, size: 44),
                const SizedBox(width: 12),
                Text(s.pair, style: AppText.h2),
                const SizedBox(width: 8),
                DirectionPill(isBuy: s.isBuy),
                const Spacer(),
                Text('${s.confidence}%', style: AppText.mono(16, color: AppColors.gold)),
              ]),
              const SizedBox(height: 24),
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(
                  color: AppColors.gold.withValues(alpha: 0.12),
                  shape: BoxShape.circle,
                  border: Border.all(color: AppColors.gold.withValues(alpha: 0.35)),
                ),
                child: const Icon(Icons.lock_rounded, color: AppColors.gold),
              ),
              const SizedBox(height: 14),
              const Text('This live signal is for Premium members',
                  textAlign: TextAlign.center, style: AppText.title),
              const SizedBox(height: 6),
              const Text('Subscribe to see the entry, stop loss, TP1, TP2 and the live chart levels.',
                  textAlign: TextAlign.center, style: AppText.body),
              const SizedBox(height: 18),
              GoldButton(label: 'Unlock live signals', icon: Icons.workspace_premium_rounded, onPressed: onUnlock),
            ],
          ),
        ),
      ],
    );
  }
}
