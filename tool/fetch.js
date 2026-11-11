const fs = require('fs');
const { getHistoricalRates } = require('dukascopy-node');
const pairs = JSON.parse(process.env.PAIRS || '{"btcusd":"BTC/USD","ethusd":"ETH/USD","usatechidxusd":"US100","usa30idxusd":"US30"}');
(async () => {
  fs.mkdirSync('out', { recursive: true });
  for (const [inst, sym] of Object.entries(pairs)) {
    const rows = [];
    for (const [from, to] of [['2022-01-01','2023-01-01'],['2023-01-01','2024-01-01'],['2024-01-01','2025-01-01'],['2025-01-01','2026-01-01'],['2026-01-01','2026-11-09']]) {
      let data;
      for (let a = 0; a < 4; a++) {
        try { data = await getHistoricalRates({ instrument: inst, dates: { from: new Date(from), to: new Date(to) }, timeframe: 'h1', format: 'json', priceType: 'bid', retryCount: 5, batchSize: 5, pauseBetweenBatchesMs: 500 }); break; }
        catch (e) { console.error(inst, from, 'retry', e.message); await new Promise(r => setTimeout(r, 5000)); }
      }
      if (!data) throw new Error('failed ' + inst + ' ' + from);
      rows.push(...data);
    }
    const seen = new Set(); const lines = ['symbol,time,open,high,low,close'];
    rows.sort((a, b) => a.timestamp - b.timestamp);
    for (const r of rows) {
      if (seen.has(r.timestamp) || r.timestamp % 3600000 && false) continue; seen.add(r.timestamp);
      const lo = Math.min(r.low, r.open, r.close), hi = Math.max(r.high, r.open, r.close);
      lines.push([sym, new Date(r.timestamp).toISOString().slice(0, 19) + 'Z', r.open, hi, lo, r.close].join(','));
    }
    fs.writeFileSync('out/' + inst + '.csv', lines.join('\n') + '\n');
    console.log(sym, lines.length - 1, 'bars');
  }
})().catch(e => { console.error(e); process.exit(1); });
