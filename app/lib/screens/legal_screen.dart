import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/theme.dart';

enum LegalDoc { risk, terms, privacy, methodology }

class LegalScreen extends StatelessWidget {
  final LegalDoc doc;
  const LegalScreen({super.key, required this.doc});

  static String titleOf(LegalDoc d) => switch (d) {
        LegalDoc.risk => 'Risk Disclaimer',
        LegalDoc.terms => 'Terms of Use',
        LegalDoc.privacy => 'Privacy Policy',
        LegalDoc.methodology => 'How signals work',
      };

  List<(String, String)> get _sections => switch (doc) {
        LegalDoc.risk => const [
            ('Trading involves significant risk',
                'Trading foreign exchange and gold on margin carries a high level of risk and may not be suitable for all investors. Leverage can work against you as well as for you. You could lose some or all of your deposit.'),
            ('Not financial advice',
                'Signals in this app are generated automatically by technical-analysis rules. They are general information for educational purposes only and do not take your objectives, finances or needs into account. They are not investment advice or a recommendation to buy or sell.'),
            ('Past results do not guarantee future results',
                'The track record shows how previous signals performed under the app\'s rules. Real trades may differ because of spreads, slippage, commissions, execution delays and position sizing.'),
            ('Your responsibility',
                'You alone are responsible for your trading decisions. Only trade with money you can afford to lose, always use a stop loss, and consider practising on a demo account first. If in doubt, seek advice from a licensed financial adviser.'),
          ],
        LegalDoc.terms => const [
            ('Using the app',
                'You must be 18 or older to use ${AppConfig.appName}. You agree to provide accurate account information and keep your password secure.'),
            ('Service',
                'The app is provided free of charge "as is". We aim for accuracy and availability but do not guarantee that signals, prices or notifications are uninterrupted, timely or error-free.'),
            ('No liability for trading losses',
                'To the maximum extent permitted by law, we are not liable for any loss or damage arising from the use of signals or information in the app.'),
            ('Acceptable use',
                'Do not misuse the service, attempt to access it by automated means, or resell signals without permission.'),
            ('Changes and termination',
                'We may update these terms or the service. You can delete your account at any time from Profile → Delete account.'),
          ],
        LegalDoc.privacy => const [
            ('What we collect',
                'Your name, email address and a securely hashed password. Your notification and watchlist preferences. We do not collect payment details or trading account credentials.'),
            ('How we use it',
                'To run your account, send password-reset emails and deliver the notifications you choose. We do not sell your personal data.'),
            ('Notifications',
                'Push notifications are delivered through Firebase Cloud Messaging by topic. You can change them in Profile → Notifications or your phone settings.'),
            ('Deleting your data',
                'Profile → Delete account permanently removes your account and personal data from our servers.'),
            ('Contact',
                'Questions about privacy: ${AppConfig.supportEmail}'),
          ],
        LegalDoc.methodology => const [
            ('Markets and timeframe',
                'The engine scans 8 instruments (EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, USD/CHF, NZD/USD and XAU/USD) on the 1-hour chart, using only fully closed candles so signals never repaint.'),
            ('Scoring',
                'Five checks add up to a strength score out of 100: price vs the 200 EMA (20), 20 EMA vs 50 EMA (25), MACD histogram and momentum (25), RSI momentum (15) and a fresh EMA crossover (15). Overbought/oversold RSI reduces the score. Only setups with 65%+ strength are published.'),
            ('Levels',
                'Stop loss is 1.5 × ATR(14) from entry. TP1 is 1.5 × ATR (1:1 risk-reward) and TP2 is 3 × ATR (1:2).'),
            ('How results are recorded',
                'Every published signal is tracked automatically on the server. If the stop loss and a target are touched in the same candle, the stop loss is counted first. After TP1 the stop moves to entry; the trade then closes at TP2 (full win) or back at entry (TP1 win). Open signals close at market after 72 hours. Signals are never edited or deleted after publishing.'),
            ('Pips',
                'Results are shown in pips before spread and commission. 1 pip = 0.0001 (0.01 for JPY pairs, 0.10 for gold).'),
          ],
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(titleOf(doc))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          for (final s in _sections) ...[
            Text(s.$1, style: AppText.title),
            const SizedBox(height: 8),
            Text(s.$2, style: AppText.body.copyWith(fontSize: 14.5, height: 1.6)),
            const SizedBox(height: 22),
          ],
          if (doc != LegalDoc.methodology)
            const Text('Last updated: 2026', style: AppText.label),
        ],
      ),
    );
  }
}
