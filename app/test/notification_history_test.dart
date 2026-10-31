import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/models/models.dart';
import 'package:forex_signals/screens/signals_screen.dart';
import 'package:forex_signals/services/background_signal_service.dart';
import 'package:forex_signals/services/push_service.dart';
import 'package:forex_signals/state/data_stores.dart';
import 'package:forex_signals/state/session_store.dart';
import 'package:forex_signals/widgets/signal_card.dart';
import 'package:provider/provider.dart';

TradeSignal signal(int id, {String pair = 'EUR/USD', String result = 'expired',
    double pips = -4, double stop = 1.099, bool running = false,
    bool tp1Hit = false}) => TradeSignal.fromJson({
  'id': id,
  'pair': pair,
  'timeframe': '1h',
  'direction': 'BUY',
  'entry': 1.1,
  'stop_loss': stop,
  'tp1': 1.101,
  'tp2': 1.102,
  'confidence': 70,
  'reasons': <String>[],
  'status': running ? 'running' : 'closed',
  'result': running ? null : result,
  'tp1_hit_at': tp1Hit ? '2026-01-01T13:00:00Z' : null,
  'opened_at': '2026-01-01T10:00:00Z',
  'expires_at': '2026-01-04T10:00:00Z',
  'closed_at': '2026-01-02T10:00:00Z',
  'close_price': 1.0996,
  'pips': pips,
  'current_price': 1.0996,
  'live_pips': null,
});

void main() {
  test('minor loss hides only small negative expiries', () {
    expect(isMinorLoss(signal(1)), isTrue); // -4 pips against 10 pips initial risk.
    expect(isMinorLoss(signal(2, pips: -6)), isFalse);
    expect(isMinorLoss(signal(3, result: 'sl')), isFalse);
    expect(isMinorLoss(signal(4, result: 'tp1')), isFalse);
    expect(isMinorLoss(signal(5, stop: 1.1)), isFalse);
  });

  test('background event selection respects stages, pairs and preferences', () {
    final items = [signal(3), signal(1), signal(4, pair: 'GBP/USD'), signal(2)];
    final filtered = unseenSignalAlerts(items, {'1:closed'},
        const NotificationPrefs(signals: true, pairs: ['EUR/USD']));
    expect(filtered.map((e) => e.signal.id).toList(), [2, 3]);
    expect(unseenSignalAlerts(items, {},
        const NotificationPrefs(signals: false, results: false)), isEmpty);
    expect(unseenSignalAlerts(items, {},
        const NotificationPrefs(signals: true, results: false)), isEmpty);
    expect(unseenSignalAlerts(items, {},
        const NotificationPrefs(signals: false, results: true)).length, 4);

    final open = signal(7, running: true);
    final tp1 = signal(7, running: true, tp1Hit: true);
    final closed = signal(7, result: 'sl', pips: -10);
    expect(unseenSignalAlerts([open], {}, const NotificationPrefs()).single.stage, 'open');
    expect(unseenSignalAlerts([tp1], {'7:open'}, const NotificationPrefs()).single.stage, 'tp1');
    expect(unseenSignalAlerts([closed], {'7:open', '7:tp1'},
        const NotificationPrefs()).single.stage, 'closed');
    expect(unseenSignalAlerts([tp1], {'7:tp1'}, const NotificationPrefs()), isEmpty);
    expect(unseenSignalAlerts([open], {},
        const NotificationPrefs(signals: false, results: true)), isEmpty);
  });

  testWidgets('history collapses small losses but keeps them accessible', (tester) async {
    tester.view.physicalSize = const Size(390, 1100);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final api = DemoApi();
    final store = SignalsStore(api)
      ..loadedOnce = true
      ..history = [signal(1)]
      ..hasMoreHistory = true;
    await tester.pumpWidget(MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: SessionStore(api, PushService())),
        ChangeNotifierProvider.value(value: store),
        ChangeNotifierProvider.value(value: StatsStore(api)),
      ],
      child: MaterialApp(home: Scaffold(body: SignalsScreen(onSeeResults: () {}))),
    ));

    await tester.tap(find.text('History'));
    await tester.pump();
    expect(find.textContaining('Filtered history: 1 smaller expired losses hidden in loaded results'), findsOneWidget);
    expect(find.text('Show all loaded results'), findsOneWidget);
    expect(find.text('Smaller expired losses are collapsed'), findsOneWidget);
    expect(find.text('Load more history'), findsOneWidget);
    expect(find.byType(SignalCard), findsNothing);

    await tester.tap(find.text('Show all loaded results'));
    await tester.pump();
    expect(find.byType(SignalCard), findsOneWidget);
    expect(find.text('Hide smaller expired losses'), findsOneWidget);
  });
}
