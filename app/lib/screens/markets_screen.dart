import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../state/data_stores.dart';
import '../state/session_store.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';

class MarketsScreen extends StatefulWidget {
  const MarketsScreen({super.key});

  @override
  State<MarketsScreen> createState() => _MarketsScreenState();
}

class _MarketsScreenState extends State<MarketsScreen> {
  bool _editing = false;

  @override
  Widget build(BuildContext context) {
    final market = context.watch<MarketStore>();
    final session = context.watch<SessionStore>();
    final watch = session.user?.watchlist ?? AppConfig.pairs;
    final symbols = _editing ? AppConfig.pairs : watch;

    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        color: AppColors.gold,
        onRefresh: market.load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
          children: [
            const Text('Markets', style: AppText.h1),
            const SizedBox(height: 4),
            const Text('Prices, sessions and your watchlist', style: AppText.caption),
            const SizedBox(height: 18),
            const SessionClock(),
            const SizedBox(height: 22),
            SectionHeader('Watchlist',
                action: _editing ? 'Done' : 'Edit',
                onAction: () => setState(() => _editing = !_editing)),
            if (market.prices.isEmpty && market.loading)
              const Skeleton(height: 300)
            else if (market.prices.isEmpty)
              EmptyState(
                icon: Icons.show_chart_rounded,
                title: 'Prices unavailable',
                message: market.error ?? 'Prices update every 20 minutes. Pull to refresh.',
              )
            else if (symbols.isEmpty)
              const EmptyState(
                icon: Icons.star_outline_rounded,
                title: 'Your watchlist is empty',
                message: 'Tap Edit to add markets.',
              )
            else
              GlassCard(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                child: Column(
                  children: [
                    for (var i = 0; i < symbols.length; i++)
                      _QuoteRow(
                        symbol: symbols[i],
                        quote: market.quote(symbols[i]),
                        editing: _editing,
                        starred: watch.contains(symbols[i]),
                        last: i == symbols.length - 1,
                        onStar: () async {
                          try {
                            await session.toggleWatch(symbols[i]);
                          } catch (e) {
                            if (context.mounted) showSnack(context, '$e', error: true);
                          }
                        },
                      ),
                  ],
                ),
              ),
            const SizedBox(height: 12),
            if (market.prices.isNotEmpty)
              Text(
                'Prices are delayed and refresh with each market scan'
                '${market.prices.first.updatedAt != null ? ' · updated ${Fmt.ago(market.prices.first.updatedAt)}' : ''}.',
                textAlign: TextAlign.center,
                style: AppText.label,
              ),
          ],
        ),
      ),
    );
  }
}

class _QuoteRow extends StatelessWidget {
  final String symbol;
  final PriceQuote? quote;
  final bool editing, starred, last;
  final VoidCallback onStar;
  const _QuoteRow(
      {required this.symbol,
      required this.quote,
      required this.editing,
      required this.starred,
      required this.last,
      required this.onStar});

  @override
  Widget build(BuildContext context) {
    final q = quote;
    final up = (q?.changePct ?? 0) >= 0;
    final color = up ? AppColors.buy : AppColors.sell;
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: AppColors.border)),
      ),
      child: Row(
        children: [
          PairAvatar(pair: symbol, size: 36),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(symbol, style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 2),
                Text(
                    q == null
                        ? '—'
                        : 'H ${Fmt.price(symbol, q.high)}  L ${Fmt.price(symbol, q.low)}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppText.label.copyWith(fontSize: 10.5, fontFeatures: AppText.tabular)),
              ],
            ),
          ),
          if (!editing && q != null) ...[
            Sparkline(values: q.spark, color: color, width: 60, height: 28),
            const SizedBox(width: 12),
          ],
          SizedBox(
            width: 92,
            child: editing
                ? Align(
                    alignment: Alignment.centerRight,
                    child: IconButton(
                      onPressed: onStar,
                      icon: Icon(starred ? Icons.star_rounded : Icons.star_outline_rounded,
                          color: starred ? AppColors.gold : AppColors.text3),
                    ),
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(Fmt.price(symbol, q?.price), style: AppText.mono(15, weight: FontWeight.w700)),
                      const SizedBox(height: 4),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: color.withValues(alpha: 0.14),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(q == null ? '—' : Fmt.pct(q.changePct, digits: 2),
                            style: AppText.mono(11.5, weight: FontWeight.w700, color: color)),
                      ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

/// Forex session status computed from UTC time (standard hours).
class SessionClock extends StatefulWidget {
  const SessionClock({super.key});

  @override
  State<SessionClock> createState() => _SessionClockState();
}

class _SessionClockState extends State<SessionClock> {
  Timer? _t;

  static const _sessions = [
    ('Sydney', 21, 6, '🇦🇺'),
    ('Tokyo', 0, 9, '🇯🇵'),
    ('London', 7, 16, '🇬🇧'),
    ('New York', 12, 21, '🇺🇸'),
  ];

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 30), (_) => setState(() {}));
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  bool _open(int start, int end, double h) =>
      start < end ? h >= start && h < end : h >= start || h < end;

  double _progress(int start, int end, double h) {
    final len = (end - start + 24) % 24;
    final elapsed = (h - start + 24) % 24;
    return (elapsed / len).clamp(0.0, 1.0);
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now().toUtc();
    final h = now.hour + now.minute / 60;
    final weekend = now.weekday == DateTime.saturday ||
        (now.weekday == DateTime.sunday && now.hour < 21) ||
        (now.weekday == DateTime.friday && now.hour >= 21);
    final openCount = weekend ? 0 : _sessions.where((s) => _open(s.$2, s.$3, h)).length;

    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('Trading sessions', style: AppText.title),
              const Spacer(),
              Pill(
                text: weekend
                    ? 'MARKET CLOSED'
                    : openCount > 1
                        ? 'OVERLAP · HIGH VOLUME'
                        : '$openCount OPEN',
                color: weekend ? AppColors.sell : (openCount > 1 ? AppColors.gold : AppColors.buy),
              ),
            ],
          ),
          const SizedBox(height: 16),
          for (final s in _sessions)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(
                children: [
                  Text(s.$4, style: const TextStyle(fontSize: 18)),
                  const SizedBox(width: 10),
                  SizedBox(
                      width: 86,
                      child: Text(s.$1,
                          maxLines: 1,
                          softWrap: false,
                          style: const TextStyle(fontWeight: FontWeight.w600))),
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                        value: !weekend && _open(s.$2, s.$3, h) ? _progress(s.$2, s.$3, h) : 0,
                        minHeight: 6,
                        backgroundColor: AppColors.surface2,
                        color: AppColors.buy,
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    width: 52,
                    child: Text(
                      !weekend && _open(s.$2, s.$3, h) ? 'Open' : 'Closed',
                      textAlign: TextAlign.right,
                      style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: !weekend && _open(s.$2, s.$3, h) ? AppColors.buy : AppColors.text3),
                    ),
                  ),
                ],
              ),
            ),
          Text('Times in UTC · now ${Fmt.two(now.hour)}:${Fmt.two(now.minute)} UTC',
              style: AppText.label),
        ],
      ),
    );
  }
}
