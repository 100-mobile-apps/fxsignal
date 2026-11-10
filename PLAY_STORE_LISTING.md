# Google Play listing: Forex Signals Live

Paste these fields into Play Console → *Grow users → Store presence → Main store listing*.
Every field below is within Google's character limits (checked).

---

## App name (title) — 29 / 30 characters
```
Forex Signals Live: Gold & FX
```
It covers the main searches: **forex signals**, **forex signals live**, **gold signals**, **fx signals**.

**Why "Free" is not in the title:** Google's store listing rules say *"Don't use 'Free' and 'No Ads' … these promote deals and don't belong in app titles"*, and the Metadata policy bans price or promotional wording in the title, icon and developer name. Some apps do it anyway, but it can get your update rejected or your listing suspended, and a suspension costs far more ranking than the word gains. "Free" goes in the short and full description instead, where Google indexes it and allows it. The Play Store already shows a **Free** label next to the Install button for every free app.

Other compliant titles you can A/B test later (Store listing experiments):
| Title | Chars | Extra keywords |
|---|---|---|
| `Forex Signals: Buy/Sell Alerts` | 30 | buy sell signals, alerts |
| `Forex Signals & Gold Trading` | 28 | gold trading |

If you still decide to take the risk, the version that fits is `Free Forex Signals: Gold & FX` (29).

## Short description — 75 / 80 characters
```
Forex & gold signals with entry, stop loss & take profit. 7-day free trial.
```

## Full description — 2,734 / 4,000 characters
Updated for version 1.1.0 (Premium). Google rejects listings that call a paid feature free, so "free" now refers only to the trial and the free features.
```
Get forex signals and gold signals with a clear trade plan: entry price, stop loss and two take profit targets for every signal. Forex Signals Live watches the major currency pairs and gold (XAU/USD) around the clock and alerts you the moment a new buy or sell signal is published. Start with a 7-day free trial.

PREMIUM: LIVE SIGNALS
• Live buy and sell signals for EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, USD/CHF, NZD/USD and gold (XAU/USD)
• Entry, stop loss (SL), take profit 1 and take profit 2 (TP1, TP2) the moment each signal is published
• Instant push notifications and email alerts with the full levels
• Live chart for every signal with risk and reward zones, plus the reasons behind each trade
• 7-day free trial, then $5 per month, or $36 per year ($3 a month). Cancel anytime in Google Play.

FREE FOR EVERYONE
• Full track record: every closed signal with its entry, result and pips
• Daily, weekly and monthly performance reports
• Alerts when signals hit TP1, TP2 or the stop loss
• Lot size calculator, pip value calculator, market sessions clock and a watchlist with live prices

A TRACK RECORD YOU CAN CHECK
Every signal is recorded automatically and closed by fixed rules, win or loss. Nothing is edited or deleted. See the win rate, total pips, profit factor and growth curve, and open any day, week or month to see every trade in it.

HOW THE SIGNALS WORK
Signals come from technical analysis on the 4-hour chart. For currency pairs, the engine looks for price stretched outside the Bollinger Bands in a calm, ranging market (low ADX) with RSI at an extreme, and targets a move back toward the average. For gold, which tends to trend, it follows the trend (200, 50 and 20 EMA, MACD, RSI) and only enters after a pullback to the 20 EMA. Stop loss and take profit levels are based on ATR (average true range), so they adapt to how much the market is moving. The full method is explained inside the app.

NOTIFICATIONS YOUR WAY
Choose push notifications, email alerts or both. Pick the pairs you follow, and turn the daily, weekly and monthly reports on or off.

GOOD FOR BEGINNERS
Each signal shows exactly where to enter, where to place your stop loss and where to take profit, plus a lot size calculator so you never risk too much on one trade. Practise on a demo account with your broker first.

RISK WARNING
Trading forex and gold on margin carries a high level of risk and may not be suitable for all investors. Signals are automated technical analysis for educational and informational purposes only. They are not financial advice. Past results do not guarantee future results. Only trade with money you can afford to lose.

Questions, feedback or a custom app for your business: swalafblog@gmail.com
```

---

## Other listing settings
| Field | Value |
|---|---|
| App category | **Finance** |
| Tags (pick up to 5) | Trading, Investing, Stocks & markets, Finance tools, Calculators (use whichever are offered) |
| Developer name | Swiftmynd Technologies Ltd |
| Contact email | swalafblog@gmail.com |
| Privacy policy URL | host the Privacy screen text at e.g. `https://yourdomain.com/privacy` |
| Content rating | Complete the questionnaire; no user-generated content or gambling |
| Financial features declaration | Declare the app provides **financial information / trading signals**, no investing or trading services |
| Ads | "No, my app does not contain ads" (unless you add them) |

## Graphics
| Asset | Spec | Prepared file |
|---|---|---|
| Icon | 512x512 PNG | `store-assets/play-icon.png`; rendered from the app's actual `LogoMark` widget, with matching Android mipmap launcher resources. |
| Feature graphic | 1024x500 PNG | `store-assets/feature-graphic.png` (editable SVG alongside it); illustrated BUY card and the requested headline. |
| Screenshot previews | 1080x1920 PNG | `store-assets/screenshots/01-signals.png` through `07-markets.png`. |

The seven screenshots are **illustrative demo captures** from the Flutter UI,
not the uploaded live AAB. Their captions say so; prices, win rates and pips
are simulated, and push is not configured in that demo build. For a final
Play listing, replace these previews with screenshots captured from the
actual published Android build after its backend and advertised alerts work.
Do not present demo results as a verified trading track record. The current
An AAB built before the launcher resource update still has Flutter's default
icon. Upload a newly signed AAB with build number 2 or higher so the installed
app matches this Play icon.

Prepared screenshot captions, in order:
1. **Signal plans: entry, SL & TP** - demo Signals tab with BUY and SELL cards
2. **Chart and risk/reward zones** - demo signal detail
3. **Simulated results layout** - demo Results overview
4. **Simulated report layouts** - demo Results reports
5. **Choose alert preferences** - demo notification settings; Firebase disabled
6. **Position size calculator** - Tools tab
7. **Markets and trading sessions** - demo Markets tab

## Keywords this listing targets
Primary: forex signals, forex signals free trial, forex signals live, gold signals, xauusd signals, fx signals, trading signals, buy sell signals
Secondary: forex alerts, forex trading app, stop loss take profit, tp sl signals, eurusd signals, gbpusd signals, forex for beginners, lot size calculator, pip calculator, forex market sessions, forex reports

Google Play has no keyword field. It ranks on the **title** (strongest), **short description**, then the **full description**. Each main keyword above appears there naturally 2–4 times. Adding more repetitions counts as keyword stuffing, which the policy prohibits and which can lower ranking.

## What moves ranking after launch
1. **Installs and retention.** Push and email alerts bring users back daily, which Play rewards.
2. **Ratings.** The app asks for a review at good moments (see `lib/services/review_service.dart`) using Google's official in-app review prompt. Never offer rewards for reviews; that violates policy.
3. **Stability.** Keep crash and ANR rates low (Play Console → Android vitals). Bad vitals reduce visibility.
4. **Store listing experiments.** Test the two alternative titles and the first screenshot after you have about 1,000 visitors a week.
5. **Custom store listings** for Nigeria, the UK, South Africa and other target countries. Mention local brokers' popular pairs, but never name a broker you're not partnered with.
6. **Update regularly** (every 4–6 weeks) and reply to reviews.

## Don't
- Put "Free", "#1", "Best", "Top", "No Ads" or emojis in the title, icon or developer name
- Promise profits or state a win rate in the listing (it changes, and it counts as a performance claim)
- Add fake or unattributed testimonials
- Name other apps or brands (for example "MetaTrader" or a broker) as keywords
