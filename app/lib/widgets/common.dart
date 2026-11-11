import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/theme.dart';

class GlassCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Gradient? gradient;
  final Color? borderColor;
  final double radius;

  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.onTap,
    this.gradient,
    this.borderColor,
    this.radius = 20,
  });

  @override
  Widget build(BuildContext context) {
    final r = BorderRadius.circular(radius);
    return Container(
      decoration: BoxDecoration(
        gradient: gradient ?? AppColors.cardGradient,
        borderRadius: r,
        border: Border.all(color: borderColor ?? AppColors.border),
      ),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: r,
          onTap: onTap,
          splashColor: AppColors.gold.withValues(alpha: 0.06),
          highlightColor: Colors.white.withValues(alpha: 0.02),
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

class GoldButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final bool loading;
  final IconData? icon;

  const GoldButton(
      {super.key,
      required this.label,
      required this.onPressed,
      this.loading = false,
      this.icon});

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null && !loading;
    return AnimatedOpacity(
      duration: const Duration(milliseconds: 150),
      opacity: enabled || loading ? 1 : 0.5,
      child: Container(
        height: 54,
        decoration: BoxDecoration(
          gradient: AppColors.goldGradient,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
                color: AppColors.gold.withValues(alpha: 0.25),
                blurRadius: 24,
                offset: const Offset(0, 8)),
          ],
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: enabled ? onPressed : null,
            child: Center(
              child: loading
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(
                          strokeWidth: 2.4, color: Color(0xFF1A1204)))
                  : Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (icon != null) ...[
                          Icon(icon, color: const Color(0xFF1A1204), size: 20),
                          const SizedBox(width: 8),
                        ],
                        Text(label,
                            style: const TextStyle(
                                color: Color(0xFF1A1204),
                                fontSize: 16,
                                fontWeight: FontWeight.w700)),
                      ],
                    ),
            ),
          ),
        ),
      ),
    );
  }
}

class OutlineButton2 extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color color;
  const OutlineButton2(
      {super.key,
      required this.label,
      required this.onPressed,
      this.icon,
      this.color = AppColors.text});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 50,
      child: OutlinedButton.icon(
        onPressed: onPressed,
        icon: icon == null ? const SizedBox.shrink() : Icon(icon, size: 18, color: color),
        label: Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w600)),
        style: OutlinedButton.styleFrom(
          side: const BorderSide(color: AppColors.border),
          backgroundColor: AppColors.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
    );
  }
}

const _flags = {
  'EUR': '🇪🇺',
  'USD': '🇺🇸',
  'GBP': '🇬🇧',
  'JPY': '🇯🇵',
  'AUD': '🇦🇺',
  'CAD': '🇨🇦',
  'CHF': '🇨🇭',
  'NZD': '🇳🇿',
};

class PairAvatar extends StatelessWidget {
  final String pair;
  final double size;
  const PairAvatar({super.key, required this.pair, this.size = 40});

  Widget _coin(String code) {
    final flag = _flags[code];
    final isGold = code == 'XAU' || code == 'BTC' || code == 'ETH';
    return Container(
      width: size * 0.72,
      height: size * 0.72,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: isGold ? AppColors.goldGradient : null,
        color: isGold ? null : AppColors.surface2,
        border: Border.all(color: AppColors.bg, width: 2),
      ),
      alignment: Alignment.center,
      child: flag != null
          ? Text(flag, style: TextStyle(fontSize: size * 0.36, height: 1.1))
          : Text(const {'XAU': 'Au', 'BTC': '₿', 'ETH': 'Ξ'}[code] ?? code.substring(0, 1),
              style: TextStyle(
                  fontSize: size * 0.26,
                  fontWeight: FontWeight.w800,
                  color: isGold ? const Color(0xFF1A1204) : AppColors.text)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final parts = pair.split('/');
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        children: [
          Positioned(left: 0, top: 0, child: _coin(parts.first)),
          Positioned(right: 0, bottom: 0, child: _coin(parts.last)),
        ],
      ),
    );
  }
}

class Pill extends StatelessWidget {
  final String text;
  final Color color;
  final IconData? icon;
  final bool filled;
  const Pill(
      {super.key,
      required this.text,
      required this.color,
      this.icon,
      this.filled = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: filled ? color : color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(30),
        border: Border.all(color: color.withValues(alpha: filled ? 1 : 0.35)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 13, color: filled ? AppColors.bg : color),
            const SizedBox(width: 4),
          ],
          Text(text,
              style: TextStyle(
                  color: filled ? AppColors.bg : color,
                  fontSize: 11.5,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0.6)),
        ],
      ),
    );
  }
}

class DirectionPill extends StatelessWidget {
  final bool isBuy;
  const DirectionPill({super.key, required this.isBuy});

  @override
  Widget build(BuildContext context) => Pill(
        text: isBuy ? 'BUY' : 'SELL',
        color: isBuy ? AppColors.buy : AppColors.sell,
        icon: isBuy ? Icons.north_east_rounded : Icons.south_east_rounded,
      );
}

class SectionHeader extends StatelessWidget {
  final String title;
  final String? action;
  final VoidCallback? onAction;
  const SectionHeader(this.title, {super.key, this.action, this.onAction});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12, top: 4),
      child: Row(
        children: [
          Text(title, style: AppText.title),
          const Spacer(),
          if (action != null)
            GestureDetector(
              onTap: onAction,
              child: Text(action!,
                  style: const TextStyle(
                      color: AppColors.gold,
                      fontWeight: FontWeight.w600,
                      fontSize: 13)),
            ),
        ],
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final Widget? action;
  const EmptyState(
      {super.key,
      required this.icon,
      required this.title,
      required this.message,
      this.action});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 24),
      child: Column(
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.gold.withValues(alpha: 0.08),
              border: Border.all(color: AppColors.gold.withValues(alpha: 0.25)),
            ),
            child: Icon(icon, color: AppColors.gold, size: 32),
          ),
          const SizedBox(height: 16),
          Text(title, style: AppText.title, textAlign: TextAlign.center),
          const SizedBox(height: 6),
          Text(message, style: AppText.body, textAlign: TextAlign.center),
          if (action != null) ...[const SizedBox(height: 16), action!],
        ],
      ),
    );
  }
}

/// Pulsing placeholder used while content loads.
class Skeleton extends StatefulWidget {
  final double height;
  final double radius;
  const Skeleton({super.key, this.height = 120, this.radius = 20});

  @override
  State<Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<Skeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
      vsync: this, duration: const Duration(milliseconds: 1100))
    ..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, __) => Container(
        height: widget.height,
        decoration: BoxDecoration(
          color: Color.lerp(AppColors.surface, AppColors.surface2, _c.value),
          borderRadius: BorderRadius.circular(widget.radius),
          border: Border.all(color: AppColors.border),
        ),
      ),
    );
  }
}

class InfoBanner extends StatelessWidget {
  final IconData icon;
  final String text;
  final Color color;
  final VoidCallback? onTap;
  const InfoBanner(
      {super.key,
      required this.icon,
      required this.text,
      this.color = AppColors.info,
      this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withValues(alpha: 0.25)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color, size: 18),
            const SizedBox(width: 10),
            Expanded(
                child: Text(text,
                    style: const TextStyle(
                        fontSize: 12.5, color: AppColors.text2, height: 1.4))),
          ],
        ),
      ),
    );
  }
}

class DemoBanner extends StatelessWidget {
  const DemoBanner({super.key});

  @override
  Widget build(BuildContext context) {
    if (!AppConfig.isDemo) return const SizedBox.shrink();
    return const Padding(
      padding: EdgeInsets.only(bottom: 14),
      child: InfoBanner(
        icon: Icons.science_outlined,
        color: AppColors.warn,
        text: 'Demo mode — prices and results are simulated. Connect your server '
            '(API_URL) to show live signals and your real track record.',
      ),
    );
  }
}

class LogoMark extends StatelessWidget {
  final double size;
  const LogoMark({super.key, this.size = 64});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: AppColors.goldGradient,
        borderRadius: BorderRadius.circular(size * 0.3),
        boxShadow: [
          BoxShadow(
              color: AppColors.gold.withValues(alpha: 0.35),
              blurRadius: size * 0.5,
              offset: Offset(0, size * 0.12)),
        ],
      ),
      child: Icon(Icons.candlestick_chart_rounded,
          size: size * 0.56, color: const Color(0xFF1A1204)),
    );
  }
}

void showSnack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Row(
        children: [
          Icon(error ? Icons.error_outline : Icons.check_circle_outline,
              color: error ? AppColors.sell : AppColors.buy, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(message)),
        ],
      ),
    ));
}
