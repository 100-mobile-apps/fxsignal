import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../data/api.dart';
import '../models/models.dart';
import '../services/background_signal_service.dart';
import '../services/push_service.dart';

enum SessionStatus { loading, onboarding, loggedOut, loggedIn }

class SessionStore extends ChangeNotifier {
  final Api api;
  final PushService push;
  final BackgroundSignalService? background;
  SessionStore(this.api, this.push, {this.background});

  static const _kToken = 'auth_token';
  static const _kUser = 'cached_user';
  static const _kOnboarding = 'onboarding_done';
  final _secure = const FlutterSecureStorage();

  SessionStatus status = SessionStatus.loading;
  AppUser? user;

  Future<void> _syncDelivery(AppUser account) async {
    final topicsReady = await push.sync(account.notifications, strategy: account.prefs.strategy);
    var permissionGranted = false;
    if (topicsReady) {
      try {
        permissionGranted = await push.permissionGranted();
      } catch (_) {}
    }
    await background?.sync(account, fcmReady: topicsReady && permissionGranted);
  }

  Future<void> bootstrap() async {
    final prefs = await SharedPreferences.getInstance();
    final onboarded = prefs.getBool(_kOnboarding) ?? false;
    String? token;
    try {
      token = await _secure.read(key: _kToken);
    } catch (_) {}

    if (token == null) {
      if (background != null) unawaited(background!.clear(null));
      status = onboarded ? SessionStatus.loggedOut : SessionStatus.onboarding;
      notifyListeners();
      return;
    }
    api.token = token;
    try {
      user = await api.me();
      await _cacheUser(user!);
      status = SessionStatus.loggedIn;
      unawaited(_syncDelivery(user!));
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await _clear();
        unawaited(push.clear());
        status = SessionStatus.loggedOut;
      } else {
        // Offline: continue with the cached profile.
        final raw = prefs.getString(_kUser);
        if (raw != null) {
          user = AppUser.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
          status = SessionStatus.loggedIn;
          unawaited(_syncDelivery(user!));
        } else {
          status = SessionStatus.loggedOut;
        }
      }
    }
    notifyListeners();
  }

  Future<void> completeOnboarding() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kOnboarding, true);
    status = SessionStatus.loggedOut;
    notifyListeners();
  }

  Future<void> _start(AuthResult r) async {
    await _secure.write(key: _kToken, value: r.token);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kOnboarding, true);
    user = r.user;
    await _cacheUser(r.user);
    status = SessionStatus.loggedIn;
    notifyListeners();
    try {
      await push.requestPermission();
    } catch (_) {}
    unawaited(_syncDelivery(r.user));
  }

  Future<void> login(String email, String password) async =>
      _start(await api.login(email.trim(), password));

  Future<void> register(String name, String email, String password) async =>
      _start(await api.register(name.trim(), email.trim(), password));

  Future<String> forgotPassword(String email) => api.forgotPassword(email.trim());

  Future<void> resetPassword(String email, String code, String password) async =>
      _start(await api.resetPassword(email.trim(), code.trim(), password));

  Future<void> verifyEmail(String code) async => setUser(await api.verifyEmail(code.trim()));

  Future<String> resendVerification() => api.resendVerification();

  Future<void> updateName(String name) async => setUser(await api.updateName(name));

  Future<void> changePassword(String current, String next) async {
    final token = await api.changePassword(current, next);
    await _secure.write(key: _kToken, value: token);
  }

  Future<void> updateNotifications(NotificationPrefs prefs) async {
    // Optimistic update so toggles feel instant.
    final previous = user;
    if (user != null) {
      setUser(user!.copyWith(notifications: prefs));
      await _cacheUser(user!);
    }
    try {
      setUser(await api.updateNotifications(prefs));
      await _syncDelivery(user!);
    } catch (e) {
      if (previous != null) {
        setUser(previous);
        await _cacheUser(previous);
      }
      rethrow;
    }
  }

  /// Saves strategy / account size / risk. Pass [setStrategy] true to change the strategy (null = always the best one).
  Future<void> updatePreferences(
      {String? strategy, bool setStrategy = false, double? accountSize, double? riskPct}) async {
    final u = user;
    if (u == null) return;
    final next = u.prefs.copyWith(
      strategy: setStrategy && strategy != null ? strategy : u.prefs.strategy,
      strategyChosen: setStrategy ? strategy != null : u.prefs.strategyChosen,
      accountSize: accountSize,
      riskPct: riskPct,
    );
    setUser(u.copyWith(prefs: next));
    try {
      setUser(await api.updatePreferences(
          strategy: strategy, setStrategy: setStrategy, accountSize: accountSize, riskPct: riskPct));
      if (setStrategy) await _syncDelivery(user!);
    } catch (_) {
      setUser(u);
      rethrow;
    }
  }

  Future<void> toggleWatch(String symbol) async {
    final u = user;
    if (u == null) return;
    final list = [...u.watchlist];
    if (list.contains(symbol)) {
      list.remove(symbol);
    } else {
      list.add(symbol);
    }
    setUser(u.copyWith(watchlist: list));
    try {
      setUser(await api.updateWatchlist(list));
    } catch (_) {
      setUser(u);
      rethrow;
    }
  }

  void setUser(AppUser u) {
    user = u;
    _cacheUser(u);
    notifyListeners();
  }

  Future<void> _cacheUser(AppUser u) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kUser, jsonEncode(u.toJson()));
  }

  Future<void> _clear() async {
    final userId = user?.id;
    api.token = null;
    user = null;
    try {
      await _secure.delete(key: _kToken);
    } catch (_) {}
    await background?.clear(userId);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kUser);
  }

  Future<void> logout() async {
    await _clear();
    status = SessionStatus.loggedOut;
    notifyListeners();
    await push.clear();
  }

  Future<void> deleteAccount(String password) async {
    await api.deleteAccount(password);
    await logout();
  }
}
