import '../models/models.dart';
import '../models/money.dart';

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

  /// Dollars and pips at an account size and risk. Only available against the live server.
  Future<ResultsData> results({required double account, required double risk, String? strategy, String? pairs}) async =>
      throw const ApiException('Money results need the live server.');

  Future<StrategiesData> strategies() async =>
      throw const ApiException('Strategies need the live server.');

  /// Saves strategy (null = always the best one), account size and risk percent.
  Future<AppUser> updatePreferences({String? strategy, bool setStrategy = false, double? accountSize, double? riskPct}) async =>
      throw const ApiException('Saving preferences needs the live server.');

  /// The user's personal key for the MetaTrader bot. [rotate] replaces it with a new one.
  Future<BotKey> botKey({bool rotate = false}) async =>
      throw const ApiException('The auto-trade bot needs the live server.');

  /// Which developer links to show (server-controlled). Keys: coffee, whatsapp, hire.
  Future<Map<String, bool>> promo();
}
