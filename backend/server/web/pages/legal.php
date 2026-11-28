<?php
declare(strict_types=1);

/** Legal pages for the website. Keep these in step with the app's legal screen and PRIVACY_POLICY.txt. */
function legal_doc(string $slug): array
{
    $co = 'Swiftmynd Technologies Ltd';
    $mail = 'swalafblog@gmail.com';
    $aff = Affiliates::settings();
    return match ($slug) {
        'terms' => ['Terms of use', [
            ['The service', BRAND . " (the website and the Android app) is operated by $co. It publishes automated technical-analysis trade ideas (\"signals\"), their tracked results, market information and calculators. By creating an account you agree to these terms."],
            ['Not financial advice', 'Signals are for information and education only. They are not investment, financial or trading advice and are not a recommendation to buy or sell anything. You decide whether to trade and you are responsible for your own trades, gains and losses.'],
            ['Accounts', 'You must be at least 18 and give a real email address. Keep your password safe. One account per person. We may suspend accounts that abuse the service, share Premium content publicly, or break these terms.'],
            ['Premium', 'Premium unlocks extra signals and features. On the website it is paid through Paystack and renews automatically until you turn off auto-renew in Account; Premium then stays on until the end of the period you paid for. In the Android app it is paid and managed through Google Play. Prices are shown before you pay. If a payment went wrong, contact us within 7 days.'],
            ['Track record', 'Every signal is recorded and closed by fixed rules and is never edited or deleted. Results are in pips before spread, commission and slippage, so your own results will differ. Past results do not guarantee future results.'],
            ['Availability', 'We aim to keep the service running but do not guarantee it will always be available, timely or error-free. Prices come from third-party data providers and may be delayed or wrong.'],
            ['Liability', "To the extent the law allows, $co is not liable for trading losses or indirect losses arising from use of the service."],
            ['Changes and contact', "We may update these terms; the date above shows the latest version. Questions: $mail."],
        ]],
        'privacy' => ['Privacy policy', [
            ['Who we are', "$co operates " . BRAND . ". Contact: $mail."],
            ['What we collect', 'Your name, email, a hash of your password, your settings (markets, alerts, watchlist), sign-in times and, for security, your IP address in temporary rate-limit records. If you pay on the website, Paystack processes your payment; we receive the payment reference, amount, plan and a Paystack customer code, never your card details. If you pay in the app, Google Play processes it and we receive the order and subscription status.'],
            ['Affiliate programme', 'If you join, we store your code, payout method and payout details, and statistics on visits to your link (stored only as a short one-way hash of the visitor IP), sign-ups and commissions. If you signed up through someone\'s link, that affiliate sees a masked version of your name and the commission from your payments, never your email.'],
            ['Cookies', 'The website uses a session cookie to keep you logged in and, if you arrived through an affiliate link, a referral cookie for ' . (int) $aff['cookie_days'] . ' days. No advertising cookies.'],
            ['How we use it', 'To run your account, send the alerts and reports you choose, process Premium and affiliate commissions, keep the service secure, and (if you allow it) send occasional product emails. Every marketing email has an unsubscribe link.'],
            ['Sharing', 'We share data only with the services that run the product: our hosting provider, our email provider, Paystack, Google (Play billing and Firebase push), and the market data provider (no personal data). We do not sell personal data.'],
            ['Your rights', 'You can see and change your data in Account, and delete your account at any time from Account (website) or Settings (app). Deleting removes your personal data; payment records are kept anonymised where tax law requires.'],
            ['Retention and security', 'We keep data while your account exists. Passwords are hashed, connections use HTTPS, and admin access is limited.'],
        ]],
        'risk' => ['Risk disclaimer', [
            ['High risk', 'Trading foreign exchange, gold, cryptocurrencies and other leveraged products carries a high level of risk and may not be suitable for all investors. Leverage can work against you as well as for you. You can lose some or all of your deposit.'],
            ['Automated signals', 'Signals are produced by an automated rules-based engine using technical indicators. They can and do lose. No strategy wins all the time, and past results do not guarantee future results.'],
            ['Your responsibility', 'Only trade with money you can afford to lose. Consider your experience, objectives and finances, practise on a demo account first, and seek independent advice if you are unsure.'],
            ['Brokers', 'Broker links on this website may be partner or affiliate links. We or an affiliate may be paid if you open an account. We do not hold client funds or execute trades. Check that any broker you use is regulated where you live.'],
        ]],
        'affiliate-terms' => ['Affiliate terms', [
            ['Commission', 'Affiliates earn ' . (int) $aff['rate'] . '% of each Premium payment (website and Google Play, after Google\'s fee) made by people who signed up through their link or code, for as long as those people stay subscribed. Rates can differ per affiliate and may change with notice.'],
            ['Tracking', 'A visit through your link is remembered for ' . (int) $aff['cookie_days'] . ' days. People can also type your code when they sign up. The first valid referrer wins. You cannot refer yourself or your own accounts.'],
            ['Holding period and payouts', 'Commissions are held for ' . (int) $aff['hold_days'] . ' days to cover refunds, then become available. You can request a payout once you have at least $' . number_format((float) $aff['min_payout'], 2) . ' available. Payouts are sent manually by bank transfer, USDT or PayPal, usually within 7 days. Refunded payments do not earn commission.'],
            ['Broker links', 'You may add your own broker referral link. It goes live after we approve it and is shown to the users you referred. Your agreement with that broker is between you and the broker.'],
            ['Rules', 'No spam, no fake reviews or testimonials, no misleading claims, no promises of profits or guaranteed win rates, no bidding on our brand name in paid ads, and no impersonating us. Always include a risk warning when you promote trading signals.'],
            ['Termination', 'We may suspend an affiliate account and withhold commissions earned through fraud or rule breaks. You can leave the programme at any time by contacting ' . $mail . '.'],
        ]],
        default => ['Not found', []],
    };
}
