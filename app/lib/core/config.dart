/// App configuration.
///
/// Set [apiBaseUrl] to your cPanel API, e.g. 'https://yourdomain.com/api'.
/// Or pass it at build time:
///   flutter build apk --dart-define=API_URL=https://yourdomain.com/api
/// Leave it empty to run in demo mode (simulated prices, no server).
class AppConfig {
  static const String apiBaseUrl =
      String.fromEnvironment('API_URL', defaultValue: '');

  static const String appName = 'Forex Signals Live';
  static const String supportEmail = 'swalafblog@gmail.com';
  static const String version = '1.2.1';

  /// Must match the build number in pubspec.yaml (version: 1.2.1+7). The server
  /// asks app builds older than its 'min_app_build' to update.
  static const int build = 7;

  // Google Play subscription products (Play Console → Monetize → Subscriptions).
  static const String monthlyProduct = 'fx_premium_monthly';
  static const String yearlyProduct = 'fx_premium_yearly';
  static const String playPackage = 'com.forexsignalslive.forex_signals';

  static bool get isDemo => apiBaseUrl.trim().isEmpty;

  static const List<String> pairs = [
    'EUR/USD',
    'GBP/USD',
    'USD/JPY',
    'AUD/USD',
    'USD/CAD',
    'USD/CHF',
    'NZD/USD',
    'XAU/USD',
    'BTC/USD',
    'ETH/USD',
  ];
}
