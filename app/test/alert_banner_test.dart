import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:forex_signals/data/demo_api.dart';
import 'package:forex_signals/models/models.dart';
import 'package:forex_signals/services/push_service.dart';
import 'package:forex_signals/state/session_store.dart';
import 'package:forex_signals/widgets/alert_banner.dart';
import 'package:provider/provider.dart';

class FakePush extends PushService {
  bool allowed;
  bool allowOnRequest;
  int requests = 0;
  FakePush({required this.allowed, this.allowOnRequest = false}) {
    available = true;
  }

  @override
  Future<bool> permissionGranted() async => allowed;

  @override
  Future<bool> requestPermission() async {
    requests++;
    allowed = allowOnRequest;
    return allowed;
  }
}

Future<void> pumpBanner(WidgetTester tester, PushService push, {required bool verified}) async {
  final session = SessionStore(DemoApi(), push)
    ..user = AppUser(
      id: 1,
      name: 'Ada',
      email: 'ada@example.com',
      createdAt: null,
      emailVerified: verified,
      notifications: const NotificationPrefs(),
      watchlist: const [],
    );
  await tester.pumpWidget(ChangeNotifierProvider.value(
    value: session,
    child: const MaterialApp(home: Scaffold(body: AlertBanner())),
  ));
  await tester.pump();
}

const pushOff = 'Notifications are off on this phone';
const emailOff = 'Your email is not verified';

void main() {
  testWidgets('nothing shows when push is allowed and email is verified', (tester) async {
    await pumpBanner(tester, FakePush(allowed: true), verified: true);
    expect(find.textContaining(pushOff), findsNothing);
    expect(find.textContaining(emailOff), findsNothing);
  });

  testWidgets('unverified email shows the email banner', (tester) async {
    await pumpBanner(tester, FakePush(allowed: true), verified: false);
    expect(find.textContaining(emailOff), findsOneWidget);
  });

  testWidgets('blocked push wins over unverified email', (tester) async {
    await pumpBanner(tester, FakePush(allowed: false), verified: false);
    expect(find.textContaining(pushOff), findsOneWidget);
    expect(find.textContaining(emailOff), findsNothing);
  });

  testWidgets('tapping asks for permission and hides the banner when allowed', (tester) async {
    final push = FakePush(allowed: false, allowOnRequest: true);
    await pumpBanner(tester, push, verified: true);
    await tester.tap(find.textContaining(pushOff));
    await tester.pump();
    expect(push.requests, 1);
    expect(find.textContaining(pushOff), findsNothing);
  });

  testWidgets('refused permission points to phone settings', (tester) async {
    await pumpBanner(tester, FakePush(allowed: false), verified: true);
    await tester.tap(find.textContaining(pushOff));
    await tester.pump();
    expect(find.textContaining("Turn them on in your phone's Settings"), findsOneWidget);
  });

  testWidgets('no push banner when Firebase is not set up', (tester) async {
    await pumpBanner(tester, PushService(), verified: true);
    expect(find.textContaining(pushOff), findsNothing);
  });
}
