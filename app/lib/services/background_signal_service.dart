import 'dart:convert';
import 'dart:ui';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:workmanager/workmanager.dart';

import '../core/config.dart';
import '../data/api.dart';
import '../data/remote_api.dart';
import '../models/models.dart';

const _uniqueTask = 'signal-poll-unique';
const _taskName = 'pollSignals';
String _eventsKey(int userId) => 'notified_signal_events_$userId';
String _stage(TradeSignal signal) => signal.isRunning
    ? (signal.tp1Hit ? 'tp1' : 'open') : 'closed';
String _eventKey(TradeSignal signal) => '${signal.id}:${_stage(signal)}';

typedef SignalAlert = ({TradeSignal signal, String stage});

List<SignalAlert> unseenSignalAlerts(
    List<TradeSignal> signals, Set<String> delivered, NotificationPrefs preferences,
    {String? strategy}) {
  final alerts = <SignalAlert>[];
  for (final signal in signals) {
    final stage = _stage(signal);
    if (!delivered.contains(_eventKey(signal)) &&
        (strategy == null || signal.strategy == strategy) &&
        preferences.wantsPair(signal.pair) &&
        (stage == 'open' ? preferences.signals : preferences.results)) {
      alerts.add((signal: signal, stage: stage));
    }
  }
  return alerts..sort((a, b) => a.signal.id.compareTo(b.signal.id));
}

@pragma('vm:entry-point')
void backgroundSignalDispatcher() {
  Workmanager().executeTask((task, _) async {
    if (task != _taskName || AppConfig.isDemo) return true;
    WidgetsFlutterBinding.ensureInitialized();
    DartPluginRegistrant.ensureInitialized();

    try {
      final token = await const FlutterSecureStorage().read(key: 'auth_token');
      if (token == null) return true;

      final api = RemoteApi(AppConfig.apiBaseUrl)..token = token;
      final user = await api.me();
      final running = await api.signals(running: true);
      final closed = await api.signals(running: false);
      final signalsById = <int, TradeSignal>{
        for (final signal in running.items) signal.id: signal,
        for (final signal in closed.items) signal.id: signal,
      };
      final signals = signalsById.values.toList();
      final prefs = await SharedPreferences.getInstance();
      await prefs.reload();
      final key = _eventsKey(user.id);
      final raw = prefs.getString(key);
      if (raw == null) {
        await prefs.setString(key, jsonEncode(signals.map(_eventKey).toList()));
        return true;
      }
      late Set<String> delivered;
      try {
        delivered = (jsonDecode(raw) as List).cast<String>().toSet();
      } catch (_) {
        await prefs.setString(key, jsonEncode(signals.map(_eventKey).toList()));
        return true;
      }

      final alerts = unseenSignalAlerts(signals, delivered, user.notifications,
          strategy: user.prefs.strategy);
      if (alerts.isNotEmpty) {
        final notifications = FlutterLocalNotificationsPlugin();
        await notifications.initialize(
          settings: const InitializationSettings(
            android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          ),
        );
        for (final alert in alerts) {
          final signal = alert.signal;
          if (await const FlutterSecureStorage().read(key: 'auth_token') != token) {
            return true;
          }
          await prefs.reload();
          final cached = prefs.getString('cached_user');
          if (cached != null) {
            try {
              final account = AppUser.fromJson(
                  (jsonDecode(cached) as Map).cast<String, dynamic>());
              if (account.id != user.id ||
                  !(alert.stage == 'open'
                      ? account.notifications.signals : account.notifications.results) ||
                  !account.notifications.wantsPair(signal.pair)) {
                continue;
              }
            } catch (_) {
              // The server-side preferences above remain the source of truth.
            }
          }
          await notifications.show(
            id: signal.id,
            title: switch (alert.stage) {
              'open' => 'New ${signal.pair} ${signal.direction} signal',
              'tp1' => '${signal.pair} TP1 reached',
              _ => '${signal.pair} ${signal.direction} signal closed',
            },
            body: switch (alert.stage) {
              'open' => 'Open the app for entry, stop-loss and target levels.',
              'tp1' => 'First target reached. Open the app for current levels.',
              _ => 'Open signal history for the result.',
            },
            notificationDetails: const NotificationDetails(
              android: AndroidNotificationDetails(
                'signal_fallback', 'Signal updates',
                importance: Importance.high,
                priority: Priority.high,
              ),
            ),
            payload: '${signal.id}',
          );
          delivered.add(_eventKey(signal));
          await prefs.setString(key, jsonEncode(delivered.toList()));
        }
      }
      if (await const FlutterSecureStorage().read(key: 'auth_token') != token) {
        return true;
      }
      final currentIds = signalsById.keys;
      delivered.removeWhere((event) {
        final separator = event.indexOf(':');
        final id = separator < 0 ? null : int.tryParse(event.substring(0, separator));
        return id == null || !currentIds.contains(id);
      });
      await prefs.setString(key, jsonEncode(delivered.toList()));
      return true;
    } on ApiException catch (error) {
      return error.isUnauthorized;
    } catch (error) {
      debugPrint('Background signal poll failed: $error');
      return false;
    }
  });
}

class BackgroundSignalService {
  final Api api;
  final _notifications = FlutterLocalNotificationsPlugin();
  bool _ready = false;
  int _generation = 0;
  Future<void> _operations = Future.value();
  int? _pendingSignal;
  void Function(int)? onOpenSignal;

  BackgroundSignalService(this.api);

  bool get _supported => !kIsWeb &&
      defaultTargetPlatform == TargetPlatform.android && !AppConfig.isDemo;

  Future<void> initialize() async {
    if (!_supported) return;
    try {
      await Workmanager().initialize(backgroundSignalDispatcher);
      await _notifications.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        ),
        onDidReceiveNotificationResponse: (response) {
          final id = int.tryParse(response.payload ?? '');
          if (id != null) {
            if (onOpenSignal != null) {
              onOpenSignal!(id);
            } else {
              _pendingSignal = id;
            }
          }
        },
      );
      final launch = await _notifications.getNotificationAppLaunchDetails();
      if (launch?.didNotificationLaunchApp == true) {
        _pendingSignal = int.tryParse(launch?.notificationResponse?.payload ?? '');
      }
      _ready = true;
    } catch (error) {
      debugPrint('Background signal polling unavailable: $error');
    }
  }

  int? takePendingSignal() {
    final id = _pendingSignal;
    _pendingSignal = null;
    return id;
  }

  void queueSignal(int id) => _pendingSignal = id;

  Future<void> sync(AppUser user, {required bool fcmReady}) {
    if (!_ready) return Future.value();
    final generation = ++_generation;
    return _operations = _operations.then((_) => _sync(user, fcmReady, generation));
  }

  Future<void> _sync(AppUser user, bool fcmReady, int generation) async {
    if (generation != _generation) return;
    try {
      final key = _eventsKey(user.id);
      var primaryPushReady = fcmReady;
      if (primaryPushReady && api is RemoteApi) {
        try {
          primaryPushReady = await (api as RemoteApi).pushEnabled();
        } catch (_) {
          // An unreachable server cannot be polled either; avoid FCM duplicates.
        }
      }
      if (generation != _generation) return;
      if (primaryPushReady || (!user.notifications.signals && !user.notifications.results)) {
        await Workmanager().cancelByUniqueName(_uniqueTask);
        await _notifications.cancelAll();
        _pendingSignal = null;
        final prefs = await SharedPreferences.getInstance();
        await prefs.remove(key);
        return;
      }

      final allowed = await _notifications
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
      if (allowed == false) {
        await Workmanager().cancelByUniqueName(_uniqueTask);
        await _notifications.cancelAll();
        _pendingSignal = null;
        final prefs = await SharedPreferences.getInstance();
        await prefs.remove(key);
        return;
      }
      if (generation != _generation) return;

      final prefs = await SharedPreferences.getInstance();
      if (prefs.getString(key) == null) {
        try {
          final running = await api.signals(running: true);
          final closed = await api.signals(running: false);
          await prefs.setString(key, jsonEncode(
              [...running.items, ...closed.items].map(_eventKey).toSet().toList()));
        } catch (_) {
          // The first successful worker run establishes the baseline instead.
        }
      }
      if (generation != _generation) return;
      await Workmanager().registerPeriodicTask(
        _uniqueTask, _taskName,
        frequency: const Duration(minutes: 20),
        existingWorkPolicy: ExistingPeriodicWorkPolicy.update,
        constraints: Constraints(networkType: NetworkType.connected),
      );
    } catch (error) {
      debugPrint('Could not schedule background signal polling: $error');
    }
  }

  Future<void> clear(int? userId) {
    _generation++;
    _pendingSignal = null;
    if (!_ready) return Future.value();
    return _operations = _operations.then((_) => _clear(userId));
  }

  Future<void> _clear(int? userId) async {
    try {
      await Workmanager().cancelByUniqueName(_uniqueTask);
      await _notifications.cancelAll();
      final prefs = await SharedPreferences.getInstance();
      if (userId != null) {
        await prefs.remove(_eventsKey(userId));
      } else {
        for (final key in prefs.getKeys().where((key) => key.startsWith('notified_signal_events_'))) {
          await prefs.remove(key);
        }
      }
    } catch (error) {
      debugPrint('Could not clear background signal polling: $error');
    }
  }
}
