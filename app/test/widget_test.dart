import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/core/format.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/engine/engine.dart';
import 'package:forex_signals/models/models.dart';
import 'package:forex_signals/screens/legal_screen.dart';

List<Candle> series(double Function(int) f, {int n = 300}) {
  final out = <Candle>[];
  var prev = f(0);
  for (var i = 0; i < n; i++) {
    final c = f(i);
    out.add(Candle(DateTime.utc(2026, 1, 1).add(Duration(hours: i)), prev,
        math.max(prev, c) + 0.0003, math.min(prev, c) - 0.0003, c));
    prev = c;
  }
  return out;
}

void main() {
  test('uptrend produces a BUY with levels on the right side', () {
    final r = SignalEngine.analyze(series((i) =>
        1.08 + i * 0.0004 + 0.002 * math.sin(i / 4) + (i > 290 ? (i - 290) * 0.0008 : 0)))!;
    expect(r.direction, 'BUY');
    expect(r.sl!, lessThan(r.entry));
    expect(r.tp2!, greaterThan(r.tp1!));
  });

  test('flat market gives WAIT', () {
    expect(SignalEngine.analyze(series((i) => 1.1))!.direction, 'WAIT');
  });

  test('pip formatting', () {
    expect(Fmt.pipSize('USD/JPY'), 0.01);
    expect(Fmt.pips(12.34), '+12.3');
    expect(Fmt.price('XAU/USD', 2350.456), '2350.46');
    expect(Fmt.money(1234.5), '\$1,234.50');
  });

  test('parses a server signal', () {
    final s = TradeSignal.fromJson({
      'id': 1, 'pair': 'EUR/USD', 'timeframe': '1h', 'direction': 'SELL',
      'entry': 1.1, 'stop_loss': 1.103, 'tp1': 1.097, 'tp2': 1.094,
      'confidence': 80, 'reasons': ['a'], 'status': 'closed', 'result': 'tp2',
      'tp1_hit_at': '2026-09-01T10:00:00Z', 'opened_at': '2026-09-01T08:00:00Z',
      'expires_at': null, 'closed_at': '2026-09-01T12:00:00Z', 'close_price': 1.094,
      'pips': 60.0, 'current_price': 1.094, 'live_pips': 60.0,
      'candles': [[1790000000, 1.1, 1.101, 1.099, 1.1]],
    });
    expect(s.isBuy, isFalse);
    expect(s.slPips, closeTo(-30, 0.01));
    expect(s.result, SignalResult.tp2);
    expect(s.candles.length, 1);
  });

  test('demo stats add up', () async {
    final stats = await DemoApi().stats(0);
    expect(stats.wins + stats.losses, lessThanOrEqualTo(stats.total));
    if (stats.equity.isNotEmpty) {
      expect(stats.equity.last.pips, closeTo(stats.totalPips, 0.01));
    }
  });

  testWidgets('legal screen renders', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LegalScreen(doc: LegalDoc.risk)));
    expect(find.text('Risk Disclaimer'), findsOneWidget);
  });
}
