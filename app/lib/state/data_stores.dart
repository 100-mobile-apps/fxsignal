import 'dart:async';

import 'package:flutter/foundation.dart';

import '../data/api.dart';
import '../models/models.dart';
import '../models/money.dart';

typedef UnauthorizedHandler = void Function();

mixin _Guard on ChangeNotifier {
  UnauthorizedHandler? onUnauthorized;

  String errorText(Object e) {
    if (e is ApiException) {
      if (e.isUnauthorized) onUnauthorized?.call();
      return e.message;
    }
    return 'Something went wrong. Please try again.';
  }
}

class SignalsStore extends ChangeNotifier with _Guard {
  final Api api;
  SignalsStore(this.api);

  List<TradeSignal> running = [];
  List<TradeSignal> history = [];
  bool loadingRunning = false;
  bool loadingHistory = false;
  bool hasMoreHistory = true;
  int _historyPage = 0;
  String? error;
  DateTime? lastUpdated;
  bool loadedOnce = false;
  Timer? _timer;

  void startAutoRefresh() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(minutes: 2), (_) => refresh(silent: true));
  }

  void stopAutoRefresh() => _timer?.cancel();

  Future<void> refresh({bool silent = false}) async {
    if (loadingRunning) return;
    loadingRunning = true;
    if (!silent) error = null;
    notifyListeners();
    try {
      final r = await api.signals(running: true);
      running = r.items;
      lastUpdated = DateTime.now();
      error = null;
      if (!silent || history.isEmpty) {
        _historyPage = 0;
        hasMoreHistory = true;
        history = [];
        loadingRunning = false;
        await loadMoreHistory();
      }
    } catch (e) {
      if (!silent) error = errorText(e);
    } finally {
      loadingRunning = false;
      loadedOnce = true;
      notifyListeners();
    }
  }

  Future<void> loadMoreHistory() async {
    if (loadingHistory || !hasMoreHistory) return;
    loadingHistory = true;
    notifyListeners();
    try {
      final page = await api.signals(running: false, page: _historyPage + 1);
      _historyPage++;
      history = [...history, ...page.items];
      hasMoreHistory = page.hasMore;
    } catch (e) {
      error = errorText(e);
    } finally {
      loadingHistory = false;
      notifyListeners();
    }
  }

  void clear() {
    running = [];
    history = [];
    loadedOnce = false;
    _historyPage = 0;
    hasMoreHistory = true;
    stopAutoRefresh();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}

class StatsStore extends ChangeNotifier with _Guard {
  final Api api;
  StatsStore(this.api);

  final Map<int, Stats> _cache = {};
  int days = 30;
  bool loading = false;
  String? error;

  Stats? get current => _cache[days];
  Stats? get month => _cache[30];

  Future<void> select(int d) async {
    days = d;
    notifyListeners();
    if (!_cache.containsKey(d)) await load();
  }

  Future<void> load({int? forDays}) async {
    final d = forDays ?? days;
    loading = true;
    error = null;
    notifyListeners();
    try {
      _cache[d] = await api.stats(d);
    } catch (e) {
      error = errorText(e);
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> refreshAll() async {
    final keys = {30, days};
    _cache.clear();
    for (final k in keys) {
      await load(forDays: k);
    }
  }

  void clear() => _cache.clear();
}

class MarketStore extends ChangeNotifier with _Guard {
  final Api api;
  MarketStore(this.api);

  List<PriceQuote> prices = [];
  bool loading = false;
  String? error;

  PriceQuote? quote(String symbol) {
    for (final p in prices) {
      if (p.symbol == symbol) return p;
    }
    return null;
  }

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      prices = await api.prices();
    } catch (e) {
      error = errorText(e);
    } finally {
      loading = false;
      notifyListeners();
    }
  }
}

/// Dollars and pips at the user's account size and risk, plus the strategy list.
class MoneyStore extends ChangeNotifier with _Guard {
  final Api api;
  MoneyStore(this.api);

  ResultsData? data;
  StrategiesData? strategies;
  bool loading = false;
  String? error;
  double account = 10000;
  double risk = 1;
  String? pairsFilter; // null = every market, 'best' = best markets now
  String? strategyFilter; // null = every strategy
  int _request = 0;

  void useDefaults(double accountSize, double riskPct) {
    account = accountSize;
    risk = riskPct;
  }

  Future<void> load() async {
    final id = ++_request;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final r = await api.results(account: account, risk: risk, strategy: strategyFilter, pairs: pairsFilter);
      if (id == _request) data = r;
      final s = await api.strategies();
      if (id == _request) strategies = s;
    } catch (e) {
      if (id == _request) error = errorText(e);
    } finally {
      if (id == _request) {
        loading = false;
        notifyListeners();
      }
    }
  }

  Future<void> setAccount(double v) async {
    account = v;
    await load();
  }

  Future<void> setRisk(double v) async {
    risk = v;
    await load();
  }

  Future<void> setPairs(String? v) async {
    pairsFilter = v;
    await load();
  }

  Future<void> setStrategyFilter(String? v) async {
    strategyFilter = v;
    await load();
  }

  void clear() {
    data = null;
    strategies = null;
    error = null;
  }
}
