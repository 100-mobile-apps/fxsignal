import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/models/models.dart';
import 'package:forex_signals/services/deriv_client.dart';
import 'package:forex_signals/state/bot_store.dart';

TradeSignal sig(int id, String pair, {required double entry, required double sl, required double tp1, double? pips,
        bool running = false, DateTime? opened, bool locked = false}) =>
    TradeSignal.fromJson({
      'id': id, 'pair': pair, 'timeframe': '4h', 'direction': 'BUY', 'entry': entry, 'stop_loss': sl,
      'tp1': tp1, 'tp2': tp1 + (tp1 - entry), 'confidence': 80, 'reasons': [], 'status': running ? 'running' : 'closed',
      'pips': pips, 'live_pips': running ? pips : null,
      'opened_at': (opened ?? DateTime.utc(2026, 11, 2)).toIso8601String(), 'locked': locked,
    });

void main() {
  group('Deriv order sizing', () {
    test('stop loss costs about the chosen risk and stays within the stake', () {
      final (o, why) = DerivOrder.plan(
          pair: 'EUR/USD', buy: true, spot: 1.1000, entry: 1.1000, stopLoss: 1.0970, target: 1.1015,
          risk: 10, multipliers: const [50, 100, 200, 300]);
      expect(why, isNull);
      expect(o!.symbol, 'frxEURUSD');
      expect(o.multiplier, 300); // ideal = 1.1 / 0.003 ≈ 366
      expect(o.stopLoss, closeTo(10, 0.05));
      expect(o.stopLoss, lessThanOrEqualTo(o.stake));
      expect(o.takeProfit, closeTo(5, 0.05)); // TP1 is half the stop distance
    });

    test('sell orders and gold', () {
      final (o, _) = DerivOrder.plan(
          pair: 'XAU/USD', buy: false, spot: 2350, entry: 2350, stopLoss: 2365, target: 2335,
          risk: 20, multipliers: const [10, 50, 100, 200]);
      expect(o!.up, isFalse);
      expect(o.multiplier, 100); // ideal ≈ 156
      expect(o.stopLoss, closeTo(20, 0.05));
    });

    test('refuses when price ran away, stop too wide, or past the stop', () {
      expect(DerivOrder.plan(pair: 'EUR/USD', buy: true, spot: 1.1012, entry: 1.1000, stopLoss: 1.0970,
          target: 1.1015, risk: 10, multipliers: const [100]).$2, contains('moved too far'));
      expect(DerivOrder.plan(pair: 'BTC/USD', buy: true, spot: 90000, entry: 90000, stopLoss: 70000,
          target: 110000, risk: 10, multipliers: const [10, 20]).$2, contains('too wide'));
      expect(DerivOrder.plan(pair: 'EUR/USD', buy: true, spot: 1.0960, entry: 1.0965, stopLoss: 1.0970,
          target: 1.1015, risk: 10, multipliers: const [100]).$2, contains('past the stop'));
      expect(DerivOrder.plan(pair: 'EUR/USD', buy: true, spot: 1.1, entry: 1.1, stopLoss: 1.097,
          target: 1.1015, risk: 0.2, multipliers: const [300]).$2, contains('minimum stake'));
    });
  });

  group('Paper auto-pilot', () {
    test('compounds closed results at the chosen risk and skips locked or other markets', () {
      final since = DateTime.utc(2026, 11, 1);
      final s = BotSettings(paperSince: since, paperStart: 1000, riskPct: 1, markets: const {'EUR/USD', 'GBP/USD'});
      final trades = BotEngine.paper([
        sig(1, 'EUR/USD', entry: 1.1, sl: 1.097, tp1: 1.103, pips: 30, opened: DateTime.utc(2026, 11, 2)), // +1R
        sig(2, 'EUR/USD', entry: 1.1, sl: 1.097, tp1: 1.103, pips: -30, opened: DateTime.utc(2026, 11, 3)), // -1R
        sig(3, 'XAU/USD', entry: 2000, sl: 1990, tp1: 2010, pips: 100, opened: DateTime.utc(2026, 11, 3)), // not followed
        sig(4, 'GBP/USD', entry: 1.3, sl: 1.297, tp1: 1.303, pips: 15, running: true, opened: DateTime.utc(2026, 11, 4)),
        sig(5, 'EUR/USD', entry: 1.1, sl: 1.097, tp1: 1.103, pips: 30, opened: DateTime.utc(2026, 10, 1)), // before start
      ], s);
      expect(trades.map((t) => t.signal.id), [1, 2, 4]);
      expect(trades[0].balanceAfter, closeTo(1010, 1e-9));
      expect(trades[1].balanceAfter, closeTo(999.9, 1e-9));
      expect(trades[2].r, closeTo(0.5, 1e-9)); // open trade: not added to the balance
      expect(trades[2].balanceAfter, closeTo(999.9, 1e-9));
    });
  });

  test('copy-trade lot size', () {
    // $1,000 at 1% = $10; 30-pip stop at $10/pip per lot → 0.03 lots
    expect(BotStore.lots(pair: 'EUR/USD', balance: 1000, riskPct: 1, entry: 1.1, stopLoss: 1.097, pipValuePerLot: 10),
        closeTo(0.03, 1e-9));
  });
}
