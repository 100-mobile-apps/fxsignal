import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';

/// Candlestick chart with shaded risk (entry→SL) and reward (entry→TP2) zones.
class SignalChart extends StatelessWidget {
  final TradeSignal signal;
  final double height;
  const SignalChart({super.key, required this.signal, this.height = 250});

  @override
  Widget build(BuildContext context) {
    if (signal.candles.isEmpty) {
      return SizedBox(
          height: height,
          child: const Center(
              child: Text('Chart unavailable', style: AppText.caption)));
    }
    return SizedBox(
      height: height,
      width: double.infinity,
      child: CustomPaint(painter: _SignalChartPainter(signal)),
    );
  }
}

class _SignalChartPainter extends CustomPainter {
  final TradeSignal s;
  _SignalChartPainter(this.s);

  static const labelW = 66.0;

  @override
  void paint(Canvas canvas, Size size) {
    final cs = s.candles.length > 70 ? s.candles.sublist(s.candles.length - 70) : s.candles;
    final levels = [s.entry, s.stopLoss, s.tp2];
    var lo = math.min(cs.map((c) => c.low).reduce(math.min), levels.reduce(math.min));
    var hi = math.max(cs.map((c) => c.high).reduce(math.max), levels.reduce(math.max));
    final pad = (hi - lo) * 0.08;
    lo -= pad;
    hi += pad;
    final w = size.width - labelW;
    final slot = w / cs.length;
    double y(double p) => size.height * (hi - p) / (hi - lo);
    double x(int i) => slot * i + slot / 2;

    // Index where the signal opened (first candle after openedAt).
    var openIdx = cs.indexWhere((c) => !c.time.isBefore(s.openedAt));
    if (openIdx < 0) openIdx = cs.length - 1;
    final zoneLeft = x(openIdx) - slot / 2;

    // Zones
    final risk = Rect.fromLTRB(zoneLeft, math.min(y(s.entry), y(s.stopLoss)), w,
        math.max(y(s.entry), y(s.stopLoss)));
    final reward = Rect.fromLTRB(zoneLeft, math.min(y(s.entry), y(s.tp2)), w,
        math.max(y(s.entry), y(s.tp2)));
    canvas.drawRect(risk, Paint()..color = AppColors.sell.withValues(alpha: 0.10));
    canvas.drawRect(reward, Paint()..color = AppColors.buy.withValues(alpha: 0.10));

    // Grid
    final grid = Paint()
      ..color = Colors.white.withValues(alpha: 0.04)
      ..strokeWidth = 1;
    for (var g = 1; g < 5; g++) {
      final gy = size.height * g / 5;
      canvas.drawLine(Offset(0, gy), Offset(w, gy), grid);
    }

    // Candles
    final bodyW = math.max(1.6, slot * 0.62);
    for (var i = 0; i < cs.length; i++) {
      final c = cs[i];
      final up = c.close >= c.open;
      final paint = Paint()
        ..color = up ? AppColors.buy : AppColors.sell
        ..strokeWidth = 1;
      canvas.drawLine(Offset(x(i), y(c.high)), Offset(x(i), y(c.low)), paint);
      final top = y(math.max(c.open, c.close));
      final bot = y(math.min(c.open, c.close));
      canvas.drawRRect(
          RRect.fromRectAndRadius(
              Rect.fromLTRB(x(i) - bodyW / 2, top, x(i) + bodyW / 2, math.max(bot, top + 1.2)),
              const Radius.circular(1)),
          paint);
    }

    void level(double price, Color color, String tag, {bool solid = false}) {
      final ly = y(price);
      final p = Paint()
        ..color = color.withValues(alpha: 0.9)
        ..strokeWidth = 1;
      if (solid) {
        canvas.drawLine(Offset(zoneLeft, ly), Offset(w, ly), p);
      } else {
        for (double dx = zoneLeft; dx < w; dx += 7) {
          canvas.drawLine(Offset(dx, ly), Offset(math.min(dx + 4, w), ly), p);
        }
      }
      final tp = TextPainter(
        text: TextSpan(
            text: '$tag ${Fmt.price(s.pair, price)}',
            style: TextStyle(
                color: AppColors.bg, fontSize: 9, fontWeight: FontWeight.w700)),
        textDirection: TextDirection.ltr,
      )..layout();
      final r = RRect.fromRectAndRadius(
          Rect.fromLTWH(w + 3, ly - 8, math.min(labelW - 4, tp.width + 8), 16),
          const Radius.circular(4));
      canvas.drawRRect(r, Paint()..color = color);
      canvas.save();
      canvas.clipRRect(r);
      tp.paint(canvas, Offset(w + 7, ly - tp.height / 2));
      canvas.restore();
    }

    level(s.tp2, AppColors.buy, 'TP2');
    level(s.tp1, AppColors.buy, 'TP1');
    level(s.stopLoss, AppColors.sell, 'SL');
    level(s.entry, AppColors.gold, 'E', solid: true);

    // Current price marker
    final cur = s.currentPrice;
    if (s.isRunning && cur != null) {
      final cy = y(cur);
      canvas.drawCircle(Offset(x(cs.length - 1), cy), 4, Paint()..color = AppColors.text);
      canvas.drawCircle(Offset(x(cs.length - 1), cy), 8,
          Paint()..color = AppColors.text.withValues(alpha: 0.18));
    }
  }

  @override
  bool shouldRepaint(covariant _SignalChartPainter old) => old.s != s;
}

class Sparkline extends StatelessWidget {
  final List<double> values;
  final Color color;
  final double width, height;
  const Sparkline(
      {super.key,
      required this.values,
      required this.color,
      this.width = 72,
      this.height = 30});

  @override
  Widget build(BuildContext context) => SizedBox(
      width: width,
      height: height,
      child: CustomPaint(painter: _SparkPainter(values, color)));
}

class _SparkPainter extends CustomPainter {
  final List<double> v;
  final Color color;
  _SparkPainter(this.v, this.color);

  @override
  void paint(Canvas canvas, Size size) {
    if (v.length < 2) return;
    final lo = v.reduce(math.min), hi = v.reduce(math.max);
    final range = hi - lo == 0 ? 1 : hi - lo;
    final path = Path();
    for (var i = 0; i < v.length; i++) {
      final px = size.width * i / (v.length - 1);
      final py = size.height - (v[i] - lo) / range * size.height;
      i == 0 ? path.moveTo(px, py) : path.lineTo(px, py);
    }
    final fill = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
        fill,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [color.withValues(alpha: 0.25), color.withValues(alpha: 0)],
          ).createShader(Offset.zero & size));
    canvas.drawPath(
        path,
        Paint()
          ..color = color
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.6
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round);
  }

  @override
  bool shouldRepaint(covariant _SparkPainter old) => old.v != v || old.color != color;
}

/// Circular win-rate gauge.
class WinRateRing extends StatelessWidget {
  final double percent; // 0–100
  final double size;
  const WinRateRing({super.key, required this.percent, this.size = 120});

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: percent),
      duration: const Duration(milliseconds: 900),
      curve: Curves.easeOutCubic,
      builder: (_, v, __) => SizedBox(
        width: size,
        height: size,
        child: CustomPaint(
          painter: _RingPainter(v),
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('${v.toStringAsFixed(0)}%', style: AppText.mono(size * 0.22, weight: FontWeight.w800)),
                const Text('Win rate', style: AppText.caption),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  final double pct;
  _RingPainter(this.pct);

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.width * 0.085;
    final rect = Offset(stroke / 2, stroke / 2) &
        Size(size.width - stroke, size.height - stroke);
    canvas.drawArc(
        rect,
        0,
        math.pi * 2,
        false,
        Paint()
          ..color = AppColors.surface2
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke);
    canvas.drawArc(
        rect,
        -math.pi / 2,
        math.pi * 2 * (pct / 100).clamp(0, 1),
        false,
        Paint()
          ..shader = const SweepGradient(
            colors: [AppColors.goldDeep, AppColors.gold, Color(0xFFFFE3A3)],
            transform: GradientRotation(-math.pi / 2),
          ).createShader(rect)
          ..style = PaintingStyle.stroke
          ..strokeCap = StrokeCap.round
          ..strokeWidth = stroke);
  }

  @override
  bool shouldRepaint(covariant _RingPainter old) => old.pct != pct;
}

/// Cumulative pips over time.
class EquityChart extends StatelessWidget {
  final List<EquityPoint> points;
  final double height;
  const EquityChart({super.key, required this.points, this.height = 160});

  @override
  Widget build(BuildContext context) {
    if (points.length < 2) {
      return SizedBox(
          height: height,
          child: const Center(
              child: Text('Not enough closed signals yet', style: AppText.caption)));
    }
    return SizedBox(
        height: height,
        width: double.infinity,
        child: CustomPaint(painter: _EquityPainter(points)));
  }
}

class _EquityPainter extends CustomPainter {
  final List<EquityPoint> pts;
  _EquityPainter(this.pts);

  @override
  void paint(Canvas canvas, Size size) {
    final vals = [0.0, ...pts.map((p) => p.pips)];
    final lo = vals.reduce(math.min), hi = vals.reduce(math.max);
    final range = hi - lo == 0 ? 1 : hi - lo;
    const labelW = 44.0;
    final w = size.width - labelW;
    double y(double v) => 8 + (size.height - 16) * (hi - v) / range;
    double x(int i) => w * i / (vals.length - 1);

    // zero line + labels
    final zero = y(0);
    canvas.drawLine(Offset(0, zero), Offset(w, zero),
        Paint()..color = Colors.white.withValues(alpha: 0.12));
    void label(double v) {
      final tp = TextPainter(
          text: TextSpan(text: Fmt.pips(v), style: const TextStyle(fontSize: 9.5, color: AppColors.text3)),
          textDirection: TextDirection.ltr)
        ..layout();
      tp.paint(canvas, Offset(w + 6, y(v) - tp.height / 2));
    }

    label(hi);
    if (lo < 0) label(lo);

    final line = Path();
    for (var i = 0; i < vals.length; i++) {
      i == 0 ? line.moveTo(x(i), y(vals[i])) : line.lineTo(x(i), y(vals[i]));
    }
    final positive = vals.last >= 0;
    final color = positive ? AppColors.buy : AppColors.sell;
    final fill = Path.from(line)
      ..lineTo(w, zero)
      ..lineTo(0, zero)
      ..close();
    canvas.drawPath(
        fill,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [color.withValues(alpha: 0.28), color.withValues(alpha: 0.0)],
          ).createShader(Rect.fromLTWH(0, 0, w, size.height)));
    canvas.drawPath(
        line,
        Paint()
          ..color = color
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2
          ..strokeJoin = StrokeJoin.round);
    canvas.drawCircle(Offset(x(vals.length - 1), y(vals.last)), 3.5, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _EquityPainter old) => old.pts != pts;
}

/// Monthly pips bars.
class MonthlyBars extends StatelessWidget {
  final List<MonthStat> months;
  const MonthlyBars({super.key, required this.months});

  @override
  Widget build(BuildContext context) {
    final list = months.length > 6 ? months.sublist(months.length - 6) : months;
    if (list.isEmpty) return const SizedBox.shrink();
    final maxAbs = list.map((m) => m.pips.abs()).reduce(math.max);
    return SizedBox(
      height: 150,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (final m in list)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 5),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    Text(Fmt.pips(m.pips),
                        style: AppText.mono(10.5,
                            color: m.pips >= 0 ? AppColors.buy : AppColors.sell)),
                    const SizedBox(height: 6),
                    TweenAnimationBuilder<double>(
                      tween: Tween(begin: 0, end: maxAbs == 0 ? 0 : m.pips.abs() / maxAbs),
                      duration: const Duration(milliseconds: 700),
                      curve: Curves.easeOutCubic,
                      builder: (_, v, __) => Container(
                        height: math.max(4.0, 90 * v),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(6),
                          gradient: LinearGradient(
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                            colors: m.pips >= 0
                                ? [AppColors.buy, AppColors.buy.withValues(alpha: 0.35)]
                                : [AppColors.sell, AppColors.sell.withValues(alpha: 0.35)],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(Fmt.monthLabel(m.month), style: AppText.label),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Horizontal track SL — Entry — TP1 — TP2 with the current price marker.
class LevelTrack extends StatelessWidget {
  final TradeSignal s;
  const LevelTrack({super.key, required this.s});

  @override
  Widget build(BuildContext context) {
    final pos = s.isRunning ? (s.currentPrice ?? s.entry) : (s.closePrice ?? s.entry);
    // Normalise along the trade direction: SL = 0, TP2 = 1.
    double t(double p) => ((p - s.stopLoss) / (s.tp2 - s.stopLoss)).clamp(0.0, 1.0);
    final entryT = t(s.entry), tp1T = t(s.tp1), curT = t(pos);
    return LayoutBuilder(builder: (context, c) {
      final w = c.maxWidth;
      return SizedBox(
        height: 64,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            Positioned(
              left: 0,
              right: 0,
              top: 22,
              child: Container(
                height: 8,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(8),
                  gradient: LinearGradient(
                    colors: [AppColors.sell, AppColors.sell.withValues(alpha: 0.3), AppColors.buy.withValues(alpha: 0.3), AppColors.buy],
                    stops: [0, entryT, entryT, 1],
                  ),
                ),
              ),
            ),
            for (final m in [
              (0.0, 'SL', AppColors.sell),
              (entryT, 'Entry', AppColors.gold),
              (tp1T, 'TP1', AppColors.buy),
              (1.0, 'TP2', AppColors.buy),
            ])
              Positioned(
                left: (w * m.$1 - 20).clamp(0.0, w - 40),
                top: 42,
                width: 40,
                child: Text(m.$2,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 10.5, color: m.$3, fontWeight: FontWeight.w700)),
              ),
            for (final m in [entryT, tp1T])
              Positioned(
                left: w * m - 1,
                top: 18,
                child: Container(width: 2, height: 16, color: AppColors.text.withValues(alpha: 0.6)),
              ),
            AnimatedPositioned(
              duration: const Duration(milliseconds: 600),
              curve: Curves.easeOutCubic,
              left: (w * curT - 9).clamp(-4.0, w - 14),
              top: 17,
              child: Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.text,
                  border: Border.all(color: AppColors.bg, width: 3),
                  boxShadow: [BoxShadow(color: AppColors.text.withValues(alpha: 0.4), blurRadius: 10)],
                ),
              ),
            ),
            Positioned(
              left: (w * curT - 40).clamp(0.0, w - 80),
              top: 0,
              width: 80,
              child: Text(s.isRunning ? 'Now' : 'Closed',
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 10.5, color: AppColors.text2)),
            ),
          ],
        ),
      );
    });
  }
}
