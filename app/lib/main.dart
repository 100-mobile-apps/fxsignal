import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import 'core/config.dart';
import 'core/theme.dart';
import 'data/api.dart';
import 'data/demo_api.dart';
import 'data/remote_api.dart';
import 'screens/auth_screens.dart';
import 'screens/home_shell.dart';
import 'screens/onboarding_screen.dart';
import 'screens/signal_detail_screen.dart';
import 'services/developer.dart';
import 'services/background_signal_service.dart';
import 'services/push_service.dart';
import 'services/review_service.dart';
import 'state/billing_store.dart';
import 'state/data_stores.dart';
import 'state/session_store.dart';

final navigatorKey = GlobalKey<NavigatorState>();
final messengerKey = GlobalKey<ScaffoldMessengerState>();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    systemNavigationBarColor: AppColors.surface,
    systemNavigationBarIconBrightness: Brightness.light,
  ));

  final Api api = AppConfig.isDemo ? DemoApi() : RemoteApi(AppConfig.apiBaseUrl);
  final push = PushService();
  await push.init();
  final background = BackgroundSignalService(api);
  await background.initialize();

  final session = SessionStore(api, push, background: background);
  final signals = SignalsStore(api);
  final stats = StatsStore(api);
  final market = MarketStore(api);
  final billing = BillingStore(api, session);
  var billingStarted = false;

  void onUnauthorized() {
    if (session.status == SessionStatus.loggedIn) session.logout();
  }

  signals.onUnauthorized = onUnauthorized;
  stats.onUnauthorized = onUnauthorized;
  market.onUnauthorized = onUnauthorized;

  void openSignal(int id) => navigatorKey.currentState
      ?.push(MaterialPageRoute(builder: (_) => SignalDetailScreen(signalId: id)));

  push.onOpenSignal = (id) {
    if (session.status == SessionStatus.loggedIn && session.user != null) openSignal(id);
  };
  background.onOpenSignal = (id) {
    if (session.status == SessionStatus.loggedIn && session.user != null) {
      openSignal(id);
    } else {
      background.queueSignal(id);
    }
  };
  push.onForeground = (title, body, id) {
    if (session.status != SessionStatus.loggedIn || session.user == null) return;
    messengerKey.currentState?.showSnackBar(SnackBar(
      duration: const Duration(seconds: 6),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
          if (body.isNotEmpty)
            Text(body, style: const TextStyle(fontSize: 12.5, color: AppColors.text2)),
        ],
      ),
      action: id == null
          ? null
          : SnackBarAction(label: 'VIEW', textColor: AppColors.gold, onPressed: () => openSignal(id)),
    ));
    signals.refresh(silent: true);
  };

  session.addListener(() {
    // Start Google Play billing once logged in, so purchases can be verified for this account.
    if (session.status == SessionStatus.loggedIn && !billingStarted) {
      billingStarted = true;
      billing.init();
    }
    if (session.status != SessionStatus.loggedIn) {
      signals.clear();
      stats.clear();
    } else {
      final pushPending = push.takePendingSignal();
      final localPending = background.takePendingSignal();
      final pending = pushPending ?? localPending;
      if (pending != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) => openSignal(pending));
      }
    }
  });

  session.bootstrap();
  ReviewService.recordOpen();
  Developer.recordUseDay();
  Developer.loadPromo(api);

  runApp(MultiProvider(
    providers: [
      Provider<Api>.value(value: api),
      Provider<PushService>.value(value: push),
      ChangeNotifierProvider.value(value: session),
      ChangeNotifierProvider.value(value: signals),
      ChangeNotifierProvider.value(value: stats),
      ChangeNotifierProvider.value(value: market),
      ChangeNotifierProvider.value(value: billing),
    ],
    child: const FxSignalsApp(),
  ));
}

class FxSignalsApp extends StatelessWidget {
  const FxSignalsApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      navigatorKey: navigatorKey,
      scaffoldMessengerKey: messengerKey,
      home: const _Root(),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final status = context.select<SessionStore, SessionStatus>((s) => s.status);
    final Widget child = switch (status) {
      SessionStatus.loading => const SplashScreen(),
      SessionStatus.onboarding => const OnboardingScreen(),
      SessionStatus.loggedOut => const LoginScreen(),
      SessionStatus.loggedIn => const HomeShell(),
    };
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 350),
      child: KeyedSubtree(key: ValueKey(status), child: child),
    );
  }
}
