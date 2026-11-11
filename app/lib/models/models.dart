import '../core/format.dart';

double _d(dynamic v) => v == null ? 0.0 : (v as num).toDouble();
double? _dn(dynamic v) => v == null ? null : (v as num).toDouble();
DateTime? _t(dynamic v) => v == null ? null : DateTime.parse(v as String);

class Candle {
  final DateTime time;
  final double open, high, low, close;
  const Candle(this.time, this.open, this.high, this.low, this.close);

  /// Server format: [unixSeconds, o, h, l, c]
  factory Candle.fromList(List<dynamic> l) => Candle(
        DateTime.fromMillisecondsSinceEpoch((l[0] as num).toInt() * 1000,
            isUtc: true),
        _d(l[1]),
        _d(l[2]),
        _d(l[3]),
        _d(l[4]),
      );
}

class EmailPrefs {
  final bool signals, results, daily, weekly, monthly;

  /// Tips, offers and "we miss you" emails.
  final bool marketing;
  const EmailPrefs(
      {this.signals = true,
      this.results = true,
      this.daily = false,
      this.weekly = true,
      this.monthly = true,
      this.marketing = true});

  factory EmailPrefs.fromJson(Map<String, dynamic> j) => EmailPrefs(
        signals: j['signals'] as bool? ?? true,
        results: j['results'] as bool? ?? true,
        daily: j['daily'] as bool? ?? false,
        weekly: j['weekly'] as bool? ?? true,
        monthly: j['monthly'] as bool? ?? true,
        marketing: j['marketing'] as bool? ?? true,
      );

  Map<String, dynamic> toJson() => {
        'signals': signals,
        'results': results,
        'daily': daily,
        'weekly': weekly,
        'monthly': monthly,
        'marketing': marketing,
      };

  EmailPrefs copyWith(
          {bool? signals, bool? results, bool? daily, bool? weekly, bool? monthly, bool? marketing}) =>
      EmailPrefs(
        signals: signals ?? this.signals,
        results: results ?? this.results,
        daily: daily ?? this.daily,
        weekly: weekly ?? this.weekly,
        monthly: monthly ?? this.monthly,
        marketing: marketing ?? this.marketing,
      );
}

class NotificationPrefs {
  /// Push notifications
  final bool signals;
  final bool results;
  final bool reports;

  /// null = all pairs (applies to push and email alerts)
  final List<String>? pairs;
  final EmailPrefs email;

  const NotificationPrefs(
      {this.signals = true,
      this.results = true,
      this.reports = true,
      this.pairs,
      this.email = const EmailPrefs()});

  factory NotificationPrefs.fromJson(Map<String, dynamic> j) => NotificationPrefs(
        signals: j['signals'] as bool? ?? true,
        results: j['results'] as bool? ?? true,
        reports: j['reports'] as bool? ?? true,
        pairs: (j['pairs'] as List?)?.cast<String>(),
        email: EmailPrefs.fromJson((j['email'] as Map?)?.cast<String, dynamic>() ?? {}),
      );

  Map<String, dynamic> toJson() => {
        'signals': signals,
        'results': results,
        'reports': reports,
        'pairs': pairs,
        'email': email.toJson(),
      };

  bool wantsPair(String pair) => pairs == null || pairs!.contains(pair);

  NotificationPrefs copyWith(
          {bool? signals,
          bool? results,
          bool? reports,
          List<String>? pairs,
          bool allPairs = false,
          EmailPrefs? email}) =>
      NotificationPrefs(
        signals: signals ?? this.signals,
        results: results ?? this.results,
        reports: reports ?? this.reports,
        pairs: allPairs ? null : (pairs ?? this.pairs),
        email: email ?? this.email,
      );
}

class AppUser {
  final int id;
  final String name;
  final String email;
  final DateTime? createdAt;
  final bool emailVerified;
  final NotificationPrefs notifications;
  final List<String> watchlist;

  /// Verified by the server with Google Play. Only premium users get live levels.
  final bool premium;
  final DateTime? premiumUntil;
  final String? premiumPlan;

  /// Sent to Google Play at checkout so a purchase is tied to this account.
  final String billingAccountId;

  /// Markets the user chose to see on the Signals screen (null = all).
  final List<String>? signalPairs;

  /// Free users: one Premium signal unlocked every [freeUnlockDays] days (0 = none).
  final int freeUnlockDays;

  /// When the next free unlock is available (null = available now).
  final DateTime? freeUnlockAt;

  bool get canFreeUnlock =>
      !premium && freeUnlockDays > 0 && (freeUnlockAt == null || freeUnlockAt!.isBefore(DateTime.now()));

  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.createdAt,
    this.emailVerified = false,
    required this.notifications,
    required this.watchlist,
    this.premium = false,
    this.premiumUntil,
    this.premiumPlan,
    this.billingAccountId = '',
    this.signalPairs,
    this.freeUnlockDays = 0,
    this.freeUnlockAt,
  });

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: (j['id'] as num).toInt(),
        name: j['name'] as String,
        email: j['email'] as String,
        createdAt: _t(j['created_at']),
        emailVerified: j['email_verified'] as bool? ?? false,
        notifications: NotificationPrefs.fromJson(
            (j['notifications'] as Map?)?.cast<String, dynamic>() ?? {}),
        watchlist: (j['watchlist'] as List?)?.cast<String>() ?? const [],
        premium: j['premium'] as bool? ?? false,
        premiumUntil: _t(j['premium_until']),
        premiumPlan: j['premium_plan'] as String?,
        billingAccountId: j['billing_account_id'] as String? ?? '',
        signalPairs: (j['signal_pairs'] as List?)?.cast<String>(),
        freeUnlockDays: (j['free_unlock_days'] as num?)?.toInt() ?? 0,
        freeUnlockAt: _t(j['free_unlock_at']),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'created_at': createdAt?.toIso8601String(),
        'email_verified': emailVerified,
        'notifications': notifications.toJson(),
        'watchlist': watchlist,
        'premium': premium,
        'premium_until': premiumUntil?.toIso8601String(),
        'premium_plan': premiumPlan,
        'billing_account_id': billingAccountId,
        'signal_pairs': signalPairs,
        'free_unlock_days': freeUnlockDays,
        'free_unlock_at': freeUnlockAt?.toIso8601String(),
      };

  AppUser copyWith(
          {String? name,
          bool? emailVerified,
          NotificationPrefs? notifications,
          List<String>? watchlist,
          bool? premium}) =>
      AppUser(
        id: id,
        name: name ?? this.name,
        email: email,
        createdAt: createdAt,
        emailVerified: emailVerified ?? this.emailVerified,
        notifications: notifications ?? this.notifications,
        watchlist: watchlist ?? this.watchlist,
        premium: premium ?? this.premium,
        premiumUntil: premiumUntil,
        premiumPlan: premiumPlan,
        billingAccountId: billingAccountId,
        signalPairs: signalPairs,
        freeUnlockDays: freeUnlockDays,
        freeUnlockAt: freeUnlockAt,
      );
}

enum SignalResult { tp2, tp1, sl, expired }

class TradeSignal {
  final int id;
  final String pair;
  final String timeframe;
  final bool isBuy;
  final double entry, stopLoss, tp1, tp2;
  final int confidence;
  final List<String> reasons;
  final bool isRunning;
  final SignalResult? result;
  final DateTime? tp1HitAt;
  final DateTime openedAt;
  final DateTime? expiresAt;
  final DateTime? closedAt;
  final double? closePrice;
  final double? pips;
  final double? currentPrice;
  final double? livePips;
  final List<Candle> candles;

  /// Live signal whose entry, stop loss and targets are hidden (no subscription).
  final bool locked;

  const TradeSignal({
    required this.id,
    required this.pair,
    required this.timeframe,
    required this.isBuy,
    required this.entry,
    required this.stopLoss,
    required this.tp1,
    required this.tp2,
    required this.confidence,
    required this.reasons,
    required this.isRunning,
    required this.result,
    required this.tp1HitAt,
    required this.openedAt,
    required this.expiresAt,
    required this.closedAt,
    required this.closePrice,
    required this.pips,
    required this.currentPrice,
    required this.livePips,
    this.candles = const [],
    this.locked = false,
  });

  static SignalResult? _result(String? r) => switch (r) {
        'tp2' => SignalResult.tp2,
        'tp1' => SignalResult.tp1,
        'sl' => SignalResult.sl,
        'expired' => SignalResult.expired,
        _ => null,
      };

  factory TradeSignal.fromJson(Map<String, dynamic> j) => TradeSignal(
        id: (j['id'] as num).toInt(),
        pair: j['pair'] as String,
        timeframe: j['timeframe'] as String? ?? '1h',
        isBuy: j['direction'] == 'BUY',
        entry: _d(j['entry']),
        stopLoss: _d(j['stop_loss']),
        tp1: _d(j['tp1']),
        tp2: _d(j['tp2']),
        confidence: (j['confidence'] as num).toInt(),
        reasons: (j['reasons'] as List?)?.cast<String>() ?? const [],
        isRunning: j['status'] == 'running',
        result: _result(j['result'] as String?),
        tp1HitAt: _t(j['tp1_hit_at']),
        openedAt: _t(j['opened_at'])!,
        expiresAt: _t(j['expires_at']),
        closedAt: _t(j['closed_at']),
        closePrice: _dn(j['close_price']),
        pips: _dn(j['pips']),
        currentPrice: _dn(j['current_price']),
        livePips: _dn(j['live_pips']),
        candles: (j['candles'] as List?)
                ?.map((c) => Candle.fromList(c as List))
                .toList() ??
            const [],
        locked: j['locked'] as bool? ?? false,
      );

  /// Same signal with the premium levels removed (used by the offline demo).
  TradeSignal lockedCopy() => TradeSignal(
        id: id, pair: pair, timeframe: timeframe, isBuy: isBuy,
        entry: 0, stopLoss: 0, tp1: 0, tp2: 0, confidence: confidence, reasons: const [],
        isRunning: isRunning, result: result, tp1HitAt: tp1HitAt, openedAt: openedAt,
        expiresAt: expiresAt, closedAt: closedAt, closePrice: closePrice, pips: pips,
        currentPrice: currentPrice, livePips: null, candles: candles, locked: true,
      );

  /// Needs Premium: gold/crypto, or forex above the free strength limit.
  bool get premiumTier =>
      const ['XAU/USD', 'BTC/USD', 'ETH/USD'].contains(pair) || confidence > 70;

  String get direction => isBuy ? 'BUY' : 'SELL';
  String get timeframeLabel => switch (timeframe) {
        '15min' => 'M15',
        '30min' => 'M30',
        '1h' => 'H1',
        '4h' => 'H4',
        '1day' => 'D1',
        _ => timeframe.toUpperCase(),
      };

  bool get tp1Hit => tp1HitAt != null;
  bool get isWin => (pips ?? 0) > 0;

  double _pipsTo(double price) =>
      (price - entry) * (isBuy ? 1 : -1) / Fmt.pipSize(pair);
  double get slPips => _pipsTo(stopLoss);
  double get tp1Pips => _pipsTo(tp1);
  double get tp2Pips => _pipsTo(tp2);

  /// Pips to show on cards: live for running, final for closed.
  double get displayPips => (isRunning ? livePips : pips) ?? 0;

  /// The stop currently in force (moves to entry after TP1).
  double get activeStop => tp1Hit ? entry : stopLoss;

  String get resultLabel => switch (result) {
        SignalResult.tp2 => 'TP2 hit',
        SignalResult.tp1 => 'TP1 hit',
        SignalResult.sl => 'SL hit',
        SignalResult.expired => 'Expired',
        null => tp1Hit ? 'TP1 hit · running' : 'Running',
      };
}

class SignalPage {
  final List<TradeSignal> items;
  final bool hasMore;
  const SignalPage(this.items, this.hasMore);
}

class PairStat {
  final String pair;
  final int total, wins;
  final double pips, winRate;
  const PairStat(this.pair, this.total, this.wins, this.pips, this.winRate);
}

class MonthStat {
  final String month;
  final int total, wins;
  final double pips;
  const MonthStat(this.month, this.total, this.wins, this.pips);
}

class EquityPoint {
  final DateTime time;
  final double pips;
  const EquityPoint(this.time, this.pips);
}

class Stats {
  final int days;
  final int total, wins, losses, running;
  final double winRate, totalPips, avgWin, avgLoss;
  final double? profitFactor;
  final String? bestPair;
  final List<PairStat> byPair;
  final List<MonthStat> monthly;
  final List<EquityPoint> equity;

  const Stats({
    required this.days,
    required this.total,
    required this.wins,
    required this.losses,
    required this.running,
    required this.winRate,
    required this.totalPips,
    required this.avgWin,
    required this.avgLoss,
    required this.profitFactor,
    required this.bestPair,
    required this.byPair,
    required this.monthly,
    required this.equity,
  });

  factory Stats.fromJson(Map<String, dynamic> j) => Stats(
        days: (j['days'] as num?)?.toInt() ?? 30,
        total: (j['total'] as num).toInt(),
        wins: (j['wins'] as num).toInt(),
        losses: (j['losses'] as num).toInt(),
        running: (j['running'] as num?)?.toInt() ?? 0,
        winRate: _d(j['win_rate']),
        totalPips: _d(j['total_pips']),
        avgWin: _d(j['avg_win_pips']),
        avgLoss: _d(j['avg_loss_pips']),
        profitFactor: _dn(j['profit_factor']),
        bestPair: j['best_pair'] as String?,
        byPair: ((j['by_pair'] as List?) ?? const [])
            .map((e) => PairStat(
                  e['pair'] as String,
                  (e['total'] as num).toInt(),
                  (e['wins'] as num).toInt(),
                  _d(e['pips']),
                  _d(e['win_rate']),
                ))
            .toList(),
        monthly: ((j['monthly'] as List?) ?? const [])
            .map((e) => MonthStat(
                  e['month'] as String,
                  (e['total'] as num).toInt(),
                  (e['wins'] as num).toInt(),
                  _d(e['pips']),
                ))
            .toList(),
        equity: ((j['equity'] as List?) ?? const [])
            .map((e) => EquityPoint(DateTime.parse(e['t'] as String), _d(e['pips'])))
            .toList(),
      );
}

class PriceQuote {
  final String symbol;
  final double price, changePct, high, low;
  final List<double> spark;
  final DateTime? updatedAt;
  const PriceQuote(this.symbol, this.price, this.changePct, this.high,
      this.low, this.spark, this.updatedAt);

  factory PriceQuote.fromJson(Map<String, dynamic> j) => PriceQuote(
        j['symbol'] as String,
        _d(j['price']),
        _d(j['change_pct']),
        _d(j['high']),
        _d(j['low']),
        ((j['spark'] as List?) ?? const []).map(_d).toList(),
        _t(j['updated_at']),
      );
}

class AuthResult {
  final String token;
  final AppUser user;
  const AuthResult(this.token, this.user);
}

/// One row of a daily / weekly / monthly report.
class ReportRow {
  final String key, label;
  final DateTime start, end;
  final int opened, closed, wins, losses;
  final double winRate, pips;
  final String? bestPair, worstPair;
  final double? bestPips, worstPips;

  const ReportRow({
    required this.key,
    required this.label,
    required this.start,
    required this.end,
    required this.opened,
    required this.closed,
    required this.wins,
    required this.losses,
    required this.winRate,
    required this.pips,
    this.bestPair,
    this.worstPair,
    this.bestPips,
    this.worstPips,
  });

  factory ReportRow.fromJson(Map<String, dynamic> j) {
    final best = (j['best'] as Map?)?.cast<String, dynamic>();
    final worst = (j['worst'] as Map?)?.cast<String, dynamic>();
    return ReportRow(
      key: j['key'] as String,
      label: j['label'] as String,
      start: DateTime.parse(j['start'] as String),
      end: DateTime.parse(j['end'] as String),
      opened: (j['opened'] as num).toInt(),
      closed: (j['closed'] as num).toInt(),
      wins: (j['wins'] as num).toInt(),
      losses: (j['losses'] as num).toInt(),
      winRate: _d(j['win_rate']),
      pips: _d(j['pips']),
      bestPair: best?['pair'] as String?,
      bestPips: _dn(best?['pips']),
      worstPair: worst?['pair'] as String?,
      worstPips: _dn(worst?['pips']),
    );
  }
}

class ReportSummary {
  final ReportRow today, week, month;
  final int allClosed, allWins, allLosses, running;
  final double allPips, allWinRate;
  final DateTime? since;
  const ReportSummary({
    required this.today,
    required this.week,
    required this.month,
    required this.allClosed,
    required this.allWins,
    required this.allLosses,
    required this.allPips,
    required this.allWinRate,
    required this.running,
    required this.since,
  });

  factory ReportSummary.fromJson(Map<String, dynamic> j) {
    final all = (j['all'] as Map).cast<String, dynamic>();
    return ReportSummary(
      today: ReportRow.fromJson((j['today'] as Map).cast<String, dynamic>()),
      week: ReportRow.fromJson((j['week'] as Map).cast<String, dynamic>()),
      month: ReportRow.fromJson((j['month'] as Map).cast<String, dynamic>()),
      allClosed: (all['closed'] as num).toInt(),
      allWins: (all['wins'] as num).toInt(),
      allLosses: (all['losses'] as num).toInt(),
      allPips: _d(all['pips']),
      allWinRate: _d(all['win_rate']),
      running: (j['running'] as num?)?.toInt() ?? 0,
      since: _t(all['since']),
    );
  }
}

class ReportsData {
  final String period;
  final String timezone;
  final ReportSummary summary;
  final List<ReportRow> items;
  const ReportsData(this.period, this.timezone, this.summary, this.items);

  factory ReportsData.fromJson(Map<String, dynamic> j) => ReportsData(
        j['period'] as String,
        j['timezone'] as String? ?? 'UTC',
        ReportSummary.fromJson((j['summary'] as Map).cast<String, dynamic>()),
        ((j['items'] as List?) ?? const [])
            .map((e) => ReportRow.fromJson((e as Map).cast<String, dynamic>()))
            .toList(),
      );
}
