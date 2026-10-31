import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:in_app_review/in_app_review.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/config.dart';
import '../data/api.dart';

/// Developer contact links (same as the Ruqya Shifa app) and the
/// "Before you go" prompt rules.
class Developer {
  static const name = 'Abdullahi Abdulfattah';
  static const company = 'Swiftmynd Technologies Ltd';
  static const email = 'swalafblog@gmail.com';
  static const whatsappNumber = '2347011401387';
  static const linkedIn = 'https://www.linkedin.com/in/abdullahi-abdulfattah-72a99874/';
  static const buyMeACoffee = 'https://buymeacoffee.com/swalafblogM';
  static const pitch =
      'I design and build mobile apps, websites and custom software. This app is an example '
      'of that work. If you have a project in mind, or feedback on this app, I\'d like to hear from you.';

  static String whatsappUrl([String? message]) {
    final text = message ??
        'Hi Abdullahi, I found your ${AppConfig.appName} app and wanted to reach out.';
    return 'https://wa.me/$whatsappNumber?text=${Uri.encodeComponent(text)}';
  }

  static const hireMessage =
      'Hi Abdullahi, I use ${AppConfig.appName} and I\'d like to talk about building an app or website.';

  /// Server-controlled switches (config.php → promo). Default: all on.
  static final ValueNotifier<Map<String, bool>> promo =
      ValueNotifier(const {'coffee': true, 'whatsapp': true, 'hire': true});

  static bool get showCoffee => promo.value['coffee'] ?? true;
  static bool get showWhatsApp => promo.value['whatsapp'] ?? true;
  static bool get showHire => promo.value['hire'] ?? true;

  static Future<void> loadPromo(Api api) async {
    try {
      final p = await api.promo();
      promo.value = {...promo.value, ...p};
    } catch (_) {}
  }

  /// Opens a link outside the app. Returns false if nothing could open it.
  static Future<bool> open(String url) async {
    try {
      return await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  static Future<bool> openEmail() async {
    final ok = await open('mailto:$email?subject=${Uri.encodeComponent(AppConfig.appName)}');
    if (!ok) await Clipboard.setData(const ClipboardData(text: email));
    return ok;
  }

  static Future<void> rateApp() async {
    try {
      await InAppReview.instance.openStoreListing();
    } catch (_) {}
  }

  // ---------- "Before you go" prompt ----------
  static const _kDays = 'exit_prompt_days';
  static const _kShown = 'exit_prompt_shown';
  static const _kLast = 'exit_prompt_last';
  static const _kNever = 'exit_prompt_never';

  /// Call once per app start: counts distinct days the app was used.
  static Future<void> recordUseDay() async {
    try {
      final p = await SharedPreferences.getInstance();
      final today = DateTime.now().toIso8601String().substring(0, 10);
      final days = p.getStringList(_kDays) ?? [];
      if (!days.contains(today)) {
        days.add(today);
        await p.setStringList(_kDays, days.length > 30 ? days.sublist(days.length - 30) : days);
      }
    } catch (_) {}
  }

  /// Shown only after real use (3+ days), at most 3 times, 3+ days apart,
  /// and never again after "Don't ask again".
  static Future<bool> shouldShowExitPrompt() async {
    try {
      final p = await SharedPreferences.getInstance();
      if (p.getBool(_kNever) ?? false) return false;
      if ((p.getStringList(_kDays) ?? []).length < 3) return false;
      if ((p.getInt(_kShown) ?? 0) >= 3) return false;
      final last = p.getInt(_kLast) ?? 0;
      return DateTime.now().millisecondsSinceEpoch - last > 3 * 86400000;
    } catch (_) {
      return false;
    }
  }

  static Future<void> markExitPromptShown() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setInt(_kShown, (p.getInt(_kShown) ?? 0) + 1);
      await p.setInt(_kLast, DateTime.now().millisecondsSinceEpoch);
    } catch (_) {}
  }

  static Future<void> neverAskAgain() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.setBool(_kNever, true);
    } catch (_) {}
  }
}
