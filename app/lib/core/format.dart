class Fmt {
  static int decimals(String pair) {
    if (pair.startsWith('XAU') || isCrypto(pair)) return 2;
    if (pair.contains('JPY')) return 3;
    return 5;
  }

  static double pipSize(String pair) {
    if (pair.startsWith('XAU') || pair.startsWith('ETH')) return 0.1;
    if (pair.startsWith('BTC')) return 1.0;
    if (pair.contains('JPY')) return 0.01;
    return 0.0001;
  }

  static bool isCrypto(String pair) => pair.startsWith('BTC') || pair.startsWith('ETH');

  static String price(String pair, double? v) =>
      v == null ? '—' : v.toStringAsFixed(decimals(pair));

  static String pips(double? v, {bool sign = true}) {
    if (v == null) return '—';
    final s = v.abs() >= 100 ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
    return sign && v > 0 ? '+$s' : s;
  }

  static String pct(double v, {int digits = 1}) =>
      '${v >= 0 ? '+' : ''}${v.toStringAsFixed(digits)}%';

  static String money(double v) {
    final neg = v < 0;
    final parts = v.abs().toStringAsFixed(2).split('.');
    final whole = parts[0].replaceAllMapped(
        RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');
    return '${neg ? '-' : ''}\$$whole.${parts[1]}';
  }

  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
  ];

  static String two(int v) => v.toString().padLeft(2, '0');

  /// "Sep 24, 14:05" in the phone's local time.
  static String dateTime(DateTime? t) {
    if (t == null) return '—';
    final l = t.toLocal();
    return '${_months[l.month - 1]} ${l.day}, ${two(l.hour)}:${two(l.minute)}';
  }

  static String date(DateTime? t) {
    if (t == null) return '—';
    final l = t.toLocal();
    return '${_months[l.month - 1]} ${l.day}, ${l.year}';
  }

  static String monthLabel(String yyyyMm) {
    final parts = yyyyMm.split('-');
    if (parts.length != 2) return yyyyMm;
    final m = int.tryParse(parts[1]) ?? 1;
    return _months[(m - 1).clamp(0, 11)];
  }

  static String ago(DateTime? t) {
    if (t == null) return '—';
    final d = DateTime.now().difference(t);
    if (d.inSeconds < 60) return 'just now';
    if (d.inMinutes < 60) return '${d.inMinutes}m ago';
    if (d.inHours < 24) return '${d.inHours}h ago';
    if (d.inDays < 7) return '${d.inDays}d ago';
    return date(t);
  }

  static String duration(Duration d) {
    if (d.inMinutes < 60) return '${d.inMinutes}m';
    if (d.inHours < 24) return '${d.inHours}h ${d.inMinutes % 60}m';
    return '${d.inDays}d ${d.inHours % 24}h';
  }

  static String greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  static String initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    return parts.take(2).map((p) => p[0].toUpperCase()).join();
  }
}
