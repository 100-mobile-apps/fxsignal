import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import 'common.dart';

Color resultColor(TradeSignal s) {
  if (s.isRunning) return s.tp1Hit ? AppColors.buy : AppColors.info;
  return switch (s.result) {
    SignalResult.tp2 || SignalResult.tp1 => AppColors.buy,
    SignalResult.sl => AppColors.sell,
    _ => (s.pips ?? 0) >= 0 ? AppColors.buy : AppColors.sell,
  };
}

/// Hide only small negative expiries in the compact history view; never hide a stop-loss.
bool isMinorLoss(TradeSignal s) {
  if (s.isRunning || s.result != SignalResult.expired ||
      s.pips == null || !s.pips!.isFinite || s.pips! >= 0) {
    return false;
  }
  final riskPips = s.slPips.abs();
  return riskPips.isFinite && riskPips > 0 && s.pips!.abs() / riskPips < 0.5;
}

class StatusPill extends StatelessWidget {
  final TradeSignal s;
  const StatusPill({super.key, required this.s});

  @override
  Widget build(BuildContext context) {
    if (s.isRunning) {
      return Pill(
        text: s.tp1Hit ? 'TP1 HIT' : 'LIVE',
        color: s.tp1Hit ? AppColors.buy : AppColors.info,
        icon: s.tp1Hit ? Icons.check_rounded : Icons.circle,
      );
    }
    final icon = switch (s.result) {
      SignalResult.sl => Icons.close_rounded,
      SignalResult.expired => Icons.schedule_rounded,
      _ => Icons.check_rounded,
    };
    return Pill(text: s.resultLabel.toUpperCase(), color: resultColor(s), icon: icon);
  }
}

class SignalCard extends StatelessWidget {
  final TradeSignal s;
  final VoidCallback onTap;
  const SignalCard({super.key, required this.s, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final pips = s.displayPips;
    final pColor = pips >= 0 ? AppColors.buy : AppColors.sell;
    final dirColor = s.isBuy ? AppColors.buy : AppColors.sell;
    return GlassCard(
      onTap: onTap,
      padding: EdgeInsets.zero,
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              width: 4,
              decoration: BoxDecoration(
                color: dirColor,
                borderRadius: const BorderRadius.horizontal(left: Radius.circular(20)),
              ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 16, 16, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        PairAvatar(pair: s.pair, size: 38),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(children: [
                                Flexible(
                                  child: Text(s.pair,
                                      maxLines: 1, overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                          fontSize: 16, fontWeight: FontWeight.w700)),
                                ),
                                const SizedBox(width: 8),
                                DirectionPill(isBuy: s.isBuy),
                              ]),
                              const SizedBox(height: 3),
                              Text(
                                  '${s.timeframeLabel} · ${s.isRunning ? 'Opened ${Fmt.ago(s.openedAt)}' : 'Closed ${Fmt.ago(s.closedAt)}'}',
                                  style: AppText.caption),
                            ],
                          ),
                        ),
                        if (s.locked)
                          Container(
                            width: 34,
                            height: 34,
                            decoration: BoxDecoration(
                              color: AppColors.gold.withValues(alpha: 0.12),
                              shape: BoxShape.circle,
                              border: Border.all(color: AppColors.gold.withValues(alpha: 0.35)),
                            ),
                            child: const Icon(Icons.lock_rounded, size: 16, color: AppColors.gold),
                          )
                        else
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text(Fmt.pips(pips),
                                  style: AppText.mono(18, weight: FontWeight.w800, color: pColor)),
                              const Text('pips', style: AppText.label),
                            ],
                          ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    if (s.locked)
                      const _LockedLevels()
                    else
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      decoration: BoxDecoration(
                        color: AppColors.bg.withValues(alpha: 0.5),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        children: [
                          _Lv('Entry', Fmt.price(s.pair, s.entry), AppColors.text),
                          _Lv('Stop loss', Fmt.price(s.pair, s.activeStop), AppColors.sell),
                          _Lv('TP1', Fmt.price(s.pair, s.tp1), AppColors.buy),
                          _Lv('TP2', Fmt.price(s.pair, s.tp2), AppColors.buy),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        StatusPill(s: s),
                        const Spacer(),
                        const Icon(Icons.bolt_rounded, size: 15, color: AppColors.gold),
                        const SizedBox(width: 3),
                        Text('${s.confidence}% strength',
                            style: const TextStyle(fontSize: 12, color: AppColors.text2)),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Blurred level row with an unlock hint (no real numbers are on the device).
class _LockedLevels extends StatelessWidget {
  const _LockedLevels();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
      decoration: BoxDecoration(
        color: AppColors.bg.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Stack(
        alignment: Alignment.bottomCenter,
        children: [
          ImageFiltered(
            imageFilter: ImageFilter.blur(sigmaX: 5, sigmaY: 5),
            child: const Row(children: [
              _Lv('Entry', '0.00000', AppColors.text),
              _Lv('Stop loss', '0.00000', AppColors.sell),
              _Lv('TP1', '0.00000', AppColors.buy),
              _Lv('TP2', '0.00000', AppColors.buy),
            ]),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(30),
              border: Border.all(color: AppColors.gold.withValues(alpha: 0.35)),
            ),
            child: const Text('Tap to unlock entry, SL & TP',
                style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: AppColors.gold)),
          ),
        ],
      ),
    );
  }
}

class _Lv extends StatelessWidget {
  final String label, value;
  final Color color;
  const _Lv(this.label, this.value, this.color);

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: AppText.label.copyWith(fontSize: 10.5)),
            const SizedBox(height: 3),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(value, style: AppText.mono(13, color: color)),
            ),
          ],
        ),
      );
}

/// Compact row used in history lists.
class SignalRow extends StatelessWidget {
  final TradeSignal s;
  final VoidCallback onTap;
  const SignalRow({super.key, required this.s, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final pips = s.displayPips;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
        child: Row(
          children: [
            PairAvatar(pair: s.pair, size: 34),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Text(s.pair, style: const TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(width: 6),
                    Text(s.direction,
                        style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w800,
                            color: s.isBuy ? AppColors.buy : AppColors.sell)),
                  ]),
                  const SizedBox(height: 2),
                  Text('${s.resultLabel} · ${Fmt.dateTime(s.closedAt ?? s.openedAt)}',
                      style: AppText.caption),
                ],
              ),
            ),
            Text(Fmt.pips(pips),
                style: AppText.mono(15,
                    weight: FontWeight.w700,
                    color: pips >= 0 ? AppColors.buy : AppColors.sell)),
          ],
        ),
      ),
    );
  }
}
