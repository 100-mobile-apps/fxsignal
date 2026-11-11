import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../state/bot_store.dart';
import '../state/data_stores.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import 'paywall_screen.dart';
import 'tools_screen.dart' show pipValuePerLot;

void openBot(BuildContext context) => Navigator.of(context).push(MaterialPageRoute(
    builder: (_) => ChangeNotifierProvider(
          create: (c) => BotStore(c.read<SessionStore>().api)..load(),
          child: const BotScreen(),
        )));

/// Premium trading bot: paper auto-pilot, copy-trade assistant and Deriv auto-trading.
class BotScreen extends StatefulWidget {
  const BotScreen({super.key});
  @override
  State<BotScreen> createState() => _BotScreenState();
}

class _BotScreenState extends State<BotScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final bot = context.watch<BotStore>();
    final premium = context.select<SessionStore, bool>((s) => s.user?.premium ?? false);
    final tabs = [
      if (bot.server['paper'] != false) ('Auto-pilot', Icons.smart_toy_outlined),
      if (bot.server['copy'] != false) ('Copy trade', Icons.content_copy_rounded),
      if (bot.derivAvailable) ('Deriv', Icons.bolt_rounded),
    ];
    final tab = _tab.clamp(0, tabs.isEmpty ? 0 : tabs.length - 1);
    final name = tabs.isEmpty ? '' : tabs[tab].$1;

    final body = switch (name) {
      'Auto-pilot' => _PaperTab(bot: bot),
      'Copy trade' => const _CopyTab(),
      'Deriv' => _DerivTab(bot: bot, premium: premium),
      _ => const EmptyState(icon: Icons.smart_toy_outlined, title: 'Bot unavailable', message: 'Please check back later.'),
    };

    return Scaffold(
      appBar: AppBar(title: const Text('Trading bot')),
      body: bot.loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
              children: [
                if (tabs.length > 1)
                  SegmentedButton<int>(
                    segments: [
                      for (final (i, t) in tabs.indexed)
                        ButtonSegment(value: i, label: Text(t.$1), icon: Icon(t.$2, size: 18)),
                    ],
                    selected: {tab},
                    showSelectedIcon: false,
                    onSelectionChanged: (v) => setState(() => _tab = v.first),
                  ),
                const SizedBox(height: 16),
                if (!premium) const _UpgradeCard(),
                if (!premium) const SizedBox(height: 16),
                IgnorePointer(
                  ignoring: !premium,
                  child: Opacity(opacity: premium ? 1 : 0.45, child: body),
                ),
              ],
            ),
    );
  }
}

class _UpgradeCard extends StatelessWidget {
  const _UpgradeCard();
  @override
  Widget build(BuildContext context) => GlassCard(
        borderColor: AppColors.gold,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Row(children: [
            Icon(Icons.workspace_premium_rounded, color: AppColors.gold),
            SizedBox(width: 8),
            Text('Premium feature', style: AppText.title),
          ]),
          const SizedBox(height: 8),
          const Text(
              'Preview below. With Premium the bot follows every signal for you: '
              'a paper account to test the strategy, one-tap trade copying with the right lot size, '
              'and automatic trades on your Deriv account.',
              style: AppText.body),
          const SizedBox(height: 14),
          GoldButton(label: 'Unlock the bot', onPressed: () => openPaywall(context)),
        ]),
      );
}

// ---------------- Auto-pilot (paper) ----------------

class _PaperTab extends StatelessWidget {
  final BotStore bot;
  const _PaperTab({required this.bot});

  @override
  Widget build(BuildContext context) {
    final s = bot.settings;
    if (s.paperSince == null) {
      return GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Paper auto-pilot', style: AppText.title),
          const SizedBox(height: 6),
          const Text(
              'The bot takes every signal on a virtual account, risking a fixed % per trade, '
              'so you can see how following the signals would have worked, without real money. '
              'It starts with the last 30 days.',
              style: AppText.body),
          const SizedBox(height: 14),
          _RiskPicker(bot: bot),
          const SizedBox(height: 14),
          GoldButton(label: 'Start with \$10,000 virtual', onPressed: () => bot.startPaper(10000)),
        ]),
      );
    }
    final trades = bot.paperTrades;
    final closed = trades.where((t) => !t.signal.isRunning).toList();
    final open = trades.where((t) => t.signal.isRunning).toList();
    final bal = bot.paperBalance;
    final gain = (bal / s.paperStart - 1) * 100;
    final wins = closed.where((t) => t.r > 0).length;
    final openPnl = open.fold<double>(0, (a, t) => a + t.pnl);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      GlassCard(
        gradient: AppColors.cardGradient,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Virtual balance', style: AppText.caption),
          const SizedBox(height: 4),
          Text('\$${bal.toStringAsFixed(2)}', style: AppText.mono(28, weight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text('${gain >= 0 ? '+' : ''}${gain.toStringAsFixed(1)}% since ${_date(s.paperSince!)}',
              style: TextStyle(color: gain >= 0 ? AppColors.buy : AppColors.sell, fontWeight: FontWeight.w600)),
          const SizedBox(height: 12),
          Wrap(spacing: 18, runSpacing: 8, children: [
            _Kpi('Trades', '${closed.length}'),
            _Kpi('Win rate', closed.isEmpty ? '—' : '${(100 * wins / closed.length).round()}%'),
            _Kpi('Open P/L', '${openPnl >= 0 ? '+' : ''}\$${openPnl.toStringAsFixed(2)}'),
            _Kpi('Risk', '${s.riskPct.toStringAsFixed(1)}% / trade'),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      const InfoBanner(
          icon: Icons.info_outline_rounded,
          text: 'Paper results use each signal\'s published result in pips, before spread and commission. '
              'Past results don\'t guarantee future ones.'),
      const SizedBox(height: 16),
      const SectionHeader('Bot trades'),
      if (trades.isEmpty)
        const Padding(padding: EdgeInsets.all(12), child: Text('No signals yet since the bot started.', style: AppText.body)),
      for (final t in trades.reversed.take(50))
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: GlassCard(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Row(children: [
              PairAvatar(pair: t.signal.pair, size: 34),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${t.signal.pair} ${t.signal.direction}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text(t.signal.isRunning ? 'Open · ${_date(t.signal.openedAt)}' : _date(t.signal.openedAt),
                      style: AppText.caption),
                ]),
              ),
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text('${t.pnl >= 0 ? '+' : ''}\$${t.pnl.toStringAsFixed(2)}',
                    style: AppText.mono(14, color: t.pnl >= 0 ? AppColors.buy : AppColors.sell)),
                Text('${t.r >= 0 ? '+' : ''}${t.r.toStringAsFixed(2)}R', style: AppText.caption),
              ]),
            ]),
          ),
        ),
      const SizedBox(height: 8),
      _RiskPicker(bot: bot),
      TextButton(onPressed: () => bot.stopPaper(), child: const Text('Reset the paper account')),
    ]);
  }
}

class _Kpi extends StatelessWidget {
  final String label, value;
  const _Kpi(this.label, this.value);
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: AppText.caption),
        Text(value, style: AppText.mono(15)),
      ]);
}

class _RiskPicker extends StatelessWidget {
  final BotStore bot;
  const _RiskPicker({required this.bot});
  @override
  Widget build(BuildContext context) {
    final s = bot.settings;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('Risk per trade: ${s.riskPct.toStringAsFixed(1)}% of balance', style: AppText.label),
      Slider(
        value: s.riskPct,
        min: 0.5,
        max: 3,
        divisions: 5,
        label: '${s.riskPct.toStringAsFixed(1)}%',
        onChanged: (v) => bot.update(s.copyWith(riskPct: v)),
      ),
      const Text('Markets the bot follows', style: AppText.label),
      const SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final p in AppConfig.pairs)
          FilterChip(
            label: Text(p),
            selected: s.wants(p),
            onSelected: (on) {
              final all = s.markets.isEmpty ? AppConfig.pairs.toSet() : {...s.markets};
              on ? all.add(p) : all.remove(p);
              if (all.isNotEmpty) bot.update(s.copyWith(markets: all.length == AppConfig.pairs.length ? <String>{} : all));
            },
          ),
      ]),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        value: s.useTp2,
        onChanged: (v) => bot.update(s.copyWith(useTp2: v)),
        title: const Text('Aim for TP2 (Deriv trades)', style: TextStyle(fontWeight: FontWeight.w600)),
        subtitle: const Text('Off: close at TP1, which is hit more often', style: AppText.caption),
      ),
    ]);
  }
}

// ---------------- Copy trade ----------------

class _CopyTab extends StatefulWidget {
  const _CopyTab();
  @override
  State<_CopyTab> createState() => _CopyTabState();
}

class _CopyTabState extends State<_CopyTab> {
  final _balance = TextEditingController(text: '1000');
  double _risk = 1;

  @override
  void dispose() {
    _balance.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final running = context.watch<SignalsStore>().running.where((s) => !s.locked).toList();
    final balance = double.tryParse(_balance.text.replaceAll(',', '')) ?? 0;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Copy-trade assistant', style: AppText.title),
          const SizedBox(height: 6),
          const Text('Enter your account balance once. Each live signal gets the lot size for your risk, '
              'ready to copy into MetaTrader or any broker app.', style: AppText.body),
          const SizedBox(height: 12),
          TextField(
            controller: _balance,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Account balance (USD)', prefixText: '\$ '),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          Text('Risk per trade: ${_risk.toStringAsFixed(1)}%', style: AppText.label),
          Slider(value: _risk, min: 0.5, max: 3, divisions: 5, onChanged: (v) => setState(() => _risk = v)),
        ]),
      ),
      const SizedBox(height: 16),
      const SectionHeader('Live signals'),
      if (running.isEmpty)
        const Padding(padding: EdgeInsets.all(12), child: Text('No live signals right now. New ones appear here.', style: AppText.body)),
      for (final s in running) _CopyCard(signal: s, balance: balance, risk: _risk),
    ]);
  }
}

class _CopyCard extends StatelessWidget {
  final TradeSignal signal;
  final double balance, risk;
  const _CopyCard({required this.signal, required this.balance, required this.risk});

  @override
  Widget build(BuildContext context) {
    final s = signal;
    final lots = BotStore.lots(
        pair: s.pair, balance: balance, riskPct: risk, entry: s.entry, stopLoss: s.stopLoss,
        pipValuePerLot: pipValuePerLot(s.pair, s.currentPrice ?? s.entry));
    String p(double v) => Fmt.price(s.pair, v);
    final ticket = '${s.direction} ${s.pair} ${lots.toStringAsFixed(2)} lots\n'
        'Entry ${p(s.entry)}\nSL ${p(s.stopLoss)}\nTP1 ${p(s.tp1)}\nTP2 ${p(s.tp2)}';
    final color = s.isBuy ? AppColors.buy : AppColors.sell;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            PairAvatar(pair: s.pair, size: 34),
            const SizedBox(width: 10),
            Text(s.pair, style: AppText.title),
            const SizedBox(width: 8),
            Text(s.direction, style: TextStyle(color: color, fontWeight: FontWeight.w800)),
            const Spacer(),
            Text('${lots.toStringAsFixed(2)} lots', style: AppText.mono(16, weight: FontWeight.w800)),
          ]),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final (l, v) in [('Entry', s.entry), ('SL', s.stopLoss), ('TP1', s.tp1), ('TP2', s.tp2)])
              ActionChip(
                label: Text('$l ${p(v)}', style: AppText.mono(12.5)),
                onPressed: () => _copy(context, p(v), '$l copied'),
              ),
          ]),
          const SizedBox(height: 6),
          Row(children: [
            TextButton.icon(
                onPressed: () => _copy(context, ticket, 'Trade copied'),
                icon: const Icon(Icons.copy_rounded, size: 18),
                label: const Text('Copy all')),
            TextButton.icon(
                onPressed: () => launchUrl(Uri.parse('https://play.google.com/store/apps/details?id=net.metaquotes.metatrader5'),
                    mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.open_in_new_rounded, size: 18),
                label: const Text('Open MetaTrader')),
          ]),
        ]),
      ),
    );
  }

  void _copy(BuildContext context, String text, String msg) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg), duration: const Duration(seconds: 1)));
  }
}

// ---------------- Deriv ----------------

class _DerivTab extends StatefulWidget {
  final BotStore bot;
  final bool premium;
  const _DerivTab({required this.bot, required this.premium});
  @override
  State<_DerivTab> createState() => _DerivTabState();
}

class _DerivTabState extends State<_DerivTab> {
  final _token = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.bot.hasToken) widget.bot.refreshAccounts();
  }

  @override
  void dispose() {
    _token.dispose();
    super.dispose();
  }

  Future<void> _connect() async {
    setState(() => _busy = true);
    await widget.bot.saveToken(_token.text);
    _token.clear();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _toggle(bool on) async {
    final bot = widget.bot;
    if (on && !bot.settings.accountDemo) {
      final ok = await _confirmReal();
      if (ok != true) return;
    }
    await bot.setDeriv(on);
    if (on && mounted) {
      await bot.runNow(context.read<SignalsStore>().running, premium: widget.premium);
    }
  }

  Future<bool?> _confirmReal() {
    final typed = TextEditingController();
    return showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => AlertDialog(
          title: const Text('Trade with real money?'),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text(
                'The bot will open real trades on your Deriv account automatically. Signals can and do lose. '
                'Only risk money you can afford to lose. Type REAL to confirm.'),
            const SizedBox(height: 12),
            TextField(controller: typed, onChanged: (_) => set(() {}), autofocus: true),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
            TextButton(
                onPressed: typed.text.trim() == 'REAL' ? () => Navigator.pop(c, true) : null,
                child: const Text('Start real trading')),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bot = widget.bot;
    final s = bot.settings;
    if (!bot.hasToken) {
      return GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Auto-trade on Deriv', style: AppText.title),
          const SizedBox(height: 6),
          const Text(
              'The bot places each new signal on your Deriv Options account as a Multipliers trade, '
              'with the stop loss and take profit set for you.\n\n'
              '1. Log in at developers.deriv.com and create a Personal Access Token with the "Trade" scope only.\n'
              '2. Paste it below. It is stored encrypted on this phone and never sent to our server.\n'
              '3. Start on your demo account.',
              style: AppText.body),
          const SizedBox(height: 12),
          TextField(
            controller: _token,
            obscureText: true,
            autocorrect: false,
            enableSuggestions: false,
            decoration: const InputDecoration(labelText: 'Deriv API token'),
          ),
          if (bot.error != null) ...[
            const SizedBox(height: 10),
            InfoBanner(icon: Icons.error_outline_rounded, color: AppColors.sell, text: bot.error!),
          ],
          const SizedBox(height: 12),
          GoldButton(label: 'Connect Deriv', loading: _busy, onPressed: _connect),
          TextButton(
              onPressed: () => launchUrl(Uri.parse('https://developers.deriv.com'), mode: LaunchMode.externalApplication),
              child: const Text('Open developers.deriv.com')),
        ]),
      );
    }
    final account = bot.accounts.where((a) => a.id == s.accountId).firstOrNull;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      GlassCard(
        borderColor: s.derivOn ? (s.accountDemo ? AppColors.buy : AppColors.sell) : null,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: s.derivOn,
            onChanged: s.accountId == null ? null : _toggle,
            title: Text(s.derivOn ? 'Auto-trading is ON' : 'Auto-trading is off', style: AppText.title),
            subtitle: Text(
                s.accountDemo ? 'Demo account: no real money' : 'REAL account: trades use real money',
                style: TextStyle(color: s.accountDemo ? AppColors.text2 : AppColors.sell, fontSize: 12)),
          ),
          const Text('Account', style: AppText.label),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final a in bot.accounts)
              ChoiceChip(
                label: Text('${a.demo ? 'Demo' : 'Real'} · ${a.id} · \$${a.balance.toStringAsFixed(2)}'),
                selected: a.id == s.accountId,
                onSelected: s.derivOn ? null : (_) => bot.update(s.copyWith(accountId: a.id, accountDemo: a.demo)),
              ),
          ]),
          if (account == null && bot.accounts.isEmpty)
            const Padding(padding: EdgeInsets.only(top: 6), child: Text('Loading accounts…', style: AppText.caption)),
          const SizedBox(height: 10),
          Text('Max open trades: ${s.maxOpen}', style: AppText.label),
          Slider(value: s.maxOpen.toDouble(), min: 1, max: 6, divisions: 5,
              onChanged: (v) => bot.update(s.copyWith(maxOpen: v.round()))),
          Text('Stop for the day after losing ${s.dailyLossPct.toStringAsFixed(0)}% of the balance', style: AppText.label),
          Slider(value: s.dailyLossPct, min: 2, max: 10, divisions: 8,
              onChanged: (v) => bot.update(s.copyWith(dailyLossPct: v))),
        ]),
      ),
      const SizedBox(height: 12),
      _RiskPicker(bot: bot),
      const InfoBanner(
          icon: Icons.schedule_rounded,
          color: AppColors.warn,
          text: 'The bot checks for new signals about every 15 minutes while your phone has internet, and skips a '
              'signal if the price has already moved too far from the entry. Trades are Deriv Multipliers, '
              'not MetaTrader positions.'),
      const SizedBox(height: 16),
      SectionHeader('Activity', action: 'Check now',
          onAction: () => bot.runNow(context.read<SignalsStore>().running, premium: widget.premium)),
      if (bot.log.isEmpty) const Padding(padding: EdgeInsets.all(12), child: Text('No activity yet.', style: AppText.body)),
      for (final e in bot.log.take(30))
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          leading: Icon(e.ok ? Icons.check_circle_outline_rounded : Icons.info_outline_rounded,
              color: e.ok ? AppColors.buy : AppColors.warn, size: 20),
          title: Text(e.text, style: const TextStyle(fontSize: 13)),
          subtitle: Text(_date(e.at, time: true), style: AppText.caption),
        ),
      const SizedBox(height: 8),
      TextButton(onPressed: () => bot.disconnect(), child: const Text('Disconnect Deriv and delete the token')),
    ]);
  }
}

String _date(DateTime d, {bool time = false}) {
  final l = d.toLocal();
  const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final s = '${l.day} ${m[l.month - 1]}';
  return time ? '$s ${l.hour.toString().padLeft(2, '0')}:${l.minute.toString().padLeft(2, '0')}' : s;
}
