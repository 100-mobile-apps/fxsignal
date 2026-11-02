import 'models.dart';

double _num(dynamic v) => (v as num?)?.toDouble() ?? 0;
int _int(dynamic v) => (v as num?)?.toInt() ?? 0;

Map<String, dynamic> _map(dynamic v) => (v as Map).cast<String, dynamic>();
List<Map<String, dynamic>> _list(dynamic v) =>
    ((v as List?) ?? const []).map((e) => _map(e)).toList();

/// One scenario for a signal: what happens, in pips and dollars.
class MoneyLine {
  final String label;
  final double pips;
  final double usd;
  const MoneyLine(this.label, this.pips, this.usd);
}

/// What one signal would make or lose for a given account size and risk.
class MoneyView {
  final double wantedRisk;
  final double actualRisk;
  final double lots;
  final bool tooSmall;
  final List<MoneyLine> lines;
  const MoneyView(this.wantedRisk, this.actualRisk, this.lots, this.tooSmall, this.lines);

  MoneyLine get stop => lines[0];
  MoneyLine get tp1Back => lines[1];
  MoneyLine get locked => lines[2];
  MoneyLine get tp2 => lines[3];
}

/// Two half-size trades (TP1 and TP2). Position size is rounded down to 0.01 lot steps per trade.
MoneyView moneyFor(TradeSignal s, double account, double riskPct) {
  final stop = s.slPips.abs();
  final wanted = account * riskPct / 100;
  final pv = s.pipValuePerLot <= 0 ? 10.0 : s.pipValuePerLot;
  final raw = stop > 0 ? wanted / (stop * pv) : 0.0;
  var half = (raw / 2 / 0.01).floor() * 0.01;
  var tooSmall = false;
  if (half < 0.01) {
    half = 0.01;
    tooSmall = true;
  }
  final lots = double.parse((half * 2).toStringAsFixed(2));
  final cost = s.costPips;
  double usd(double pips) => lots * pv * (pips - cost);
  final t1 = s.tp1Pips.abs();
  final t2 = s.tp2Pips.abs();
  final lines = [
    MoneyLine('Stop loss hit', -stop, usd(-stop)),
    MoneyLine('TP1, then back to entry', t1 / 2, usd(t1 / 2)),
    MoneyLine('TP1, halfway, back to TP1', t1, usd(t1)),
    MoneyLine('TP2 hit', (t1 + t2) / 2, usd((t1 + t2) / 2)),
  ];
  return MoneyView(wanted, lots * stop * pv, lots, tooSmall, lines);
}

class MoneySummary {
  final int trades, wins, losses, worstLosingRun;
  final double winRate, pips, profit, endBalance, maxDropPct, lowestBalance, bestTrade, worstTrade;
  const MoneySummary({
    this.trades = 0,
    this.wins = 0,
    this.losses = 0,
    this.worstLosingRun = 0,
    this.winRate = 0,
    this.pips = 0,
    this.profit = 0,
    this.endBalance = 0,
    this.maxDropPct = 0,
    this.lowestBalance = 0,
    this.bestTrade = 0,
    this.worstTrade = 0,
  });

  factory MoneySummary.fromJson(Map<String, dynamic> j) => MoneySummary(
        trades: _int(j['trades']),
        wins: _int(j['wins']),
        losses: _int(j['losses']),
        worstLosingRun: _int(j['worst_losing_run']),
        winRate: _num(j['win_rate']),
        pips: _num(j['pips']),
        profit: _num(j['profit']),
        endBalance: _num(j['end_balance']),
        maxDropPct: _num(j['max_drop_pct']),
        lowestBalance: _num(j['lowest_balance']),
        bestTrade: _num(j['best_trade']),
        worstTrade: _num(j['worst_trade']),
      );
}

class RiskLevel {
  final double risk, profit, endBalance, maxDropPct, lowestBalance;
  const RiskLevel(this.risk, this.profit, this.endBalance, this.maxDropPct, this.lowestBalance);

  factory RiskLevel.fromJson(Map<String, dynamic> j) => RiskLevel(
        _num(j['risk']),
        _num(j['profit']),
        _num(j['end_balance']),
        _num(j['max_drop_pct']),
        _num(j['lowest_balance']),
      );
}

class MonthMoney {
  final String month;
  final int trades, wins;
  final double pips, profit, endBalance;
  const MonthMoney(this.month, this.trades, this.wins, this.pips, this.profit, this.endBalance);

  factory MonthMoney.fromJson(Map<String, dynamic> j) => MonthMoney(
        j['month'] as String? ?? '',
        _int(j['trades']),
        _int(j['wins']),
        _num(j['pips']),
        _num(j['profit']),
        _num(j['end_balance']),
      );
}

class MarketMoney {
  final String pair, status;
  final int trades;
  final double winRate, pips, profit, maxDropPct, avgStopPips, pipValuePerLot, minRisk;
  const MarketMoney({
    required this.pair,
    required this.status,
    required this.trades,
    required this.winRate,
    required this.pips,
    required this.profit,
    required this.maxDropPct,
    required this.avgStopPips,
    required this.pipValuePerLot,
    required this.minRisk,
  });

  factory MarketMoney.fromJson(Map<String, dynamic> j) => MarketMoney(
        pair: j['pair'] as String? ?? '',
        status: j['status'] as String? ?? 'new',
        trades: _int(j['trades']),
        winRate: _num(j['win_rate']),
        pips: _num(j['pips']),
        profit: _num(j['profit']),
        maxDropPct: _num(j['max_drop_pct']),
        avgStopPips: _num(j['avg_stop_pips']),
        pipValuePerLot: _num(j['pip_value_per_lot']),
        minRisk: _num(j['min_risk']),
      );
}

class StrategyMoney {
  final String key, name;
  final int trades;
  final double winRate, pips, profit, maxDropPct;
  const StrategyMoney(this.key, this.name, this.trades, this.winRate, this.pips, this.profit, this.maxDropPct);

  factory StrategyMoney.fromJson(Map<String, dynamic> j) => StrategyMoney(
        j['strategy'] as String? ?? '',
        j['name'] as String? ?? '',
        _int(j['trades']),
        _num(j['win_rate']),
        _num(j['pips']),
        _num(j['profit']),
        _num(j['max_drop_pct']),
      );
}

class TradeMoney {
  final int id;
  final String pair, strategy, direction, result;
  final DateTime? closedAt;
  final double pips, risked, usd, balance;
  const TradeMoney({
    required this.id,
    required this.pair,
    required this.strategy,
    required this.direction,
    required this.result,
    required this.closedAt,
    required this.pips,
    required this.risked,
    required this.usd,
    required this.balance,
  });

  factory TradeMoney.fromJson(Map<String, dynamic> j) => TradeMoney(
        id: _int(j['id']),
        pair: j['pair'] as String? ?? '',
        strategy: j['strategy'] as String? ?? 'classic',
        direction: j['direction'] as String? ?? '',
        result: j['result'] as String? ?? '',
        closedAt: j['closed_at'] == null ? null : DateTime.tryParse(j['closed_at'] as String),
        pips: _num(j['pips']),
        risked: _num(j['risked']),
        usd: _num(j['usd']),
        balance: _num(j['balance']),
      );
}

class ResultsData {
  final double account, risk;
  final MoneySummary summary;
  final List<RiskLevel> riskLevels;
  final List<MonthMoney> months;
  final List<MarketMoney> byMarket;
  final List<StrategyMoney> byStrategy;
  final List<TradeMoney> trades;
  final List<String> bestMarkets;
  final String bestStrategy;
  final String note;
  final DateTime? since;

  const ResultsData({
    required this.account,
    required this.risk,
    required this.summary,
    required this.riskLevels,
    required this.months,
    required this.byMarket,
    required this.byStrategy,
    required this.trades,
    required this.bestMarkets,
    required this.bestStrategy,
    required this.note,
    required this.since,
  });

  factory ResultsData.fromJson(Map<String, dynamic> j) {
    final p = (j['params'] as Map?)?.cast<String, dynamic>() ?? const {};
    return ResultsData(
      account: _num(p['account']),
      risk: _num(p['risk']),
      summary: MoneySummary.fromJson(_map(j['summary'])),
      riskLevels: _list(j['risk_levels']).map(RiskLevel.fromJson).toList(),
      months: _list(j['months']).map(MonthMoney.fromJson).toList(),
      byMarket: _list(j['by_market']).map(MarketMoney.fromJson).toList(),
      byStrategy: _list(j['by_strategy']).map(StrategyMoney.fromJson).toList(),
      trades: _list(j['trades']).map(TradeMoney.fromJson).toList(),
      bestMarkets: ((j['best_markets'] as List?) ?? const []).cast<String>(),
      bestStrategy: j['best_strategy'] as String? ?? 'classic',
      note: j['note'] as String? ?? '',
      since: j['since'] == null ? null : DateTime.tryParse(j['since'] as String),
    );
  }
}

class LiveStrategyStat {
  final int trades;
  final double winRate, score, pips;
  const LiveStrategyStat(this.trades, this.winRate, this.score, this.pips);
}

class StrategyItem {
  final String key, name, blurb;
  final bool optIn;
  final LiveStrategyStat? live;
  const StrategyItem(this.key, this.name, this.blurb, this.optIn, this.live);
}

class MarketBadge {
  final String pair, status;
  final int trades;
  const MarketBadge(this.pair, this.status, this.trades);
}

class StrategiesData {
  final List<StrategyItem> items;
  final String defaultKey, selected;
  final List<MarketBadge> markets;
  final List<String> bestMarkets;
  const StrategiesData(this.items, this.defaultKey, this.selected, this.markets, this.bestMarkets);

  factory StrategiesData.fromJson(Map<String, dynamic> j) => StrategiesData(
        _list(j['items'])
            .map((e) => StrategyItem(
                  e['key'] as String? ?? '',
                  e['name'] as String? ?? '',
                  e['blurb'] as String? ?? '',
                  e['opt_in'] as bool? ?? false,
                  e['live'] == null
                      ? null
                      : LiveStrategyStat(
                          _int(_map(e['live'])['trades']),
                          _num(_map(e['live'])['win_rate']),
                          _num(_map(e['live'])['score']),
                          _num(_map(e['live'])['pips'])),
                ))
            .toList(),
        j['default'] as String? ?? 'classic',
        j['selected'] as String? ?? 'classic',
        _list(j['markets'])
            .map((e) => MarketBadge(e['pair'] as String? ?? '', e['status'] as String? ?? 'new', _int(e['trades'])))
            .toList(),
        ((j['best_markets'] as List?) ?? const []).cast<String>(),
      );
}
