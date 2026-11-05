import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../services/developer.dart';
import '../state/data_stores.dart';
import '../widgets/developer_widgets.dart';
import 'bot_screen.dart';
import 'markets_screen.dart';
import 'profile_screens.dart';
import 'results_screen.dart';
import 'signals_screen.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with WidgetsBindingObserver {
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final signals = context.read<SignalsStore>();
      signals.refresh();
      signals.startAutoRefresh();
      context.read<StatsStore>().load(forDays: 30);
      context.read<MarketStore>().load();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final signals = context.read<SignalsStore>();
    if (state == AppLifecycleState.resumed) {
      signals.refresh(silent: true);
      signals.startAutoRefresh();
      context.read<MarketStore>().load();
    } else if (state == AppLifecycleState.paused) {
      signals.stopAutoRefresh();
    }
  }

  void goToTab(int i) => setState(() => _tab = i);

  Future<void> _onBack() async {
    if (_tab != 0) {
      goToTab(0);
      return;
    }
    if (await Developer.shouldShowExitPrompt()) {
      if (mounted) await showBeforeYouGo(context);
    } else {
      SystemNavigator.pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _onBack();
      },
      child: Scaffold(
      floatingActionButton: _tab <= 2 ? const WhatsAppFab() : null,
      body: IndexedStack(
        index: _tab,
        children: [
          SignalsScreen(onSeeResults: () => goToTab(1)),
          const ResultsScreen(),
          const MarketsScreen(),
          const BotScreen(),
          const ProfileScreen(),
        ],
      ),
      bottomNavigationBar: DecoratedBox(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: Color(0xFF1F2A3F))),
        ),
        child: NavigationBar(
          selectedIndex: _tab,
          onDestinationSelected: goToTab,
          destinations: const [
            NavigationDestination(
                icon: Icon(Icons.bolt_outlined),
                selectedIcon: Icon(Icons.bolt_rounded),
                label: 'Signals'),
            NavigationDestination(
                icon: Icon(Icons.insights_outlined),
                selectedIcon: Icon(Icons.insights_rounded),
                label: 'Results'),
            NavigationDestination(
                icon: Icon(Icons.show_chart_outlined),
                selectedIcon: Icon(Icons.show_chart_rounded),
                label: 'Markets'),
            NavigationDestination(
                icon: Icon(Icons.smart_toy_outlined),
                selectedIcon: Icon(Icons.smart_toy_rounded),
                label: 'Bot'),
            NavigationDestination(
                icon: Icon(Icons.person_outline_rounded),
                selectedIcon: Icon(Icons.person_rounded),
                label: 'Profile'),
          ],
        ),
      ),
    ),
    );
  }
}
