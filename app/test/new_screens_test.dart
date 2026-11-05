import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/core/theme.dart';
import 'package:forex_signals/data/api.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/screens/bot_screen.dart';
import 'package:forex_signals/screens/money_screen.dart';
import 'package:forex_signals/services/push_service.dart';
import 'package:forex_signals/state/data_stores.dart';
import 'package:forex_signals/state/session_store.dart';
import 'package:provider/provider.dart';

/// Smoke tests for the screens added after the last release: they must build
/// on a small phone (320 px wide) with no layout overflow or exceptions.
Future<void> pumpScreen(WidgetTester tester, Widget screen) async {
  tester.view.physicalSize = const Size(320, 900);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final api = DemoApi();
  await tester.pumpWidget(MultiProvider(
    providers: [
      Provider<Api>.value(value: api),
      ChangeNotifierProvider.value(value: SessionStore(api, PushService())),
      ChangeNotifierProvider.value(value: SignalsStore(api)),
      ChangeNotifierProvider.value(value: StatsStore(api)),
      ChangeNotifierProvider.value(value: MarketStore(api)),
      ChangeNotifierProvider.value(value: MoneyStore(api)),
    ],
    child: MaterialApp(theme: buildTheme(), home: Scaffold(body: screen)),
  ));
  for (var i = 0; i < 20; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets('Your money screen builds on a narrow phone', (tester) async {
    await pumpScreen(tester, const MoneyScreen());
    expect(tester.takeException(), isNull);
    expect(find.byType(MoneyScreen), findsOneWidget);
  });

  testWidgets('Auto-trade bot screen builds on a narrow phone', (tester) async {
    await pumpScreen(tester, const BotScreen());
    expect(tester.takeException(), isNull);
    expect(find.byType(BotScreen), findsOneWidget);
  });
}
