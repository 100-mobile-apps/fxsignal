import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../models/money.dart';
import '../state/data_stores.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import '../widgets/money_widgets.dart';

/// "Your money": dollars and pips from the real signals at the user's account size and risk,
/// with the strategy and market choices that go with it.
class MoneyScreen extends StatefulWidget {
  const MoneyScreen({super.key});

  @override
  State<MoneyScreen> createState() => _MoneyScreenState();
}

class _MoneyScreenState extends State<MoneyScreen> {
  static const _accounts = [100.0, 1000.0, 5000.0, 10000.0, 20000.0];
  static const _risks = [1.0, 2.0, 5.0, 10.0, 30.0];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final prefs = context.read<SessionStore>().user?.prefs ?? const UserPrefs();
      final store = context.read<MoneyStore>();
      store.useDefaults(prefs.accountSize, prefs.riskPct);
      store.load();
    });
  }

  String _num(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);

  Future<void> _save({double? account, double? risk}) async {
    try {
      await context.read<SessionStore>().updatePreferences(accountSize: account, riskPct: risk);
    } catch (_) {
      // The figures still update on screen; saving can be retried by changing the value again.
    }
  }

  Future<void> _pickAccount(MoneyStore store) async {
    final c = TextEditingController(text: _num(store.account));
    final v = await showDialog<double>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: const Text('Account size (USD)'),
        content: TextField(
          controller: c,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(prefixText: '\$ ', hintText: '10000'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, double.tryParse(c.text.replaceAll(',', '').trim())),
            child: const Text('Apply'),
          ),
        ],
      ),
    );
    if (v == null) return;
    final value = v.clamp(10.0, 10000000.0).toDouble();
    await store.setAccount(value);
    await _save(account: value);
  }

  Future<void> _pickStrategy(StrategyItem item, StrategiesData data) async {
    if (item.optIn) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(item.name),
          content: const Text(
            'This option wins very often because its targets are tiny, but its stop is very wide. '
            'One loss can erase many wins, and a few losses in a row can wipe out weeks of profit. '
            'A high win rate is not the same as a profit. Use it only if you understand this.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Not now')),
            TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('I understand, use it')),
          ],
        ),
      );
      if (ok != true) return;
    }
    await _applyStrategy(item.key);
  }

  Future<void> _applyStrategy(String? key) async {
    try {
      await context.read<SessionStore>().updatePreferences(strategy: key, setStrategy: true);
      if (mounted) context.read<MoneyStore>().load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final store = context.watch<MoneyStore>();
    final prefs = context.select<SessionStore, UserPrefs>((s) => s.user?.prefs ?? const UserPrefs());
    final d = store.data;
    return Scaffold(
      appBar: AppBar(title: const Text('Your money')),
      body: RefreshIndicator(
        onRefresh: store.load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
          children: [
            const InfoBanner(
              icon: Icons.info_outline_rounded,
              text: 'What following the signals this app published would have made for your account size and risk. '
                  'Each signal is counted as two half-size trades with the stop moved as the alerts tell you, after typical trading costs.',
            ),
            const SizedBox(height: 14),
            _controls(store),
            const SizedBox(height: 14),
            if (store.error != null && d == null)
              EmptyState(
                icon: Icons.cloud_off_rounded,
                title: 'Could not load your numbers',
                message: store.error!,
                action: GoldButton(label: 'Try again', onPressed: store.load),
              )
            else if (d == null)
              const Skeleton(height: 220, radius: 20)
            else ...[
              _summary(d),
              const SizedBox(height: 14),
              _riskStrip(d, store.risk),
              if (store.risk >= 20) ...[
                const SizedBox(height: 10),
                InfoBanner(
                  icon: Icons.local_fire_department_rounded,
                  color: AppColors.sell,
                  text: 'At ${_num(store.risk)}% risk, big wins come with big drops. Look at the biggest drop and lowest balance above: '
                      'that is what the account went through on the way.',
                ),
              ],
              const SizedBox(height: 18),
              const SectionHeader('Month by month'),
              _months(d),
              const SizedBox(height: 18),
              const SectionHeader('By market'),
              _markets(d, store),
              const SizedBox(height: 18),
              const SectionHeader('Strategy'),
              _strategies(store, prefs),
              const SizedBox(height: 18),
              const SectionHeader('Recent trades'),
              _trades(d),
              const SizedBox(height: 14),
              Text(d.note, style: AppText.label),
            ],
          ],
        ),
      ),
    );
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
          label: Text(label),
          selected: selected,
          onSelected: (_) => onTap(),
          selectedColor: AppColors.gold.withValues(alpha: 0.2),
          backgroundColor: AppColors.surface,
          side: BorderSide(color: selected ? AppColors.gold : AppColors.border),
          labelStyle: TextStyle(
              color: selected ? AppColors.gold : AppColors.text2, fontWeight: FontWeight.w700, fontSize: 12.5),
          showCheckmark: false,
        ),
      );

  Widget _controls(MoneyStore store) {
    final custom = !_accounts.contains(store.account);
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Account size', style: AppText.label),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (final a in _accounts)
                  _chip(Fmt.money(a).replaceAll('.00', ''), store.account == a, () async {
                    await store.setAccount(a);
                    await _save(account: a);
                  }),
                _chip(custom ? Fmt.money(store.account).replaceAll('.00', '') : 'Other', custom, () => _pickAccount(store)),
              ],
            ),
          ),
          const SizedBox(height: 14),
          const Text('Risk per signal', style: AppText.label),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (final r in _risks)
                  _chip('${_num(r)}%', store.risk == r, () async {
                    await store.setRisk(r);
                    await _save(risk: r);
                  }),
              ],
            ),
          ),
          const SizedBox(height: 14),
          const Text('Markets', style: AppText.label),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _chip('All markets', store.pairsFilter == null, () => store.setPairs(null)),
                _chip('Best 3, picked weekly', store.pairsFilter == 'best', () => store.setPairs('best')),
              ],
            ),
          ),
          if (store.pairsFilter == 'best') ...[
            const SizedBox(height: 8),
            const Text(
              'Each Monday gold plus the best markets of the previous 14 days are picked, and only their next week counts, '
              'the same way the app picks them for you. No hindsight.',
              style: AppText.caption,
            ),
          ],
        ],
      ),
    );
  }

  Widget _stat(String label, String value, {Color? color}) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: AppText.label),
            const SizedBox(height: 3),
            Text(value, style: TextStyle(color: color ?? AppColors.text, fontWeight: FontWeight.w800, fontSize: 15)),
          ],
        ),
      );

  Widget _summary(ResultsData d) {
    final s = d.summary;
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${Fmt.money(d.account).replaceAll('.00', '')} would now be', style: AppText.caption),
          const SizedBox(height: 4),
          Text(Fmt.money(s.endBalance), style: AppText.h1),
          const SizedBox(height: 2),
          Text('${usdSigned(s.profit)}  ·  ${Fmt.pips(s.pips)} pips',
              style: TextStyle(color: usdColor(s.profit), fontWeight: FontWeight.w800, fontSize: 15)),
          if (d.since != null) Text('Since ${Fmt.date(d.since)} · ${s.trades} signals', style: AppText.label),
          const Divider(height: 24),
          Row(children: [
            _stat('Win rate', '${s.winRate.toStringAsFixed(0)}%'),
            _stat('Biggest drop', '-${s.maxDropPct.toStringAsFixed(0)}%', color: AppColors.sell),
            _stat('Lowest balance', Fmt.money(s.lowestBalance).replaceAll('.00', '')),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            _stat('Best signal', usdSigned(s.bestTrade), color: AppColors.buy),
            _stat('Worst signal', usdSigned(s.worstTrade), color: AppColors.sell),
            _stat('Longest losing run', '${s.worstLosingRun}'),
          ]),
        ],
      ),
    );
  }

  Widget _riskStrip(ResultsData d, double selected) {
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('The same signals at different risk', style: AppText.title),
          const SizedBox(height: 10),
          Row(children: const [
            Expanded(flex: 2, child: Text('Risk', style: AppText.label)),
            Expanded(flex: 4, child: Text('Profit', style: AppText.label)),
            Expanded(flex: 3, child: Text('Biggest drop', style: AppText.label)),
            Expanded(flex: 4, child: Text('Lowest', style: AppText.label)),
          ]),
          const SizedBox(height: 6),
          for (final r in d.riskLevels)
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 6),
              decoration: BoxDecoration(
                color: r.risk == selected ? AppColors.gold.withValues(alpha: 0.10) : Colors.transparent,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(children: [
                Expanded(flex: 2, child: Text('${_num(r.risk)}%', style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.text))),
                Expanded(
                    flex: 4,
                    child: Text(usdSigned(r.profit).replaceAll('.00', ''),
                        style: TextStyle(color: usdColor(r.profit), fontWeight: FontWeight.w800, fontSize: 13))),
                Expanded(flex: 3, child: Text('-${r.maxDropPct.toStringAsFixed(0)}%', style: const TextStyle(color: AppColors.sell, fontSize: 13))),
                Expanded(flex: 4, child: Text(Fmt.money(r.lowestBalance).replaceAll('.00', ''), style: AppText.caption)),
              ]),
            ),
        ],
      ),
    );
  }

  Widget _months(ResultsData d) {
    if (d.months.isEmpty) {
      return const Text('No closed signals yet.', style: AppText.body);
    }
    return GlassCard(
      child: Column(
        children: [
          for (final m in d.months.reversed)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 7),
              child: Row(children: [
                Expanded(flex: 3, child: Text(m.month, style: const TextStyle(color: AppColors.text, fontWeight: FontWeight.w700))),
                Expanded(flex: 3, child: Text('${m.wins}/${m.trades} won', style: AppText.caption)),
                Expanded(flex: 3, child: Text('${Fmt.pips(m.pips)} pips', style: AppText.caption)),
                Expanded(
                    flex: 4,
                    child: Text(usdSigned(m.profit).replaceAll('.00', ''),
                        textAlign: TextAlign.right,
                        style: TextStyle(color: usdColor(m.profit), fontWeight: FontWeight.w800))),
              ]),
            ),
        ],
      ),
    );
  }

  (String, Color) _badge(String status) => switch (status) {
        'top' => ('TOP NOW', AppColors.buy),
        'cold' => ('COLD', AppColors.sell),
        'neutral' => ('OK', AppColors.info),
        _ => ('NEW', AppColors.text3),
      };

  Widget _markets(ResultsData d, MoneyStore store) {
    if (d.byMarket.isEmpty) return const Text('No closed signals yet.', style: AppText.body);
    final riskUsd = store.account * store.risk / 100;
    return Column(
      children: [
        for (final m in d.byMarket)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: GlassCard(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Text(m.pair, style: AppText.title),
                    const SizedBox(width: 8),
                    Pill(text: _badge(m.status).$1, color: _badge(m.status).$2),
                    const Spacer(),
                    Text(usdSigned(m.profit).replaceAll('.00', ''),
                        style: TextStyle(color: usdColor(m.profit), fontWeight: FontWeight.w800, fontSize: 15)),
                  ]),
                  const SizedBox(height: 4),
                  Text('${m.trades} signals · ${m.winRate.toStringAsFixed(0)}% won · ${Fmt.pips(m.pips)} pips · biggest drop -${m.maxDropPct.toStringAsFixed(0)}%',
                      style: AppText.caption),
                  if (m.minRisk > riskUsd && m.minRisk > 0)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                          'Small account note: the smallest position here risks about ${Fmt.money(m.minRisk)}, more than your ${Fmt.money(riskUsd)}.',
                          style: const TextStyle(fontSize: 12, color: AppColors.warn)),
                    ),
                ],
              ),
            ),
          ),
        const Text('Each market shows what you would have made trading only that market.', style: AppText.label),
      ],
    );
  }

  Widget _strategies(MoneyStore store, UserPrefs prefs) {
    final s = store.strategies;
    if (s == null) return const Skeleton(height: 120, radius: 20);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GlassCard(
          onTap: () => _applyStrategy(null),
          borderColor: !prefs.strategyChosen ? AppColors.gold : null,
          child: Row(children: [
            Icon(!prefs.strategyChosen ? Icons.radio_button_checked : Icons.radio_button_off,
                color: !prefs.strategyChosen ? AppColors.gold : AppColors.text3, size: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('Always the best one (recommended)', style: AppText.title),
                const SizedBox(height: 2),
                Text('Right now: ${_nameOf(s, s.defaultKey)}. It switches automatically when another does better on real results.',
                    style: AppText.caption),
              ]),
            ),
          ]),
        ),
        const SizedBox(height: 8),
        for (final it in s.items)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: GlassCard(
              onTap: () => _pickStrategy(it, s),
              borderColor: prefs.strategyChosen && prefs.strategy == it.key ? AppColors.gold : null,
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Icon(
                      prefs.strategyChosen && prefs.strategy == it.key ? Icons.radio_button_checked : Icons.radio_button_off,
                      color: prefs.strategyChosen && prefs.strategy == it.key ? AppColors.gold : AppColors.text3,
                      size: 20),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      Text(it.name, style: AppText.title),
                      if (it.key == s.defaultKey) const Pill(text: 'BEST NOW', color: AppColors.buy),
                      if (it.optIn) const Pill(text: 'HIGH RISK', color: AppColors.sell),
                    ]),
                    const SizedBox(height: 3),
                    Text(it.blurb, style: AppText.caption),
                    const SizedBox(height: 4),
                    Text(
                      it.live == null || it.live!.trades == 0
                          ? 'Live results: still collecting'
                          : 'Live results: ${it.live!.trades} signals · ${it.live!.winRate.toStringAsFixed(0)}% won · ${Fmt.pips(it.live!.pips)} pips',
                      style: AppText.label,
                    ),
                  ]),
                ),
              ]),
            ),
          ),
        const Text(
            'You only get alerts for the strategy you pick. New strategies need about 20 live signals before they can become the best one.',
            style: AppText.label),
      ],
    );
  }

  String _nameOf(StrategiesData s, String key) {
    for (final i in s.items) {
      if (i.key == key) return i.name;
    }
    return key;
  }

  Widget _trades(ResultsData d) {
    if (d.trades.isEmpty) return const Text('No closed signals yet.', style: AppText.body);
    return GlassCard(
      child: Column(
        children: [
          for (final t in d.trades.take(25))
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(children: [
                Expanded(
                    flex: 4,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${t.direction} ${t.pair}', style: const TextStyle(color: AppColors.text, fontWeight: FontWeight.w700, fontSize: 13)),
                      Text(Fmt.dateTime(t.closedAt), style: AppText.label),
                    ])),
                Expanded(flex: 3, child: Text('${Fmt.pips(t.pips)} pips', style: AppText.caption)),
                Expanded(
                    flex: 4,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(usdSigned(t.usd), style: TextStyle(color: usdColor(t.usd), fontWeight: FontWeight.w800)),
                      Text('risked ${Fmt.money(t.risked).replaceAll('.00', '')}', style: AppText.label),
                    ])),
              ]),
            ),
        ],
      ),
    );
  }
}
