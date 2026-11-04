import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/data/api.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/models/gold_test.dart';
import 'package:forex_signals/screens/gold_test_screen.dart';
import 'package:provider/provider.dart';

class FakeGoldApi extends DemoApi {
  final Map<String, dynamic> json;
  FakeGoldApi(this.json);

  @override
  Future<GoldTestData> goldTest() async => GoldTestData.fromJson(json);
}

Map<String, dynamic> sample({String status = 'running', int trades = 3, double dropPct = 2.1}) => {
      'rules': {
        'start': '2026-11-04T00:00:00Z', 'pair': 'XAU/USD', 'strategies': ['steady', 'turtle'], 'min_days': 90,
        'min_trades': 60, 'risk_pct': 2, 'account': 10000, 'min_profit_factor': 1.2, 'max_drop_pct': 15,
        'earliest_end': '2027-02-02T00:00:00Z',
      },
      'status': status,
      'day': 7,
      'trades': trades,
      'wins': 2,
      'running': 1,
      'checks': [
        {'key': 'profit', 'label': 'Profit after costs', 'need': 'above \$0 at 2% risk', 'value': 7.4, 'unit': '%', 'pips': 482.5, 'ok': true},
        {'key': 'profit_factor', 'label': 'Profit factor', 'need': '1.2 or more', 'value': 5.77, 'unit': '', 'ok': true},
        {'key': 'drop', 'label': 'Worst drop', 'need': 'under 15%', 'value': dropPct, 'unit': '%', 'ok': dropPct < 15},
      ],
      'strategies': [
        {'strategy': 'steady', 'name': 'Steady', 'closed': 1, 'running': 0, 'pips': 197, 'live': true},
        {'strategy': 'turtle', 'name': 'Gold breakout', 'closed': 2, 'running': 1, 'pips': 285.5, 'live': true},
      ],
      'recent': [
        {'id': 3, 'pair': 'XAU/USD', 'strategy': 'turtle', 'direction': 'BUY', 'closed_at': '2026-11-07T10:00:00Z', 'result': 'tp2', 'pips': 397, 'usd': 799.2},
        {'id': 2, 'pair': 'XAU/USD', 'strategy': 'turtle', 'direction': 'BUY', 'closed_at': '2026-11-06T10:00:00Z', 'result': 'sl', 'pips': -103, 'usd': -206.1},
      ],
    };

Future<void> pumpScreen(WidgetTester tester, Api api) async {
  tester.view.physicalSize = const Size(320, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(Provider<Api>.value(value: api, child: const MaterialApp(home: GoldTestScreen())));
  for (var i = 0; i < 5; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets('shows progress, pass marks, strategies and trades on a narrow phone', (tester) async {
    await pumpScreen(tester, FakeGoldApi(sample()));
    expect(tester.takeException(), isNull);
    expect(find.text('Running'), findsOneWidget);
    expect(find.text('Day 7 of 90'), findsOneWidget);
    expect(find.text('3 of 60'), findsOneWidget);
    expect(find.text('+7.4%'), findsOneWidget);
    expect(find.text('5.77'), findsOneWidget);
    expect(find.text('2.1%'), findsOneWidget);
    expect(find.textContaining('Gold breakout · gold · 4-hour'), findsOneWidget);
    expect(find.text('2 closed · 1 open'), findsOneWidget);
    expect(find.textContaining('Too early to judge'), findsOneWidget);
  });

  testWidgets('a failed test says so plainly', (tester) async {
    await pumpScreen(tester, FakeGoldApi(sample(status: 'failed', trades: 12, dropPct: 17.1)));
    expect(tester.takeException(), isNull);
    expect(find.text('Failed'), findsOneWidget);
    expect(find.textContaining('stay free'), findsOneWidget);
  });

  testWidgets('without the live server it explains instead of crashing', (tester) async {
    await pumpScreen(tester, DemoApi());
    expect(tester.takeException(), isNull);
    expect(find.textContaining('needs the live server'), findsOneWidget);
  });
}
