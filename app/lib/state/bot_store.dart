import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/format.dart';
import '../data/api.dart';
import '../models/models.dart';
import '../services/background_signal_service.dart';
import '../services/deriv_client.dart';

/// Trading bot settings, saved on the phone.
class BotSettings {
  /// Paper auto-pilot: start time and virtual balance.
  final DateTime? paperSince;
  final double paperStart;

  /// Shared by all modes.
  final double riskPct;
  final Set<String> markets;
  final bool useTp2;

  /// Deriv auto-trading.
  final bool derivOn;
  final DateTime? derivSince;
  final String? accountId;
  final bool accountDemo;
  final int maxOpen;
  final double dailyLossPct;

  const BotSettings({
    this.paperSince,
    this.paperStart = 10000,
    this.riskPct = 1,
    this.markets = const {},
    this.useTp2 = false,
    this.derivOn = false,
    this.derivSince,
    this.accountId,
    this.accountDemo = true,
    this.maxOpen = 3,
    this.dailyLossPct = 5,
  });

  bool wants(String pair) => markets.isEmpty || markets.contains(pair);

  factory BotSettings.fromJson(Map<String, dynamic> j) => BotSettings(
        paperSince: DateTime.tryParse('${j['paper_since'] ?? ''}'),
        paperStart: (j['paper_start'] as num?)?.toDouble() ?? 10000,
        riskPct: (j['risk_pct'] as num?)?.toDouble() ?? 1,
        markets: ((j['markets'] as List?) ?? const []).cast<String>().toSet(),
        useTp2: j['use_tp2'] == true,
        derivOn: j['deriv_on'] == true,
        derivSince: DateTime.tryParse('${j['deriv_since'] ?? ''}'),
        accountId: j['account_id'] as String?,
        accountDemo: j['account_demo'] != false,
        maxOpen: (j['max_open'] as num?)?.toInt() ?? 3,
        dailyLossPct: (j['daily_loss_pct'] as num?)?.toDouble() ?? 5,
      );

  Map<String, dynamic> toJson() => {
        'paper_since': paperSince?.toIso8601String(),
        'paper_start': paperStart,
        'risk_pct': riskPct,
        'markets': markets.toList(),
        'use_tp2': useTp2,
        'deriv_on': derivOn,
        'deriv_since': derivSince?.toIso8601String(),
        'account_id': accountId,
        'account_demo': accountDemo,
        'max_open': maxOpen,
        'daily_loss_pct': dailyLossPct,
      };

  BotSettings copyWith({
    DateTime? paperSince,
    bool clearPaper = false,
    double? paperStart,
    double? riskPct,
    Set<String>? markets,
    bool? useTp2,
    bool? derivOn,
    DateTime? derivSince,
    String? accountId,
    bool? accountDemo,
    int? maxOpen,
    double? dailyLossPct,
  }) =>
      BotSettings(
        paperSince: clearPaper ? null : (paperSince ?? this.paperSince),
        paperStart: paperStart ?? this.paperStart,
        riskPct: riskPct ?? this.riskPct,
        markets: markets ?? this.markets,
        useTp2: useTp2 ?? this.useTp2,
        derivOn: derivOn ?? this.derivOn,
        derivSince: derivSince ?? this.derivSince,
        accountId: accountId ?? this.accountId,
        accountDemo: accountDemo ?? this.accountDemo,
        maxOpen: maxOpen ?? this.maxOpen,
        dailyLossPct: dailyLossPct ?? this.dailyLossPct,
      );
}

/// One paper trade: a signal followed at a fixed % risk.
class PaperTrade {
  final TradeSignal signal;
  final double r; // result in multiples of risk (open trades: current)
  final double pnl;
  final double balanceAfter;
  const PaperTrade(this.signal, this.r, this.pnl, this.balanceAfter);
}

class BotLogEntry {
  final DateTime at;
  final String text;
  final bool ok;
  const BotLogEntry(this.at, this.text, this.ok);
  Map<String, dynamic> toJson() => {'at': at.toIso8601String(), 'text': text, 'ok': ok};
  factory BotLogEntry.fromJson(Map<String, dynamic> j) =>
      BotLogEntry(DateTime.tryParse('${j['at']}') ?? DateTime.now(), '${j['text']}', j['ok'] == true);
}

/// Logic shared by the screen and the background task (no Flutter widgets here).
class BotEngine {
  static const _kSettings = 'bot_settings';
  static const _kLog = 'bot_log';
  static const _kDone = 'bot_done_signals';
  static const _kAppId = 'deriv_app_id';
  static const tokenKey = 'deriv_token';

  static Future<BotSettings> settings() async {
    final p = await SharedPreferences.getInstance();
    final raw = p.getString(_kSettings);
    if (raw == null) return const BotSettings();
    try {
      return BotSettings.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    } catch (_) {
      return const BotSettings();
    }
  }

  static Future<void> save(BotSettings s) async =>
      (await SharedPreferences.getInstance()).setString(_kSettings, jsonEncode(s.toJson()));

  static Future<String?> appId() async => (await SharedPreferences.getInstance()).getString(_kAppId);
  static Future<void> saveAppId(String id) async => (await SharedPreferences.getInstance()).setString(_kAppId, id);

  static Future<List<BotLogEntry>> log() async {
    final raw = (await SharedPreferences.getInstance()).getString(_kLog);
    if (raw == null) return [];
    try {
      return (jsonDecode(raw) as List).map((e) => BotLogEntry.fromJson((e as Map).cast<String, dynamic>())).toList();
    } catch (_) {
      return [];
    }
  }

  static Future<void> addLog(String text, {bool ok = true}) async {
    final p = await SharedPreferences.getInstance();
    final list = [BotLogEntry(DateTime.now(), text, ok), ...await log()].take(60).toList();
    await p.setString(_kLog, jsonEncode(list.map((e) => e.toJson()).toList()));
  }

  /// Result of a signal in R (multiples of the risk taken).
  static double? resultR(TradeSignal s, {bool live = false}) {
    final riskPips = (s.entry - s.stopLoss).abs() / Fmt.pipSize(s.pair);
    if (riskPips <= 0 || s.locked) return null;
    final pips = live ? s.livePips : s.pips;
    return pips == null ? null : pips / riskPips;
  }

  /// Paper account: every signal since [since] on the chosen markets, compounding at [riskPct].
  static List<PaperTrade> paper(List<TradeSignal> signals, BotSettings s) {
    final since = s.paperSince;
    if (since == null) return [];
    final list = signals.where((x) => !x.openedAt.isBefore(since) && s.wants(x.pair)).toList()
      ..sort((a, b) => a.openedAt.compareTo(b.openedAt));
    var bal = s.paperStart;
    final out = <PaperTrade>[];
    for (final x in list) {
      final r = resultR(x, live: x.isRunning);
      if (r == null) continue;
      final pnl = bal * s.riskPct / 100 * r;
      if (!x.isRunning) bal += pnl;
      out.add(PaperTrade(x, r, pnl, bal));
    }
    return out;
  }

  /// Places Deriv trades for new live signals. Safe to call often: each signal is traded once.
  /// Returns log lines.
  static Future<List<String>> runDeriv(List<TradeSignal> running, {required bool premium}) async {
    final s = await settings();
    final lines = <String>[];
    if (!s.derivOn || s.accountId == null || s.derivSince == null) return lines;
    if (!premium) {
      await addLog('Auto-trading paused: Premium is not active.', ok: false);
      return lines;
    }
    final id = await appId();
    final token = await const FlutterSecureStorage().read(key: tokenKey);
    if (id == null || id.isEmpty || token == null) return lines;

    final p = await SharedPreferences.getInstance();
    final done = (p.getStringList(_kDone) ?? const []).toSet();
    final fresh = running.where((x) =>
        !x.locked &&
        !done.contains('${x.id}') &&
        s.wants(x.pair) &&
        !x.openedAt.isBefore(s.derivSince!) &&
        DateTime.now().difference(x.openedAt) < const Duration(hours: 12) &&
        !x.tp1Hit);
    if (fresh.isEmpty) return lines;

    final client = DerivClient(id, token);
    DerivSession? ws;
    try {
      ws = await client.connect(s.accountId!);
      final balance = await ws.balance();
      final today = DateTime.now().toUtc();
      final lost = -await ws.realisedSince(DateTime.utc(today.year, today.month, today.day));
      if (lost >= balance * s.dailyLossPct / 100) {
        await addLog('Daily loss limit reached (${s.dailyLossPct.toStringAsFixed(0)}%). No new trades today.', ok: false);
        return lines;
      }
      var open = await ws.openContracts();
      for (final x in fresh) {
        done.add('${x.id}');
        await p.setStringList(_kDone, done.toList());
        if (open >= s.maxOpen) {
          await addLog('Skipped ${x.pair} ${x.direction}: ${s.maxOpen} trades already open.', ok: false);
          continue;
        }
        final symbol = DerivOrder.symbolFor(x.pair);
        if (symbol == null) continue;
        final (order, why) = DerivOrder.plan(
          pair: x.pair,
          buy: x.isBuy,
          spot: await ws.spot(symbol),
          entry: x.entry,
          stopLoss: x.stopLoss,
          target: s.useTp2 ? x.tp2 : x.tp1,
          risk: balance * s.riskPct / 100,
          multipliers: await ws.multipliers(symbol),
        );
        if (order == null) {
          await addLog('Skipped ${x.pair} ${x.direction}: $why.', ok: false);
          continue;
        }
        final r = await ws.buy(order, 'USD');
        open++;
        final msg = 'Opened ${x.pair} ${x.direction} on Deriv (${s.accountDemo ? 'demo' : 'REAL'}): '
            'stake \$${order.stake.toStringAsFixed(2)}, ×${order.multiplier}, SL \$${order.stopLoss.toStringAsFixed(2)}, '
            'TP \$${order.takeProfit.toStringAsFixed(2)}. Contract ${r['contract_id']}.';
        await addLog(msg);
        lines.add(msg);
      }
    } on DerivException catch (e) {
      await addLog('Deriv: ${e.message}', ok: false);
    } catch (e) {
      await addLog('Deriv connection failed: $e', ok: false);
    } finally {
      await ws?.close();
      client.close();
    }
    return lines;
  }
}

/// Screen state for the bot.
class BotStore extends ChangeNotifier {
  final Api api;
  BotStore(this.api);

  BotSettings settings = const BotSettings();
  List<BotLogEntry> log = [];
  List<TradeSignal> history = [];
  bool loading = false;
  String? error;
  Map<String, dynamic> server = const {'paper': true, 'copy': true, 'deriv': true, 'deriv_app_id': ''};
  bool hasToken = false;
  List<DerivAccount> accounts = [];

  bool get derivAvailable => server['deriv'] == true && '${server['deriv_app_id'] ?? ''}'.isNotEmpty;

  Future<void> load() async {
    loading = true;
    notifyListeners();
    settings = await BotEngine.settings();
    log = await BotEngine.log();
    hasToken = (await const FlutterSecureStorage().read(key: BotEngine.tokenKey)) != null;
    try {
      server = await api.botConfig();
      final id = '${server['deriv_app_id'] ?? ''}';
      if (id.isNotEmpty) await BotEngine.saveAppId(id);
    } catch (_) {}
    await loadHistory();
    loading = false;
    notifyListeners();
  }

  Future<void> loadHistory() async {
    final since = settings.paperSince;
    if (since == null) {
      history = [];
      return;
    }
    try {
      final out = <TradeSignal>[];
      for (var page = 1; page <= 10; page++) {
        final r = await api.signals(running: false, page: page, from: since);
        out.addAll(r.items);
        if (!r.hasMore) break;
      }
      out.addAll((await api.signals(running: true)).items);
      history = out;
      error = null;
    } catch (e) {
      error = 'Could not load signals: $e';
    }
  }

  List<PaperTrade> get paperTrades => BotEngine.paper(history, settings);

  double get paperBalance {
    final closed = paperTrades.where((t) => !t.signal.isRunning);
    return closed.isEmpty ? settings.paperStart : closed.last.balanceAfter;
  }

  Future<void> update(BotSettings next) async {
    settings = next;
    await BotEngine.save(next);
    notifyListeners();
  }

  Future<void> startPaper(double start) async {
    await update(settings.copyWith(paperSince: DateTime.now().subtract(const Duration(days: 30)), paperStart: start));
    await loadHistory();
    notifyListeners();
  }

  Future<void> stopPaper() async => update(settings.copyWith(clearPaper: true));

  Future<void> saveToken(String token) async {
    error = null;
    final appId = '${server['deriv_app_id'] ?? ''}';
    final client = DerivClient(appId, token.trim());
    try {
      accounts = await client.accounts();
      if (accounts.isEmpty) throw const DerivException('No Options account found on this Deriv login.');
      await const FlutterSecureStorage().write(key: BotEngine.tokenKey, value: token.trim());
      hasToken = true;
      final demo = accounts.where((a) => a.demo).firstOrNull ?? accounts.first;
      await update(settings.copyWith(accountId: demo.id, accountDemo: demo.demo));
    } on DerivException catch (e) {
      error = e.message;
    } catch (e) {
      error = 'Could not reach Deriv: $e';
    } finally {
      client.close();
    }
    notifyListeners();
  }

  Future<void> refreshAccounts() async {
    final token = await const FlutterSecureStorage().read(key: BotEngine.tokenKey);
    if (token == null) return;
    final client = DerivClient('${server['deriv_app_id'] ?? ''}', token);
    try {
      accounts = await client.accounts();
    } catch (e) {
      error = '$e';
    } finally {
      client.close();
    }
    notifyListeners();
  }

  Future<void> disconnect() async {
    await const FlutterSecureStorage().delete(key: BotEngine.tokenKey);
    hasToken = false;
    accounts = [];
    await update(BotSettings(
      paperSince: settings.paperSince,
      paperStart: settings.paperStart,
      riskPct: settings.riskPct,
      markets: settings.markets,
      useTp2: settings.useTp2,
    ));
  }

  Future<void> setDeriv(bool on) async {
    await update(settings.copyWith(derivOn: on, derivSince: on ? DateTime.now() : null));
    await BotEngine.addLog(on ? 'Auto-trading switched on.' : 'Auto-trading switched off.');
    if (on) await BackgroundSignalService.ensureScheduled();
    log = await BotEngine.log();
    notifyListeners();
  }

  /// Runs one check now (the background task also does this about every 15 minutes).
  Future<void> runNow(List<TradeSignal> running, {required bool premium}) async {
    await BotEngine.runDeriv(running, premium: premium);
    log = await BotEngine.log();
    notifyListeners();
  }

  /// Lot size for copying a signal by hand into MetaTrader or any broker.
  static double lots({required String pair, required double balance, required double riskPct, required double entry,
      required double stopLoss, required double pipValuePerLot}) {
    final pips = (entry - stopLoss).abs() / Fmt.pipSize(pair);
    if (pips <= 0 || pipValuePerLot <= 0) return 0;
    final raw = balance * riskPct / 100 / (pips * pipValuePerLot);
    return math.max(0, (raw * 100).floorToDouble() / 100);
  }
}
