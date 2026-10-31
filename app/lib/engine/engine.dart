import 'dart:math' as math;

import '../models/models.dart';

/// Same rules as the server's Engine.php (used by demo mode).
class Indicators {
  static List<double?> ema(List<double> v, int p) {
    final out = List<double?>.filled(v.length, null);
    if (v.length < p) return out;
    double prev = 0;
    for (var i = 0; i < p; i++) {
      prev += v[i];
    }
    prev /= p;
    out[p - 1] = prev;
    final k = 2 / (p + 1);
    for (var i = p; i < v.length; i++) {
      prev = v[i] * k + prev * (1 - k);
      out[i] = prev;
    }
    return out;
  }

  static double _rsiV(double g, double l) =>
      l == 0 ? (g == 0 ? 50 : 100) : 100 - 100 / (1 + g / l);

  static List<double?> rsi(List<double> c, int p) {
    final out = List<double?>.filled(c.length, null);
    if (c.length <= p) return out;
    double g = 0, l = 0;
    for (var i = 1; i <= p; i++) {
      final d = c[i] - c[i - 1];
      if (d > 0) {
        g += d;
      } else {
        l -= d;
      }
    }
    var ag = g / p, al = l / p;
    out[p] = _rsiV(ag, al);
    for (var i = p + 1; i < c.length; i++) {
      final d = c[i] - c[i - 1];
      ag = (ag * (p - 1) + math.max(d, 0)) / p;
      al = (al * (p - 1) + math.max(-d, 0)) / p;
      out[i] = _rsiV(ag, al);
    }
    return out;
  }

  static List<double?> macdHistogram(List<double> c) {
    final n = c.length;
    final f = ema(c, 12), s = ema(c, 26);
    final hist = List<double?>.filled(n, null);
    const start = 25;
    if (n <= start) return hist;
    final line = <double>[for (var i = start; i < n; i++) f[i]! - s[i]!];
    final sig = ema(line, 9);
    for (var j = 0; j < sig.length; j++) {
      final sv = sig[j];
      if (sv != null) hist[start + j] = line[j] - sv;
    }
    return hist;
  }

  static List<double?> atr(List<Candle> cs, int p) {
    final n = cs.length;
    final out = List<double?>.filled(n, null);
    if (n <= p) return out;
    final tr = <double>[cs[0].high - cs[0].low];
    for (var i = 1; i < n; i++) {
      final pc = cs[i - 1].close;
      tr.add(math.max(cs[i].high - cs[i].low,
          math.max((cs[i].high - pc).abs(), (cs[i].low - pc).abs())));
    }
    double prev = 0;
    for (var i = 1; i <= p; i++) {
      prev += tr[i];
    }
    prev /= p;
    out[p] = prev;
    for (var i = p + 1; i < n; i++) {
      prev = (prev * (p - 1) + tr[i]) / p;
      out[i] = prev;
    }
    return out;
  }
}

class EngineResult {
  final String direction; // BUY, SELL, WAIT
  final int confidence;
  final double entry;
  final double? sl, tp1, tp2;
  final List<String> reasons;
  const EngineResult(this.direction, this.confidence, this.entry, this.sl,
      this.tp1, this.tp2, this.reasons);
}

class SignalEngine {
  static EngineResult? analyze(List<Candle> cs, {int threshold = 60}) {
    final n = cs.length;
    if (n < 60) return null;
    final c = cs.map((x) => x.close).toList();
    final last = n - 1;
    final fa = Indicators.ema(c, 20),
        sl = Indicators.ema(c, 50),
        tr = Indicators.ema(c, 200);
    final r = Indicators.rsi(c, 14);
    final h = Indicators.macdHistogram(c);
    final a = Indicators.atr(cs, 14);

    final price = c[last];
    final eps = price.abs() * 1e-7;
    final rn = r[last] ?? 50;
    final hn = h[last] ?? 0;
    final hp = h[last - 1] ?? 0;
    final at = a[last] ?? 0;
    var bull = 0, bear = 0;
    final br = <String>[], sr = <String>[];

    final trend = tr[last];
    if (trend != null) {
      if (price - trend > eps) {
        bull += 20;
        br.add('Price above the 200 EMA (long-term uptrend)');
      } else if (trend - price > eps) {
        bear += 20;
        sr.add('Price below the 200 EMA (long-term downtrend)');
      }
    }
    if (fa[last]! - sl[last]! > eps) {
      bull += 25;
      br.add('20 EMA above 50 EMA');
    } else if (sl[last]! - fa[last]! > eps) {
      bear += 25;
      sr.add('20 EMA below 50 EMA');
    }
    if (hn > eps) {
      bull += 20;
      br.add('MACD histogram positive');
      if (hn > hp) {
        bull += 5;
        br.add('Bullish momentum increasing');
      }
    } else if (hn < -eps) {
      bear += 20;
      sr.add('MACD histogram negative');
      if (hn < hp) {
        bear += 5;
        sr.add('Bearish momentum increasing');
      }
    }
    if (rn > 55) {
      bull += 15;
      br.add('RSI ${rn.toStringAsFixed(0)} confirms bullish momentum');
    } else if (rn < 45) {
      bear += 15;
      sr.add('RSI ${rn.toStringAsFixed(0)} confirms bearish momentum');
    }
    var cross = 0;
    for (var i = last; i > last - 3; i--) {
      final f0 = fa[i - 1], s0 = sl[i - 1], f1 = fa[i], s1 = sl[i];
      if (f0 == null || s0 == null || f1 == null || s1 == null) break;
      if (f0 <= s0 && f1 > s1) {
        cross = 1;
        break;
      }
      if (f0 >= s0 && f1 < s1) {
        cross = -1;
        break;
      }
    }
    if (cross > 0) {
      bull += 15;
      br.add('Fresh bullish EMA crossover');
    } else if (cross < 0) {
      bear += 15;
      sr.add('Fresh bearish EMA crossover');
    }
    if (rn > 70) {
      bull -= 15;
      br.add('Caution: RSI overbought');
    } else if (rn < 30) {
      bear -= 15;
      sr.add('Caution: RSI oversold');
    }

    if (bull >= threshold && bull > bear && at > 0) {
      return EngineResult('BUY', bull.clamp(0, 100).toInt(), price,
          price - 1.5 * at, price + 1.5 * at, price + 3 * at, br);
    }
    if (bear >= threshold && bear > bull && at > 0) {
      return EngineResult('SELL', bear.clamp(0, 100).toInt(), price,
          price + 1.5 * at, price - 1.5 * at, price - 3 * at, sr);
    }
    return EngineResult('WAIT', math.max(bull, bear), price, null, null, null,
        const []);
  }
}
