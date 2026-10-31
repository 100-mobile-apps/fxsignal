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
  static const String version = '1.0.0';

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
  ];
}
