import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;

import 'package:http/http.dart' as http;

/// Minimal client for Deriv's Options API (https://developers.deriv.com).
///
/// Auth: the user's Personal Access Token (scope "trade") plus our Deriv App ID.
/// The token stays on the phone; it is never sent to our server.
/// Trades are Multipliers (MULTUP / MULTDOWN) with a stop loss and take profit in money.
class DerivClient {
  static const base = 'https://api.derivws.com';
  final String appId;
  final String token;
  final http.Client _http;
  DerivClient(this.appId, this.token, {http.Client? client}) : _http = client ?? http.Client();

  Map<String, String> get _headers => {
        'Authorization': 'Bearer $token',
        'Deriv-App-ID': appId,
        'Content-Type': 'application/json',
      };

  Future<dynamic> _rest(String method, String path) async {
    final uri = Uri.parse('$base$path');
    final res = await (method == 'GET' ? _http.get(uri, headers: _headers) : _http.post(uri, headers: _headers))
        .timeout(const Duration(seconds: 20));
    final body = res.body.isEmpty ? null : jsonDecode(res.body);
    if (res.statusCode == 401 || res.statusCode == 403) {
      throw const DerivException('Deriv rejected the token. Create a new one with the "Trade" scope.');
    }
    if (res.statusCode >= 300) {
      final msg = body is Map ? (body['error']?['message'] ?? body['message'] ?? '') : '';
      throw DerivException('Deriv error ${res.statusCode}${msg == '' ? '' : ': $msg'}');
    }
    return body is Map ? body['data'] : body;
  }

  /// The user's Options accounts (demo and real).
  Future<List<DerivAccount>> accounts() async {
    final data = await _rest('GET', '/trading/v1/options/accounts');
    final list = data is List ? data : (data is Map ? [data] : const []);
    return list.whereType<Map>().map((m) => DerivAccount.fromJson(m.cast<String, dynamic>())).toList();
  }

  /// Opens an authenticated trading connection for one account (one-time URL, valid 2 minutes).
  Future<DerivSession> connect(String accountId) async {
    final data = await _rest('POST', '/trading/v1/options/accounts/${Uri.encodeComponent(accountId)}/otp');
    final url = data is Map ? data['url'] as String? : null;
    if (url == null) throw const DerivException('Deriv did not return a trading connection.');
    final ws = await WebSocket.connect(url).timeout(const Duration(seconds: 20));
    return DerivSession._(ws);
  }

  void close() => _http.close();
}

class DerivAccount {
  final String id;
  final double balance;
  final String currency;
  final bool demo;
  const DerivAccount(this.id, this.balance, this.currency, this.demo);

  factory DerivAccount.fromJson(Map<String, dynamic> j) => DerivAccount(
        '${j['account_id'] ?? j['id'] ?? ''}',
        (j['balance'] as num?)?.toDouble() ?? 0,
        '${j['currency'] ?? 'USD'}',
        '${j['account_type'] ?? ''}' == 'demo',
      );
}

class DerivException implements Exception {
  final String message;
  const DerivException(this.message);
  @override
  String toString() => message;
}

class DerivSession {
  final WebSocket _ws;
  final _pending = <int, Completer<Map<String, dynamic>>>{};
  int _next = 1;

  DerivSession._(this._ws) {
    _ws.listen((raw) {
      final msg = (jsonDecode(raw as String) as Map).cast<String, dynamic>();
      final c = _pending.remove(msg['req_id']);
      if (c == null) return;
      final err = msg['error'];
      err is Map ? c.completeError(DerivException('${err['message'] ?? err['code']}')) : c.complete(msg);
    }, onDone: () {
      for (final c in _pending.values) {
        c.completeError(const DerivException('Connection to Deriv closed.'));
      }
      _pending.clear();
    });
  }

  Future<Map<String, dynamic>> send(Map<String, dynamic> req) {
    final id = _next++;
    final c = Completer<Map<String, dynamic>>();
    _pending[id] = c;
    _ws.add(jsonEncode({...req, 'req_id': id}));
    return c.future.timeout(const Duration(seconds: 20));
  }

  Future<double> balance() async => ((await send({'balance': 1}))['balance']['balance'] as num).toDouble();

  Future<double> spot(String symbol) async {
    final r = await send({'ticks_history': symbol, 'end': 'latest', 'count': 1, 'style': 'ticks'});
    return ((r['history']['prices'] as List).last as num).toDouble();
  }

  /// Multipliers Deriv allows on this symbol (e.g. [50, 100, 200, 500]).
  Future<List<int>> multipliers(String symbol) async {
    final r = await send({'contracts_for': symbol});
    final out = <int>{};
    for (final c in (r['contracts_for']['available'] as List).cast<Map>()) {
      if (c['contract_type'] == 'MULTUP' && c['multiplier_range'] is List) {
        out.addAll((c['multiplier_range'] as List).map((e) => (e as num).toInt()));
      }
    }
    return out.toList()..sort();
  }

  /// Open bot contracts (Multipliers) still running.
  Future<int> openContracts() async {
    final r = await send({'portfolio': 1, 'contract_type': ['MULTUP', 'MULTDOWN']});
    return ((r['portfolio']?['contracts'] as List?) ?? const []).length;
  }

  /// Profit or loss of contracts closed since [from] (negative = loss).
  Future<double> realisedSince(DateTime from) async {
    final r = await send({'profit_table': 1, 'description': 0, 'limit': 100, 'date_from': from.millisecondsSinceEpoch ~/ 1000});
    var sum = 0.0;
    for (final t in ((r['profit_table']?['transactions'] as List?) ?? const []).cast<Map>()) {
      sum += ((t['sell_price'] as num?)?.toDouble() ?? 0) - ((t['buy_price'] as num?)?.toDouble() ?? 0);
    }
    return sum;
  }

  Future<Map<String, dynamic>> buy(DerivOrder o, String currency) async {
    final r = await send({
      'buy': '1',
      'price': o.stake,
      'parameters': {
        'amount': o.stake,
        'basis': 'stake',
        'contract_type': o.up ? 'MULTUP' : 'MULTDOWN',
        'currency': currency,
        'symbol': o.symbol,
        'multiplier': o.multiplier,
        'limit_order': {'stop_loss': o.stopLoss, 'take_profit': o.takeProfit},
      },
    });
    return (r['buy'] as Map).cast<String, dynamic>();
  }

  Future<void> close() => _ws.close();
}

/// A Multipliers order sized so that hitting the signal's stop loss loses about [stopLoss].
class DerivOrder {
  final String symbol;
  final bool up;
  final double stake;
  final int multiplier;
  final double stopLoss;
  final double takeProfit;
  const DerivOrder(this.symbol, this.up, this.stake, this.multiplier, this.stopLoss, this.takeProfit);

  /// Deriv symbol for our pair names.
  static String? symbolFor(String pair) => const {
        'EUR/USD': 'frxEURUSD', 'GBP/USD': 'frxGBPUSD', 'USD/JPY': 'frxUSDJPY', 'AUD/USD': 'frxAUDUSD',
        'USD/CAD': 'frxUSDCAD', 'USD/CHF': 'frxUSDCHF', 'NZD/USD': 'frxNZDUSD', 'XAU/USD': 'frxXAUUSD',
        'BTC/USD': 'cryBTCUSD', 'ETH/USD': 'cryETHUSD',
      }[pair];

  /// Profit/loss of a Multipliers contract ≈ stake × multiplier × (price move ÷ entry price).
  /// We pick the biggest allowed multiplier that keeps the stop-loss amount within the stake,
  /// then set the stake so the stop loss costs [risk].
  /// Returns null (with a reason) when the trade can't be placed safely.
  static (DerivOrder?, String?) plan({
    required String pair,
    required bool buy,
    required double spot,
    required double entry,
    required double stopLoss,
    required double target,
    required double risk,
    required List<int> multipliers,
    double maxDrift = 0.3,
  }) {
    final symbol = symbolFor(pair);
    if (symbol == null) return (null, 'Deriv has no $pair market');
    final slDist = (spot - stopLoss) * (buy ? 1 : -1);
    final tpDist = (target - spot) * (buy ? 1 : -1);
    final plannedSl = (entry - stopLoss).abs();
    if (slDist <= 0 || tpDist <= 0) return (null, 'price is already past the stop loss or target');
    if ((spot - entry).abs() > plannedSl * maxDrift) return (null, 'price has moved too far from the entry');
    final ideal = spot / slDist; // multiplier at which the stop loss would equal the whole stake
    final usable = multipliers.where((m) => m <= ideal).toList();
    if (usable.isEmpty) return (null, 'stop loss is too wide for Deriv multipliers on $pair');
    final m = usable.reduce(math.max);
    final lossPerStake = m * slDist / spot; // ≤ 1
    final stake = _round(risk / lossPerStake);
    if (stake < 1) return (null, 'risk amount is below Deriv\'s \$1 minimum stake');
    final sl = _round(math.min(stake, stake * lossPerStake));
    final tp = _round(stake * m * tpDist / spot);
    return (DerivOrder(symbol, buy, stake, m, sl, tp), null);
  }

  static double _round(double v) => (v * 100).floorToDouble() / 100;
}
