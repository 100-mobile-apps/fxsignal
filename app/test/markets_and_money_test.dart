import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/models/models.dart';
import 'package:forex_signals/screens/signals_screen.dart';
import 'package:forex_signals/services/push_service.dart';
import 'package:forex_signals/state/data_stores.dart';
import 'package:forex_signals/state/session_store.dart';
import 'package:forex_signals/widgets/money_widgets.dart';
import 'package:provider/provider.dart';

TradeSignal gold({bool running = true, double? pips}) => TradeSignal.fromJson({
      'id': 1,
      'pair': 'XAU/USD',
      'timeframe': '1h',
      'direction': 'BUY',
      'entry': 2350.0,
      'stop_loss': 2340.0,
      'tp1': 2360.0,
      'tp2': 2380.0,
      'confidence': 70,
      'reasons': <String>[],
      'status': running ? 'running' : 'closed',
      'result': running ? null : 'tp2',
      'opened_at': '2026-11-01T10:00:00Z',
      'expires_at': '2026-11-04T10:00:00Z',
      'closed_at': running ? null : '2026-11-02T10:00:00Z',
      'pips': pips,
      'stop_pips': 100.0,
      'tp1_pips': 100.0,
      'tp2_pips': 300.0,
      'pip_value_per_lot': 10.0,
      'cost_pips': 3.0,
    });

SessionStore session(double risk) => SessionStore(DemoApi(), PushService())
  ..user = AppUser(
    id: 1,
    name: 'Ada',
    email: 'a@x.com',
    createdAt: null,
    emailVerified: true,
    notifications: const NotificationPrefs(),
    watchlist: const [],
    prefs: UserPrefs(accountSize: 10000, riskPct: risk),
  );

Future<void> pumpLine(WidgetTester tester, TradeSignal s, double risk) async {
  tester.view.physicalSize = const Size(320, 600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(ChangeNotifierProvider.value(
    value: session(risk),
    child: MaterialApp(home: Scaffold(body: SignalMoneyLine(signal: s))),
  ));
}

String allText(WidgetTester tester) => tester
    .widgetList<RichText>(find.byType(RichText))
    .map((r) => r.text.toPlainText())
    .join(' | ');

void main() {
  group('markets', () {
    test('settings changes do not freeze the best markets', () {
      const followsBest = NotificationPrefs(pairs: ['XAU/USD', 'USD/CAD', 'GBP/USD'], pairsChosen: false);
      expect(followsBest.copyWith(signals: false).toJson()['pairs'], isNull);
      final picked = followsBest.copyWith(pairs: ['EUR/USD']);
      expect(picked.pairsChosen, isTrue);
      expect(picked.toJson()['pairs'], ['EUR/USD']);
      final back = picked.copyWith(allPairs: true);
      expect(back.pairsChosen, isFalse);
      expect(back.toJson()['pairs'], isNull);
    });

    test('older servers without pairs_chosen keep the old meaning', () {
      expect(NotificationPrefs.fromJson({'pairs': ['EUR/USD']}).pairsChosen, isTrue);
      expect(NotificationPrefs.fromJson({'pairs': null}).pairsChosen, isFalse);
      expect(NotificationPrefs.fromJson({'pairs': ['XAU/USD'], 'pairs_chosen': false}).pairsChosen, isFalse);
    });

    testWidgets('Signals tab shows the best markets and can switch to all', (tester) async {
      tester.view.physicalSize = const Size(320, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = DemoApi();
      final signals = SignalsStore(api);
      final stats = StatsStore(api);
      await tester.pumpWidget(MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: session(1)),
          ChangeNotifierProvider.value(value: signals),
          ChangeNotifierProvider.value(value: stats),
        ],
        child: MaterialApp(home: Scaffold(body: SignalsScreen(onSeeResults: () {}))),
      ));
      signals.refresh();
      for (var i = 0; i < 20; i++) {
        await tester.pump(const Duration(milliseconds: 200));
      }
      expect(find.text('Your markets: Gold, USD/CAD, GBP/USD'), findsOneWidget);
      expect(find.textContaining('best markets of the last 14 days'), findsOneWidget);
      expect(signals.running.every((s) => ['XAU/USD', 'USD/CAD', 'GBP/USD'].contains(s.pair)), isTrue);

      await tester.tap(find.byType(Switch));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 200));
      }
      expect(signals.allMarkets, isTrue);
      expect(stats.allMarkets, isTrue);
      expect(find.text('Showing all markets'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('money at 30%', () {
    testWidgets('open signal shows the user risk and 30%', (tester) async {
      await pumpLine(tester, gold(), 1);
      final t = allText(tester);
      // 1% of $10,000 = $100 over a 100-pip stop: 0.10 lots, $1 a pip. Stop: (-100-3) = -$103.
      expect(t, contains('At 1%'));
      expect(t, contains('-\$103.00'));
      // 30% = $3,000: 3.00 lots, $30 a pip. Stop -$3,090; TP2 (100+300)/2 - 3 = 197 pips = +$5,910.
      expect(t, contains('At 30%'));
      expect(t, contains('-\$3,090.00'));
      expect(t, contains('+\$5,910.00'));
      expect(tester.takeException(), isNull);
    });

    testWidgets('no duplicate row when the user already uses 30%', (tester) async {
      await pumpLine(tester, gold(), 30);
      expect(find.text('At 30%'), findsOneWidget);
      expect(find.text('At 1%'), findsNothing);
    });

    testWidgets('closed trade shows the dollar result at both risks', (tester) async {
      await pumpLine(tester, gold(running: false, pips: 200), 1);
      final t = allText(tester);
      expect(t, contains('Result'));
      expect(t, contains('+\$197.00'));
      expect(t, contains('+\$5,910.00'));
    });
  });
}
