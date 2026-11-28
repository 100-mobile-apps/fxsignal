"""Vary risk, sizing and a month lock-in rule over every hunt combination, looking for +$750 months from $100.

Input: the trade list from `HUNT_TRADES=1 php run_hunt.php DATA_DIR`.
Each market starts every month with $100; the month's profit is withdrawn (or the loss topped up) at month end.
Settings:
  risk    10, 20, 30, 50, 75, 100 % per trade
  sizing  'compound' = % of the current balance; 'fixed' = % of the starting $100 (capped at what is left)
  lock    'none' = keep trading all month; '750' = stop trading for the month once the balance reaches $850
Honest test: settings and combinations are chosen on 2025 (Feb-Dec) and judged on 2026 (Jan - 4 Nov).
Usage: python3 vary_750.py hunt_trades.json OUT.json
"""
import collections
import json
import sys
from datetime import datetime, timezone

RISKS = (0.10, 0.20, 0.30, 0.50, 0.75, 1.00)
SIZING = ('compound', 'fixed')
LOCK = ('none', '750')


def month_of(ts):
    return datetime.fromtimestamp(ts, timezone.utc).strftime('%Y-%m')


def months_result(trades_by_month, risk, sizing, lock):
    out = {}
    for m, rs in trades_by_month.items():
        bal = 100.0
        low = 100.0
        for r in rs:
            if bal <= 0.5:
                break
            if lock == '750' and bal >= 850:
                break
            stake = bal * risk if sizing == 'compound' else min(100 * risk, bal)
            bal = max(0.0, bal + stake * r)
            low = min(low, bal)
        out[m] = (bal - 100.0, low)
    return out


def main():
    data = json.load(open(sys.argv[1]))
    combos = []
    for tf, tgt, strat, mk, trades in data['rows']:
        by = collections.defaultdict(list)
        for t, r in trades:
            by[month_of(t)].append(r)
        combos.append(((tf, tgt, strat, mk), by))

    stats = []   # one per combo x setting
    for key, by in combos:
        n25 = sum(len(v) for m, v in by.items() if m.startswith('2025'))
        mt25 = sum(1 for m in by if m.startswith('2025'))
        for risk in RISKS:
            for sizing in SIZING:
                for lock in LOCK:
                    res = months_result(by, risk, sizing, lock)
                    s = {'key': key, 'risk': risk, 'sizing': sizing, 'lock': lock, 'n25': n25, 'mt25': mt25}
                    for y in ('2025', '2026'):
                        ms = [(p, low) for m, (p, low) in res.items() if m.startswith(y)]
                        s[f'p{y}'] = sum(p for p, _ in ms)
                        s[f'mt{y}'] = len(ms)
                        s[f'hit{y}'] = sum(p >= 750 for p, _ in ms)
                        s[f'wipe{y}'] = sum(low <= 10 for _, low in ms)
                        s[f'green{y}'] = sum(p > 0 for p, _ in ms)
                    stats.append(s)

    total26 = collections.Counter()
    by_setting = collections.defaultdict(lambda: collections.Counter())
    for s in stats:
        k = (s['risk'], s['sizing'], s['lock'])
        c = by_setting[k]
        c['months'] += s['mt2026']; c['hit'] += s['hit2026']; c['wipe'] += s['wipe2026']; c['p'] += s['p2026']
    print('ALL COMBINATIONS, 2026: share of months reaching +$750, share wiped, average month')
    print(f"{'risk':>5} {'sizing':8} {'lock':5} {'+750 months':>12} {'wiped':>7} {'avg month':>10}")
    for k in sorted(by_setting):
        c = by_setting[k]
        print(f"{int(k[0]*100):>4}% {k[1]:8} {k[2]:5} {c['hit']/c['months']:>11.2%} {c['wipe']/c['months']:>7.1%} {c['p']/c['months']:>+10.2f}")

    # Choose on 2025: enough activity, then rank by number of +750 months in 2025, ties by 2025 average month.
    ok = [s for s in stats if s['n25'] >= 15 and s['mt25'] >= 6]
    ok.sort(key=lambda s: (-s['hit2025'], -(s['p2025'] / max(1, s['mt2025']))))
    seen = set(); picks = []
    for s in ok:
        if s['key'] in seen:
            continue
        seen.add(s['key']); picks.append(s)
        if len(picks) == 25:
            break
    print('\nTOP 25 CHOSEN ON 2025 (most +$750 months in 2025), WHAT THEY DID IN 2026')
    print(f"{'combo':70} {'setting':22} | 2025: +750m avg/mo | 2026: +750m avg/mo wiped months")
    tot = collections.Counter()
    for s in picks:
        k = s['key']; label = f"{k[0]} {k[1]:6} {k[3]:8} {k[2][:40]}"
        st = f"{int(s['risk']*100)}% {s['sizing']} lock={s['lock']}"
        a25 = s['p2025'] / max(1, s['mt2025']); a26 = s['p2026'] / max(1, s['mt2026'])
        tot['hit25'] += s['hit2025']; tot['hit26'] += s['hit2026']; tot['m26'] += s['mt2026']; tot['p26'] += s['p2026']; tot['w26'] += s['wipe2026']
        print(f"{label:70} {st:22} | {s['hit2025']:>3} {a25:>+8.0f}  | {s['hit2026']:>3} {a26:>+8.0f} {s['wipe2026']:>3}/{s['mt2026']}")
    print(f"\n25 picks in 2026: +$750 months {tot['hit26']} of {tot['m26']} ({tot['hit26']/max(1,tot['m26']):.1%}), "
          f"wiped {tot['w26']} ({tot['w26']/max(1,tot['m26']):.1%}), average month {tot['p26']/max(1,tot['m26']):+.0f} per $100")

    best = max(stats, key=lambda s: s['hit2026'])
    print(f"\nWith hindsight, the most +$750 months any combination and setting reached in 2026: {best['hit2026']} of {best['mt2026']} "
          f"({best['key'][0]} {best['key'][1]} {best['key'][3]} {best['key'][2][:40]}, {int(best['risk']*100)}% {best['sizing']} lock={best['lock']}; "
          f"its 2025: {best['hit2025']} of {best['mt2025']})")

    json.dump({'settings': [{'risk': k[0], 'sizing': k[1], 'lock': k[2], 'months_2026': c['months'], 'hit750_2026': c['hit'], 'wiped_2026': c['wipe'], 'avg_month_2026': round(c['p'] / c['months'], 2)} for k, c in sorted(by_setting.items())],
               'picks': [{'timeframe': s['key'][0], 'targets': s['key'][1], 'strategy': s['key'][2], 'market': s['key'][3], 'risk': s['risk'], 'sizing': s['sizing'], 'lock': s['lock'],
                          'hit750_2025': s['hit2025'], 'avg_month_2025': round(s['p2025'] / max(1, s['mt2025']), 2), 'months_2025': s['mt2025'],
                          'hit750_2026': s['hit2026'], 'avg_month_2026': round(s['p2026'] / max(1, s['mt2026']), 2), 'months_2026': s['mt2026'], 'wiped_2026': s['wipe2026'], 'green_2026': s['green2026']} for s in picks]},
              open(sys.argv[2], 'w'), separators=(',', ':'))


if __name__ == '__main__':
    main()
