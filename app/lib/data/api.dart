import '../models/models.dart';

class ApiException implements Exception {
  final String message;
  final int status;
  const ApiException(this.message, [this.status = 0]);

  bool get isUnauthorized => status == 401;

  @override
  String toString() => message;
}

/// Everything the app needs from the backend. Implemented by [RemoteApi]
/// (your cPanel server) and [DemoApi] (offline simulation).
abstract class Api {
  String? token;

  Future<AuthResult> login(String email, String password);
  Future<AuthResult> register(String name, String email, String password);
  Future<String> forgotPassword(String email);
  Future<AuthResult> resetPassword(String email, String code, String password);

  Future<AppUser> verifyEmail(String code);
  Future<String> resendVerification();

  Future<AppUser> me();
  Future<AppUser> updateName(String name);
  Future<String> changePassword(String current, String next);
  Future<void> deleteAccount(String password);
  Future<AppUser> updateNotifications(NotificationPrefs prefs);
  Future<AppUser> updateWatchlist(List<String> symbols);

  Future<SignalPage> signals(
      {required bool running, int page = 1, DateTime? from, DateTime? to});
  Future<ReportsData> reports(String period);
  Future<TradeSignal> signal(int id);
  Future<Stats> stats(int days);
  Future<List<PriceQuote>> prices();

  /// Which developer links to show (server-controlled). Keys: coffee, whatsapp, hire.
  Future<Map<String, bool>> promo();

  /// Markets to show on the Signals screen (null = all).
  Future<AppUser> setSignalPairs(List<String>? pairs);

  /// Uses the free-user unlock on one Premium signal.
  Future<(TradeSignal, AppUser)> freeUnlock(int signalId);

  /// Trading bot switches from the server (/health → bot).
  Future<Map<String, dynamic>> botConfig();

  /// Sends a Google Play purchase to the server, which checks it with Google
  /// and returns the account with premium switched on.
  Future<AppUser> verifyPurchase(String productId, String purchaseToken);

  /// Registers this phone's push token so premium alerts with full levels reach it.
  Future<void> registerDevice(String pushToken);
  Future<void> unregisterDevice(String pushToken);
}
