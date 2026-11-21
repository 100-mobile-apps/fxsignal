# FX Signal Bot (MetaTrader 5)

Places every app signal on your own account using the two-trade method:

- Trade A takes profit at TP1, trade B at TP2.
- When TP1 is hit, B's stop moves to entry.
- Halfway from TP1 to TP2, B's stop moves to TP1.

Your broker login never leaves your computer. The bot reads signals with a personal read-only key
(App > Results > Auto-trade bot).

## Install
1. MetaTrader 5: File > Open Data Folder > MQL5 > Experts. Put `FxSignalBot.mq5` there, then open it in MetaEditor (F4) and press Compile.
2. Tools > Options > Expert Advisors: tick "Allow WebRequest for listed URL" and add `https://forexsignal.bulksend.top/ea/signals`.
3. Drag FxSignalBot onto any chart. Paste the key into `ApiKey`. Switch on Algo Trading.
4. Try a demo account first.

## Settings that matter
| Setting | Default | What it does |
|---|---|---|
| RiskPercent | 0 | 0 follows the risk you set in the app. Any other number overrides it. |
| MaxRiskPercent | 30 | Hard cap per signal. |
| MaxOverRiskFactor | 2 | Small accounts: the 0.01 lot minimum is used only if its real risk is at most this many times the requested risk. |
| DailyLossLimitPct | 0 | Stops new signals when equity is down this much today (0 = off). |
| MaxOpenSignals | 6 | Signals open at once. |
| SymbolSuffix / SymbolMap | empty | For brokers that rename markets (`EURUSD.m`, `GOLD`). |
| EnableTrading | true | false = watch only. |

## Good to know
- Needs a hedging account (two trades per signal). Netting accounts are refused at start.
- Stays on only while MetaTrader is running; use a VPS if you want it 24/5.
- Signals older than 90 minutes, or where price has already run away from entry, are skipped.
- If the server says a signal has ended (expired), any trades left from it are closed.
- Trading is risky; high risk settings can empty an account in a losing streak.

Not compiled on a live terminal by the author yet: test on a demo account and report anything odd.
