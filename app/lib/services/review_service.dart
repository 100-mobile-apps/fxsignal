import 'package:in_app_review/in_app_review.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Asks for a Play Store rating at a good moment, using Google's official
/// in-app review prompt (Google decides whether it actually shows).
///
/// Rules: at least 5 app opens, 3 days after install, right after the user
/// views a winning signal, and never more than once every 90 days.
class ReviewService {
  static const _kOpens = 'review_opens';
  static const _kFirst = 'review_first_open';
  static const _kLast = 'review_last_ask';

  static Future<void> recordOpen() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setInt(_kOpens, (p.getInt(_kOpens) ?? 0) + 1);
      if (!p.containsKey(_kFirst)) {
        await p.setInt(_kFirst, DateTime.now().millisecondsSinceEpoch);
      }
    } catch (_) {}
  }

  /// Call after the user has seen good news (e.g. opened a TP2 result).
  static Future<void> maybeAsk() async {
    try {
      final p = await SharedPreferences.getInstance();
      final now = DateTime.now().millisecondsSinceEpoch;
      final opens = p.getInt(_kOpens) ?? 0;
      final first = p.getInt(_kFirst) ?? now;
      final last = p.getInt(_kLast) ?? 0;
      const day = 86400000;
      if (opens < 5 || now - first < 3 * day || now - last < 90 * day) return;
      final review = InAppReview.instance;
      if (await review.isAvailable()) {
        await p.setInt(_kLast, now);
        await review.requestReview();
      }
    } catch (_) {}
  }
}
