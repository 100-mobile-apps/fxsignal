import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../models/money.dart';
import '../state/session_store.dart';
import 'common.dart';

String usdSigned(double v) => v >= 0 ? '+${Fmt.money(v)}' : Fmt.money(v);

Color usdColor(double v) => v > 0 ? AppColors.buy : (v < 0 ? AppColors.sell : AppColors.text2);

UserPrefs _prefsOf(BuildContext context) =>
    context.select<SessionStore, UserPrefs>((s) => s.user?.prefs ?? const UserPrefs());

/// One line on a signal card: what it would make or lose for the user's account size and risk.
class SignalMoneyLine extends StatelessWidget {
  final TradeSignal signal;
  const SignalMoneyLine({super.key, required this.signal});

  @override
  Widget build(BuildContext context) {
    final p = _prefsOf(context);
    final m = moneyFor(signal, p.accountSize, p.riskPct);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.surface2,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          const Icon(Icons.payments_outlined, size: 16, color: AppColors.gold),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'At ${p.riskPct.toStringAsFixed(p.riskPct == p.riskPct.roundToDouble() ? 0 : 1)}% of ${Fmt.money(p.accountSize).replaceAll('.00', '')}',
              style: AppText.caption,
            ),
          ),
          Text(usdSigned(m.stop.usd),
              style: const TextStyle(color: AppColors.sell, fontWeight: FontWeight.w800, fontSize: 12.5)),
          const Text('  ·  ', style: TextStyle(color: AppColors.text3)),
          Text(usdSigned(m.locked.usd),
              style: const TextStyle(color: AppColors.buy, fontWeight: FontWeight.w800, fontSize: 12.5)),
          const Text('  ·  ', style: TextStyle(color: AppColors.text3)),
          Text(usdSigned(m.tp2.usd),
              style: const TextStyle(color: AppColors.buy, fontWeight: FontWeight.w800, fontSize: 12.5)),
        ],
      ),
    );
  }
}

/// Full breakdown on the signal screen: lot size and every way the trade can end, in dollars and pips.
class SignalMoneyTable extends StatelessWidget {
  final TradeSignal signal;
  const SignalMoneyTable({super.key, required this.signal});

  @override
  Widget build(BuildContext context) {
    final p = _prefsOf(context);
    final m = moneyFor(signal, p.accountSize, p.riskPct);
    final riskTxt = p.riskPct.toStringAsFixed(p.riskPct == p.riskPct.roundToDouble() ? 0 : 1);
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.payments_outlined, size: 18, color: AppColors.gold),
              const SizedBox(width: 8),
              const Text('What this signal could do to your account', style: AppText.title),
            ],
          ),
          const SizedBox(height: 4),
          Text('Account ${Fmt.money(p.accountSize).replaceAll('.00', '')} · risk $riskTxt% (change in Your money)',
              style: AppText.caption),
          const SizedBox(height: 12),
          for (final l in m.lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  Expanded(child: Text(l.label, style: const TextStyle(color: AppColors.text, fontSize: 13.5))),
                  Text('${Fmt.pips(l.pips)} pips', style: AppText.caption),
                  const SizedBox(width: 12),
                  SizedBox(
                    width: 96,
                    child: Text(usdSigned(l.usd),
                        textAlign: TextAlign.right,
                        style: TextStyle(color: usdColor(l.usd), fontWeight: FontWeight.w800, fontSize: 14)),
                  ),
                ],
              ),
            ),
          const Divider(height: 22),
          Text(
            'Trade ${m.lots.toStringAsFixed(2)} lots in total: two trades of ${(m.lots / 2).toStringAsFixed(2)} lots, one set to TP1 and one to TP2. '
            'Risk at the stop: about ${Fmt.money(m.actualRisk)}.',
            style: AppText.body,
          ),
          if (m.tooSmall) ...[
            const SizedBox(height: 10),
            InfoBanner(
              icon: Icons.warning_amber_rounded,
              color: AppColors.warn,
              text: 'Your account is small for this signal. The smallest possible position (two trades of 0.01 lot) risks about '
                  '${Fmt.money(m.actualRisk)}, more than the ${Fmt.money(m.wantedRisk)} your $riskTxt% would allow.',
            ),
          ],
          if (p.riskPct >= 20) ...[
            const SizedBox(height: 10),
            InfoBanner(
              icon: Icons.local_fire_department_rounded,
              color: AppColors.sell,
              text: 'Very high risk. Three losses in a row at $riskTxt% would cut your account by about '
                  '${((1 - _pow(1 - p.riskPct / 100, 3)) * 100).toStringAsFixed(0)}%.',
            ),
          ],
          const SizedBox(height: 8),
          const Text('After typical trading costs. Past results do not guarantee future results.', style: AppText.label),
        ],
      ),
    );
  }
}

double _pow(double base, int n) {
  var r = 1.0;
  for (var i = 0; i < n; i++) {
    r *= base;
  }
  return r;
}
