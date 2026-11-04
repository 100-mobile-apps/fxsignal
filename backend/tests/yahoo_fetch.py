"""Download hourly (from 2025-01) and daily (from 2024-06) candles from Yahoo Finance for the app's 8 markets.

No API key needed. Gold uses COMEX gold futures (GC=F), which track spot gold closely.
Writes <out>/<SYM>_1h.csv and <out>/<SYM>_1d.csv with columns t,o,h,l,c (t = bar open, unix UTC).
Usage: python3 yahoo_fetch.py OUT_DIR
"""
import csv
import json
import os
import sys
import time
import urllib.request
from datetime import datetime, timezone

SYMBOLS = {
    'EUR/USD': 'EURUSD=X', 'GBP/USD': 'GBPUSD=X', 'USD/JPY': 'JPY=X', 'AUD/USD': 'AUDUSD=X',
    'USD/CAD': 'CAD=X', 'USD/CHF': 'CHF=X', 'NZD/USD': 'NZDUSD=X', 'XAU/USD': 'GC=F',
}


def ts(day):
    return int(datetime.strptime(day, '%Y-%m-%d').replace(tzinfo=timezone.utc).timestamp())


def fetch(ticker, interval, start):
    url = (f'https://query1.finance.yahoo.com/v8/finance/chart/{ticker}'
           f'?interval={interval}&period1={ts(start)}&period2={int(time.time())}')
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req, timeout=60) as r:
        res = json.load(r)['chart']['result'][0]
    q = res['indicators']['quote'][0]
    rows = []
    for i, t in enumerate(res['timestamp']):
        o, h, l, c = q['open'][i], q['high'][i], q['low'][i], q['close'][i]
        if None in (o, h, l, c) or min(o, h, l, c) <= 0:
            continue
        rows.append((t, o, max(h, o, c), min(l, o, c), c))
    return rows


def main():
    out = sys.argv[1]
    os.makedirs(out, exist_ok=True)
    for sym, ticker in SYMBOLS.items():
        for interval, start, suffix in (('60m', '2025-01-02', '1h'), ('1d', '2024-06-01', '1d')):
            rows = fetch(ticker, interval, start)
            path = os.path.join(out, sym.replace('/', '_') + f'_{suffix}.csv')
            with open(path, 'w', newline='') as f:
                w = csv.writer(f)
                w.writerow(['t', 'o', 'h', 'l', 'c'])
                w.writerows(rows)
            first = datetime.fromtimestamp(rows[0][0], timezone.utc).date()
            last = datetime.fromtimestamp(rows[-1][0], timezone.utc).date()
            print(f'{sym} {suffix}: {len(rows)} bars {first} to {last}')
            time.sleep(1)


if __name__ == '__main__':
    main()
