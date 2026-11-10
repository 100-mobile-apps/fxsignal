# Strategy v2 — backtest results (Nov 2026)

## Data and method

- **Prices:** Dukascopy hourly bid candles, Jan 2022 to Nov 2026, for EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, USD/CHF, NZD/USD and XAU/USD. That's about 30,000 candles per pair. Hourly candles were combined into 4-hour candles (00/04/08/12/16/20 UTC).
- **Costs:** every trade pays 1.2 pips (currency pairs) or 3 pips (gold) for spread and slippage.
- **Trade rules:** these match the app. A signal is a "win" once TP1 is reached; the stop then moves to entry, and the trade closes at TP2, back at entry, or at expiry. Inside each candle the stop is checked first, which is the conservative choice. Entries are made at the next candle's open, the weekend pause is respected, and a pair has one open signal at a time.
- **Avoiding curve-fitting:** rules were chosen using **2022–2024 only** ("in-sample"). **Jan 2025 – Nov 2026** ("out-of-sample") was used only to judge the result. Several variants were tried on 2022–2024, so treat the out-of-sample numbers as the honest guide.
- **R** = one unit of risk, i.e. the distance from entry to stop loss. +0.03R per trade means you gain 3% of your risk per trade on average.

## Results

| | Old strategy (H1 trend) | **New strategy v2 (H4)** |
|---|---|---|
| Signals per week (all 8 pairs) | ~40 | **~3.5** |
| Signals reaching TP1, 2022–2024 | 49.1% | **65.4%** |
| Signals reaching TP1, 2025–2026 (unseen) | 50.2% | **66.4%** |
| Average result per signal after costs, 2022–2024 | −0.074R | **+0.026R** |
| Average result per signal after costs, 2025–2026 | −0.059R | **+0.036R** |
| Worst losing streak (drawdown), 2025–2026 | 252R | **8.7R** |

With lower broker costs (0.7 / 2.5 pips), v2 makes +0.033R (2022–2024) and +0.044R (2025–2026) per signal.

### By pair, total R after costs (2022–2024 / 2025–2026)

AUD/USD +2.6 / +6.0 · EUR/USD −3.0 / +3.7 · GBP/USD +10.6 / +3.0 · NZD/USD −1.6 / −3.9 ·
USD/CAD −1.9 / +2.8 · USD/CHF +7.2 / −1.1 · USD/JPY −4.5 / −4.6 · XAU/USD +4.7 / +6.4

## What this means

- The old strategy lost money after spreads on real data. Its signals were close to a coin flip at 1:1 risk-to-reward.
- v2 sends far fewer signals but much better ones: about 2 in 3 reach TP1, and it is slightly profitable after costs in both periods.
- The edge is small. Expect losing weeks and losing months. Past results don't guarantee future results, and the app's risk warning stays in place.
- USD/JPY was weak in both periods. If it stays weak in live results, remove it from `pairs` in config.php.

## Reproduce

```
node backend/tests/strategy_research/fetch_dukascopy.js           # needs internet; writes out/*.csv
(head -1 out/eurusd.csv; for f in out/*.csv; do tail -n +2 $f; done) > all.csv
php backend/tests/strategy_research/run_h4.php all.csv pairs live   # production engine on 4h candles
php backend/tests/strategy_research/run_h1.php all.csv - base       # old strategy on 1h candles
```
