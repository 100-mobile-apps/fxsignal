import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../core/config.dart';
import '../models/models.dart';

/// Push notifications through Firebase Cloud Messaging topics.
/// The server sends to "signals_EURUSD", "results_EURUSD", … and each phone
/// subscribes to the topics that match the user's notification settings.
///
/// If Firebase isn't set up yet (no google-services.json), push is simply
/// disabled and the rest of the app works normally.
class PushService {
  bool available = false;
  Future<void> _operations = Future.value();
  int _generation = 0;

  /// Called when the user taps a notification (or an in-app banner).
  void Function(int signalId)? onOpenSignal;

  /// Called for notifications that arrive while the app is open.
  void Function(String title, String body, int? signalId)? onForeground;

  int? _pendingSignal;

  static String _key(String pair) => pair.replaceAll('/', '');

  Future<void> init() async {
    if (kIsWeb) return;
    try {
      await Firebase.initializeApp();
      available = true;
    } catch (e) {
      debugPrint('Push disabled: Firebase not configured ($e)');
      return;
    }
    if (defaultTargetPlatform == TargetPlatform.android) {
      try {
        await FlutterLocalNotificationsPlugin()
            .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
            ?.createNotificationChannel(const AndroidNotificationChannel(
              'signals', 'Signal alerts', importance: Importance.high,
            ));
      } catch (e) {
        debugPrint('Could not create FCM signal channel: $e');
      }
    }
    FirebaseMessaging.onMessage.listen((m) {
      onForeground?.call(
        m.notification?.title ?? 'FX Signals',
        m.notification?.body ?? '',
        int.tryParse('${m.data['signal_id']}'),
      );
    });
    FirebaseMessaging.onMessageOpenedApp.listen(_open);
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) _pendingSignal = int.tryParse('${initial.data['signal_id']}');
  }

  void _open(RemoteMessage m) {
    final id = int.tryParse('${m.data['signal_id']}');
    if (id != null) onOpenSignal?.call(id);
  }

  /// Signal the app was launched from (consumed once, after login).
  int? takePendingSignal() {
    final id = _pendingSignal;
    _pendingSignal = null;
    return id;
  }

  Future<bool> requestPermission() async {
    if (!available) return false;
    final s = await FirebaseMessaging.instance.requestPermission();
    return s.authorizationStatus == AuthorizationStatus.authorized ||
        s.authorizationStatus == AuthorizationStatus.provisional;
  }

  Future<bool> permissionGranted() async {
    if (!available) return false;
    final s = await FirebaseMessaging.instance.getNotificationSettings();
    return s.authorizationStatus == AuthorizationStatus.authorized ||
        s.authorizationStatus == AuthorizationStatus.provisional;
  }

  Future<bool> sync(NotificationPrefs prefs) {
    if (!available) return Future.value(false);
    final generation = ++_generation;
    final task = _operations.then((_) =>
        generation == _generation ? _syncTopics(prefs) : Future.value(false));
    _operations = task.then<void>((_) {});
    return task;
  }

  Future<bool> _syncTopics(NotificationPrefs prefs) async {
    final fm = FirebaseMessaging.instance;
    try {
      await (prefs.reports ? fm.subscribeToTopic('reports') : fm.unsubscribeFromTopic('reports'));
      for (final pair in AppConfig.pairs) {
        final k = _key(pair);
        final want = prefs.wantsPair(pair);
        await (prefs.signals && want
            ? fm.subscribeToTopic('signals_$k')
            : fm.unsubscribeFromTopic('signals_$k'));
        await (prefs.results && want
            ? fm.subscribeToTopic('results_$k')
            : fm.unsubscribeFromTopic('results_$k'));
      }
      return true;
    } catch (e) {
      debugPrint('Topic sync failed: $e');
      return false;
    }
  }

  Future<void> clear() {
    if (!available) return Future.value();
    _generation++;
    return _operations = _operations.then((_) => _clearTopics());
  }

  Future<void> _clearTopics() async {
    final fm = FirebaseMessaging.instance;
    try {
      await fm.unsubscribeFromTopic('reports');
      for (final pair in AppConfig.pairs) {
        await fm.unsubscribeFromTopic('signals_${_key(pair)}');
        await fm.unsubscribeFromTopic('results_${_key(pair)}');
      }
    } catch (e) {
      debugPrint('Could not unsubscribe every FCM topic: $e');
    }
    try {
      await fm.deleteToken();
    } catch (e) {
      debugPrint('Could not delete FCM token: $e');
    }
  }
}
