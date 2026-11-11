import 'dart:convert';
import 'dart:math' as math;

import 'package:shared_preferences/shared_preferences.dart';

import '../core/config.dart';
import '../core/format.dart';
import '../engine/engine.dart';
import '../models/models.dart';
import 'api.dart';

/// Offline stand-in for the server so the whole app can be tried without a
/// backend. Prices are simulated; signals and results are produced by running
/// the real signal engine and TP/SL rules over those simulated prices.
class DemoApi extends Api {
  static const _kUser = 'demo_user';
  static const _hours = 300 + 24 * 30; // 30 days of history
  static const _base = {
    'EUR/USD': 1.085,
    'GBP/USD': 1.27,
    'USD/JPY': 150.0,
    'AUD/USD': 0.66,
    'USD/CAD': 1.36,
    'USD/CHF': 0.88,
    'NZD/USD': 0.61,
    'XAU/USD': 2350.0,
    'BTC/USD': 95000.0,
    'ETH/USD': 3400.0,
  };

  Map<String, List<Candle>>? _market;
  List<TradeSignal>? _signals;

  Future<void> _delay() =>
      Future<void>.delayed(const Duration(milliseconds: 350));

  // ---------- auth ----------
  Future<AppUser?> _loadUser() async {
    final p = await SharedPreferences.getInstance();
    final raw = p.getString(_kUser);
    if (raw == null) return null;
    return AppUser.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
  }

  Future<AppUser> _saveUser(AppUser u) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kUser, jsonEncode(u.toJson()));
    return u;
  }

  void _validate(String email, String password) {
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email.trim())) {
      throw const ApiException('Enter a valid email address.', 422);
    }
    if (password.length < 8) {
      throw const ApiException('Password must be at least 8 characters.', 422);
    }
  }

  AuthResult _session(AppUser u) {
    token = 'demo-token';
    return AuthResult(token!, u);
  }

  @override
  Future<AuthResult> login(String email, String password) async {
    await _delay();
    _validate(email, password);
    final existing = await _loadUser();
    final u = existing ??
        AppUser(
          id: 1,
          name: email.split('@').first,
          email: email.trim().toLowerCase(),
          createdAt: DateTime.now(),
          notifications: const NotificationPrefs(),
          watchlist: AppConfig.pairs,
        );
    return _session(await _saveUser(u));
  }

  @override
  Future<AuthResult> register(String name, String email, String password) async {
    await _delay();
    _validate(email, password);
    return _session(await _saveUser(AppUser(
      id: 1,
      name: name.trim(),
      email: email.trim().toLowerCase(),
      createdAt: DateTime.now(),
      notifications: const NotificationPrefs(),
      watchlist: AppConfig.pairs,
    )));
  }

  @override
  Future<String> forgotPassword(String email) async {
    await _delay();
    return 'Demo mode: use code 123456 to reset your password.';
  }

  @override
  Future<AuthResult> resetPassword(String email, String code, String password) async {
    await _delay();
    if (code != '123456') throw const ApiException('Incorrect code. In demo mode the code is 123456.');
    return login(email, password);
  }

  @override
  Future<AppUser> verifyEmail(String code) async {
    await _delay();
    if (code.trim() != '123456') {
      throw const ApiException('Incorrect code. In demo mode the code is 123456.');
    }
    return _saveUser((await me()).copyWith(emailVerified: true));
  }

  @override
  Future<String> resendVerification() async {
    await _delay();
    return 'Demo mode: use code 123456.';
  }

  @override
  Future<AppUser> me() async {
    final u = await _loadUser();
    if (u == null) throw const ApiException('Please log in again.', 401);
    return u;
  }

  @override
  Future<AppUser> updateName(String name) async {
    await _delay();
    return _saveUser((await me()).copyWith(name: name.trim()));
  }

  @override
  Future<String> changePassword(String current, String next) async {
    await _delay();
    if (next.length < 8) throw const ApiException('Password must be at least 8 characters.');
    return token ?? 'demo-token';
  }

  @override
  Future<void> deleteAccount(String password) async {
    await _delay();
    final p = await SharedPreferences.getInstance();
    await p.remove(_kUser);
  }

  @override
  Future<AppUser> updateNotifications(NotificationPrefs prefs) async =>
      _saveUser((await me()).copyWith(notifications: prefs));

  @override
  Future<AppUser> updateWatchlist(List<String> symbols) async =>
      _saveUser((await me()).copyWith(watchlist: symbols));

  // ---------- market simulation ----------
  Map<String, List<Candle>> get _candles => _market ??= _generate();

  Map<String, List<Candle>> _generate() {
    final nowHour = DateTime.now().toUtc();
    final end = DateTime.utc(nowHour.year, nowHour.month, nowHour.day, nowHour.hour);
    final out = <String, List<Candle>>{};
    var seed = 7;
    for (final e in _base.entries) {
      final rng = math.Random(seed++ * 7919 + end.day);
      final vol = e.value * 0.0015;
      var p = e.value * (0.98 + rng.nextDouble() * 0.04);
      var drift = 0.0;
      final list = <Candle>[];
      for (var i = 0; i < _hours; i++) {
        if (i % 60 == 0) drift = (rng.nextDouble() - 0.5) * vol * 0.6;
        final u1 = math.max(rng.nextDouble(), 1e-12);
        final g = math.sqrt(-2 * math.log(u1)) * math.cos(2 * math.pi * rng.nextDouble());
        final o = p;
        final c = o + drift + g * vol;
        list.add(Candle(
          end.subtract(Duration(hours: _hours - 1 - i)),
          o,
          math.max(o, c) + rng.nextDouble() * vol * 0.6,
          math.min(o, c) - rng.nextDouble() * vol * 0.6,
          c,
        ));
        p = c;
      }
      out[e.key] = list;
    }
    return out;
  }

  List<TradeSignal> get _all => _signals ??= _simulate();

  List<TradeSignal> _simulate() {
    final result = <TradeSignal>[];
    var id = 1;
    _candles.forEach((pair, cs) {
      final n = cs.length;
      final pip = Fmt.pipSize(pair);
      var cooldownUntil = 0;
      int? openIdx;
      EngineResult? open;
      DateTime? tp1At;

      TradeSignal build({
        required bool running,
        SignalResult? res,
        DateTime? closedAt,
        double? closePrice,
        required double price,
        required int uptoIdx,
      }) {
        final o = open!;
        final dir = o.direction == 'BUY' ? 1 : -1;
        final pips = (price - o.entry) * dir / pip;
        return TradeSignal(
          id: id++,
          pair: pair,
          timeframe: '1h',
          isBuy: o.direction == 'BUY',
          entry: o.entry,
          stopLoss: o.sl!,
          tp1: o.tp1!,
          tp2: o.tp2!,
          confidence: o.confidence,
          reasons: o.reasons,
          isRunning: running,
          result: res,
          tp1HitAt: tp1At,
          openedAt: cs[openIdx!].time.add(const Duration(hours: 1)),
          expiresAt: cs[openIdx!].time.add(const Duration(hours: 73)),
          closedAt: closedAt,
          closePrice: closePrice,
          pips: running ? null : double.parse(pips.toStringAsFixed(1)),
          currentPrice: price,
          livePips: double.parse(pips.toStringAsFixed(1)),
          candles: cs.sublist(math.max(0, uptoIdx - 119), uptoIdx + 1),
        );
      }

      for (var h = 300; h < n; h++) {
        final c = cs[h];
        if (open != null && openIdx != null) {
          final o = open!;
          final oi = openIdx!;
          final buy = o.direction == 'BUY';
          final stop = tp1At != null ? o.entry : o.sl!;
          TradeSignal? closed;
          if (buy ? c.low <= stop : c.high >= stop) {
            closed = build(
                running: false,
                res: tp1At != null ? SignalResult.tp1 : SignalResult.sl,
                closedAt: c.time,
                closePrice: tp1At != null ? o.tp1 : o.sl,
                price: tp1At != null ? o.tp1! : o.sl!,
                uptoIdx: h);
          } else {
            if (tp1At == null && (buy ? c.high >= o.tp1! : c.low <= o.tp1!)) {
              tp1At = c.time;
            }
            if (buy ? c.high >= o.tp2! : c.low <= o.tp2!) {
              closed = build(
                  running: false,
                  res: SignalResult.tp2,
                  closedAt: c.time,
                  closePrice: o.tp2,
                  price: o.tp2!,
                  uptoIdx: h);
            } else if (h - oi >= 72) {
              closed = build(
                  running: false,
                  res: tp1At != null ? SignalResult.tp1 : SignalResult.expired,
                  closedAt: c.time,
                  closePrice: tp1At != null ? o.tp1 : c.close,
                  price: tp1At != null ? o.tp1! : c.close,
                  uptoIdx: h);
            }
          }
          if (closed != null) {
            result.add(closed);
            open = null;
            openIdx = null;
            tp1At = null;
            cooldownUntil = h + 4;
          } else if (h == n - 1) {
            result.add(build(running: true, price: c.close, uptoIdx: h));
          }
          continue;
        }
        if (h >= cooldownUntil && h < n - 1) {
          final r = SignalEngine.analyze(cs.sublist(h - 299, h + 1));
          if (r != null && r.direction != 'WAIT' && r.confidence >= 65) {
            open = r;
            openIdx = h;
            tp1At = null;
          }
        }
      }
    });
    return result;
  }

  @override
  Future<SignalPage> signals(
      {required bool running, int page = 1, DateTime? from, DateTime? to}) async {
    await _delay();
    final list = _all
        .where((s) =>
            s.isRunning == running &&
            (from == null || !s.closedAt!.isBefore(from)) &&
            (to == null || s.closedAt!.isBefore(to)))
        .toList()
      ..sort((a, b) => running
          ? b.openedAt.compareTo(a.openedAt)
          : b.closedAt!.compareTo(a.closedAt!));
    const per = 20;
    final start = (page - 1) * per;
    if (start >= list.length) return const SignalPage([], false);
    final end = math.min(start + per, list.length);
    final premium = await _premium();
    for (var i = start; i < end; i++) {
      list[i] = _gate(list[i], premium);
    }
    return SignalPage(list.sublist(start, end), end < list.length);
  }

  @override
  Future<TradeSignal> signal(int id) async {
    await _delay();
    final s = _all.firstWhere((s) => s.id == id,
        orElse: () => throw const ApiException('Signal not found', 404));
    return _gate(s, await _premium());
  }

  @override
  Future<ReportsData> reports(String period) async {
    await _delay();
    final now = DateTime.now();
    final closed = _all.where((s) => !s.isRunning).toList();
    ReportRow window(DateTime start) {
      final end = nextPeriod(period, start);
      final inP = closed
          .where((s) => !s.closedAt!.isBefore(start) && s.closedAt!.isBefore(end))
          .toList();
      final opened =
          _all.where((s) => !s.openedAt.isBefore(start) && s.openedAt.isBefore(end)).length;
      return buildRow(period, start, end, inP, opened);
    }

    final items = <ReportRow>[];
    var cur = periodStart(period, now);
    final limit = period == 'daily' ? 20 : 12;
    while (items.length < limit) {
      if (period != 'daily' || cur.weekday <= 5) items.add(window(cur));
      cur = prevPeriod(period, cur);
    }
    final wins = closed.where((s) => (s.pips ?? 0) > 0).length;
    final losses = closed.where((s) => (s.pips ?? 0) < 0).length;
    final pips = closed.fold<double>(0, (a, s) => a + (s.pips ?? 0));
    return ReportsData(
      period,
      'Phone time',
      ReportSummary(
        today: window(periodStart('daily', now)),
        week: window(periodStart('weekly', now)),
        month: window(periodStart('monthly', now)),
        allClosed: closed.length,
        allWins: wins,
        allLosses: losses,
        allPips: pips,
        allWinRate: closed.isEmpty ? 0.0 : wins * 100 / closed.length,
        running: _all.where((s) => s.isRunning).length,
        since: _all.isEmpty ? null : _all.map((s) => s.openedAt).reduce((a, b) => a.isBefore(b) ? a : b),
      ),
      items,
    );
  }

  @override
  Future<Stats> stats(int days) async {
    await _delay();
    final since = DateTime.now().subtract(Duration(days: days));
    final closed = _all
        .where((s) => !s.isRunning && (days == 0 || s.closedAt!.isAfter(since)))
        .toList()
      ..sort((a, b) => a.closedAt!.compareTo(b.closedAt!));
    return computeStats(closed, _all.where((s) => s.isRunning).length, days);
  }

  @override
  Future<Map<String, bool>> promo() async => const {'coffee': true, 'whatsapp': true, 'hire': true};

  @override
  Future<AppUser> verifyPurchase(String productId, String purchaseToken) async {
    await _delay();
    final u = await me();
    final yearly = productId.contains('year');
    return _saveUser(AppUser(
      id: u.id, name: u.name, email: u.email, createdAt: u.createdAt,
      emailVerified: u.emailVerified, notifications: u.notifications, watchlist: u.watchlist,
      premium: true, premiumPlan: yearly ? 'yearly' : 'monthly',
      premiumUntil: DateTime.now().add(Duration(days: yearly ? 365 : 30)),
      billingAccountId: u.billingAccountId,
    ));
  }

  @override
  Future<void> registerDevice(String pushToken) async {}

  @override
  Future<void> unregisterDevice(String pushToken) async {}

  Future<bool> _premium() async => (await _loadUser())?.premium ?? false;
  TradeSignal _gate(TradeSignal s, bool premium) => s.isRunning && !premium && s.premiumTier ? s.lockedCopy() : s;

  @override
  Future<List<PriceQuote>> prices() async {
    await _delay();
    return _candles.entries.map((e) {
      final cs = e.value;
      final day = cs.sublist(cs.length - 24);
      final last = cs.last.close;
      final ref = cs[cs.length - 25].close;
      return PriceQuote(
        e.key,
        last,
        (last - ref) / ref * 100,
        day.map((c) => c.high).reduce(math.max),
        day.map((c) => c.low).reduce(math.min),
        day.map((c) => c.close).toList(),
        DateTime.now(),
      );
    }).toList();
  }
}

/// Same maths as the server's Stats::compute.
Stats computeStats(List<TradeSignal> closedOldestFirst, int running, int days) {
  var wins = 0, losses = 0;
  double pips = 0, winPips = 0, lossPips = 0, cum = 0;
  final byPair = <String, List<num>>{}; // total, wins, pips
  final monthly = <String, List<num>>{};
  final equity = <EquityPoint>[];
  for (final s in closedOldestFirst) {
    final p = s.pips ?? 0;
    pips += p;
    if (p > 0) {
      wins++;
      winPips += p;
    } else if (p < 0) {
      losses++;
      lossPips -= p;
    }
    final bp = byPair.putIfAbsent(s.pair, () => [0, 0, 0.0]);
    bp[0] = bp[0] + 1;
    if (p > 0) bp[1] = bp[1] + 1;
    bp[2] = bp[2] + p;
    final t = s.closedAt!.toUtc();
    final key = '${t.year}-${Fmt.two(t.month)}';
    final m = monthly.putIfAbsent(key, () => [0, 0, 0.0]);
    m[0] = m[0] + 1;
    if (p > 0) m[1] = m[1] + 1;
    m[2] = m[2] + p;
    cum += p;
    equity.add(EquityPoint(s.closedAt!, cum));
  }
  final total = closedOldestFirst.length;
  final pairs = byPair.entries
      .map((e) => PairStat(e.key, e.value[0].toInt(), e.value[1].toInt(),
          e.value[2].toDouble(), e.value[0] == 0 ? 0.0 : (e.value[1] * 100 / e.value[0]).toDouble()))
      .toList()
    ..sort((a, b) => b.pips.compareTo(a.pips));
  return Stats(
    days: days,
    total: total,
    wins: wins,
    losses: losses,
    running: running,
    winRate: total == 0 ? 0.0 : wins * 100 / total,
    totalPips: pips,
    avgWin: wins == 0 ? 0.0 : winPips / wins,
    avgLoss: losses == 0 ? 0.0 : lossPips / losses,
    profitFactor: lossPips > 0 ? winPips / lossPips : null,
    bestPair: pairs.isEmpty ? null : pairs.first.pair,
    byPair: pairs,
    monthly: monthly.entries
        .map((e) => MonthStat(e.key, e.value[0].toInt(), e.value[1].toInt(),
            e.value[2].toDouble()))
        .toList(),
    equity: equity,
  );
}

DateTime periodStart(String period, DateTime t) {
  final d = DateTime(t.year, t.month, t.day);
  return switch (period) {
    'weekly' => d.subtract(Duration(days: d.weekday - 1)),
    'monthly' => DateTime(t.year, t.month, 1),
    _ => d,
  };
}

DateTime nextPeriod(String period, DateTime s) => switch (period) {
      'weekly' => DateTime(s.year, s.month, s.day + 7),
      'monthly' => DateTime(s.year, s.month + 1, 1),
      _ => DateTime(s.year, s.month, s.day + 1),
    };

DateTime prevPeriod(String period, DateTime s) => switch (period) {
      'weekly' => DateTime(s.year, s.month, s.day - 7),
      'monthly' => DateTime(s.year, s.month - 1, 1),
      _ => DateTime(s.year, s.month, s.day - 1),
    };

const _mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const _monFull = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
  'September', 'October', 'November', 'December'];
const _dow = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

ReportRow buildRow(String period, DateTime start, DateTime end, List<TradeSignal> closed, int opened) {
  var wins = 0, losses = 0;
  double pips = 0;
  TradeSignal? best, worst;
  for (final s in closed) {
    final p = s.pips ?? 0;
    pips += p;
    if (p > 0) wins++;
    if (p < 0) losses++;
    if (best == null || p > (best.pips ?? 0)) best = s;
    if (worst == null || p < (worst.pips ?? 0)) worst = s;
  }
  final last = end.subtract(const Duration(days: 1));
  final label = switch (period) {
    'weekly' => '${_mon[start.month - 1]} ${start.day} – ${_mon[last.month - 1]} ${last.day}, ${last.year}',
    'monthly' => '${_monFull[start.month - 1]} ${start.year}',
    _ => '${_dow[start.weekday - 1]}, ${_mon[start.month - 1]} ${start.day}, ${start.year}',
  };
  return ReportRow(
    key: start.toIso8601String(),
    label: label,
    start: start,
    end: end,
    opened: opened,
    closed: closed.length,
    wins: wins,
    losses: losses,
    winRate: closed.isEmpty ? 0.0 : wins * 100 / closed.length,
    pips: pips,
    bestPair: best?.pair,
    bestPips: best?.pips,
    worstPair: worst?.pair,
    worstPips: worst?.pips,
  );
}
