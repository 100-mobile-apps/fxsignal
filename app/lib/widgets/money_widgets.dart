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

/// The high-risk level shown next to the user's own on every signal.
const double showcaseRisk = 30;

String _riskTxt(double r) => r.toStringAsFixed(r == r.roundToDouble() ? 0 : 1);

/// Dollars on a signal card: what it could make or lose (open) or what it made (closed),
/// at the user's own risk and, unless that already is 30%, at 30% too.
class SignalMoneyLine extends StatelessWidget {
  final TradeSignal signal;
  const SignalMoneyLine({super.key, required this.signal});

  @override
  Widget build(BuildContext context) {
    final p = _prefsOf(context);
    final risks = [p.riskPct, if (p.riskPct != showcaseRisk) showcaseRisk];
    final closed = !signal.isRunning && signal.pips != null;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.surface2,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.payments_outlined, size: 15, color: AppColors.gold),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '${closed ? 'Result' : 'Could make or lose'} on ${Fmt.money(p.accountSize).replaceAll('.00', '')}',
                  style: AppText.caption,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          for (final r in risks) _row(r, moneyFor(signal, p.accountSize, r), closed),
        ],
      ),
    );
  }

  Widget _row(double risk, MoneyView m, bool closed) {
    const strong = TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5);
    final label = Text('At ${_riskTxt(risk)}%',
        style: TextStyle(
            fontSize: 12, fontWeight: FontWeight.w700, color: risk >= 20 ? AppColors.warn : AppColors.text2));
    final Widget value;
    if (closed) {
      final v = m.usdFor(signal.pips!);
      value = Text(usdSigned(v), style: strong.copyWith(color: usdColor(v)));
    } else {
      value = Text.rich(
          TextSpan(children: [
            const TextSpan(text: 'lose ', style: TextStyle(color: AppColors.text3, fontWeight: FontWeight.w400)),
            TextSpan(text: usdSigned(m.stop.usd), style: const TextStyle(color: AppColors.sell)),
            const TextSpan(text: '  ·  make up to ', style: TextStyle(color: AppColors.text3, fontWeight: FontWeight.w400)),
            TextSpan(text: usdSigned(m.tp2.usd), style: const TextStyle(color: AppColors.buy)),
          ]),
          style: strong);
    }
    return Padding(
      padding: const EdgeInsets.only(top: 3),
      child: Wrap(
        spacing: 8,
        runSpacing: 1,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [SizedBox(width: 44, child: label), value],
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
    final riskTxt = _riskTxt(p.riskPct);
    final hi = p.riskPct == showcaseRisk ? null : moneyFor(signal, p.accountSize, showcaseRisk);
    const head = TextStyle(fontSize: 11.5, color: AppColors.text3, fontWeight: FontWeight.w600);
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.payments_outlined, size: 18, color: AppColors.gold),
              const SizedBox(width: 8),
              const Expanded(child: Text('What this signal could do to your account', style: AppText.title)),
            ],
          ),
          const SizedBox(height: 4),
          Text('Account ${Fmt.money(p.accountSize).replaceAll('.00', '')} · risk $riskTxt% (change in Your money)',
              style: AppText.caption),
          const SizedBox(height: 12),
          Row(
            children: [
              const Expanded(child: Text('If the trade ends at', style: head)),
              const SizedBox(width: 52, child: Text('Pips', textAlign: TextAlign.right, style: head)),
              SizedBox(width: 82, child: Text('At $riskTxt%', textAlign: TextAlign.right, style: head)),
              if (hi != null)
                SizedBox(
                    width: 82,
                    child: Text('At ${_riskTxt(showcaseRisk)}%',
                        textAlign: TextAlign.right, style: head.copyWith(color: AppColors.warn))),
            ],
          ),
          for (var i = 0; i < m.lines.length; i++)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  Expanded(child: Text(m.lines[i].label, style: const TextStyle(color: AppColors.text, fontSize: 13))),
                  SizedBox(
                      width: 52,
                      child: Text(Fmt.pips(m.lines[i].pips), textAlign: TextAlign.right, style: AppText.caption)),
                  SizedBox(
                    width: 82,
                    child: Text(usdSigned(m.lines[i].usd),
                        textAlign: TextAlign.right,
                        style: TextStyle(color: usdColor(m.lines[i].usd), fontWeight: FontWeight.w800, fontSize: 13.5)),
                  ),
                  if (hi != null)
                    SizedBox(
                      width: 82,
                      child: Text(usdSigned(hi.lines[i].usd),
                          textAlign: TextAlign.right,
                          style: TextStyle(color: usdColor(hi.lines[i].usd), fontWeight: FontWeight.w800, fontSize: 13.5)),
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
          if (hi != null) ...[
            const SizedBox(height: 10),
            InfoBanner(
              icon: Icons.local_fire_department_rounded,
              color: AppColors.warn,
              text: 'At ${_riskTxt(showcaseRisk)}% each loss takes ${_riskTxt(showcaseRisk)}% of the account. Three losses in a row '
                  'would cut it by about ${((1 - _pow(1 - showcaseRisk / 100, 3)) * 100).toStringAsFixed(0)}%. '
                  'Change your risk in Your money.',
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
