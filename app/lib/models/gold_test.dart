double? _dn(dynamic v) => (v as num?)?.toDouble();
int _int(dynamic v) => (v as num?)?.toInt() ?? 0;
Map<String, dynamic> _map(dynamic v) => ((v as Map?) ?? const {}).cast<String, dynamic>();
List<Map<String, dynamic>> _list(dynamic v) => ((v as List?) ?? const []).map(_map).toList();

/// One pass mark of the gold live test and where it stands now.
class GoldCheck {
  final String key, label, need, unit;
  final double? value;
  final double? pips;
  final bool ok;
  const GoldCheck(this.key, this.label, this.need, this.unit, this.value, this.pips, this.ok);

  factory GoldCheck.fromJson(Map<String, dynamic> j) => GoldCheck(
        j['key'] as String? ?? '',
        j['label'] as String? ?? '',
        j['need'] as String? ?? '',
        j['unit'] as String? ?? '',
        _dn(j['value']),
        _dn(j['pips']),
        j['ok'] == true,
      );
}

class GoldStrategy {
  final String key, name;
  final int closed, running;
  final double pips;
  final bool live;
  const GoldStrategy(this.key, this.name, this.closed, this.running, this.pips, this.live);

  factory GoldStrategy.fromJson(Map<String, dynamic> j) => GoldStrategy(
        j['strategy'] as String? ?? '',
        j['name'] as String? ?? '',
        _int(j['closed']),
        _int(j['running']),
        _dn(j['pips']) ?? 0,
        j['live'] != false,
      );
}

class GoldTrade {
  final String strategy, direction, result;
  final DateTime? closedAt;
  final double pips, usd;
  const GoldTrade(this.strategy, this.direction, this.result, this.closedAt, this.pips, this.usd);

  factory GoldTrade.fromJson(Map<String, dynamic> j) => GoldTrade(
        j['strategy'] as String? ?? '',
        j['direction'] as String? ?? '',
        j['result'] as String? ?? '',
        DateTime.tryParse(j['closed_at'] as String? ?? '')?.toLocal(),
        _dn(j['pips']) ?? 0,
        _dn(j['usd']) ?? 0,
      );
}

/// GET /gold-test: rules fixed before the test began, and the live figures against them.
class GoldTestData {
  final String status; // running | passed | failed
  final int day, trades, wins, running;
  final int minDays, minTrades;
  final double riskPct, account;
  final DateTime? start, earliestEnd;
  final List<GoldCheck> checks;
  final List<GoldStrategy> strategies;
  final List<GoldTrade> recent;

  const GoldTestData({
    required this.status,
    required this.day,
    required this.trades,
    required this.wins,
    required this.running,
    required this.minDays,
    required this.minTrades,
    required this.riskPct,
    required this.account,
    required this.start,
    required this.earliestEnd,
    required this.checks,
    required this.strategies,
    required this.recent,
  });

  factory GoldTestData.fromJson(Map<String, dynamic> j) {
    final r = _map(j['rules']);
    return GoldTestData(
      status: j['status'] as String? ?? 'running',
      day: _int(j['day']),
      trades: _int(j['trades']),
      wins: _int(j['wins']),
      running: _int(j['running']),
      minDays: _int(r['min_days']),
      minTrades: _int(r['min_trades']),
      riskPct: _dn(r['risk_pct']) ?? 2,
      account: _dn(r['account']) ?? 10000,
      start: DateTime.tryParse(r['start'] as String? ?? '')?.toLocal(),
      earliestEnd: DateTime.tryParse(r['earliest_end'] as String? ?? '')?.toLocal(),
      checks: _list(j['checks']).map(GoldCheck.fromJson).toList(),
      strategies: _list(j['strategies']).map(GoldStrategy.fromJson).toList(),
      recent: _list(j['recent']).map(GoldTrade.fromJson).toList(),
    );
  }
}
