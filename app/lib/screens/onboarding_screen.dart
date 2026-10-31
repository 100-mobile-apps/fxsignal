import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0.8, end: 1),
          duration: const Duration(milliseconds: 700),
          curve: Curves.easeOutBack,
          builder: (_, v, child) => Transform.scale(scale: v, child: child),
          child: const Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              LogoMark(size: 84),
              SizedBox(height: 22),
              Text('Forex Signals Live', style: AppText.h2),
              SizedBox(height: 6),
              Text('Trade with a plan', style: AppText.caption),
            ],
          ),
        ),
      ),
    );
  }
}

class _Slide {
  final IconData icon;
  final String title;
  final String body;
  const _Slide(this.icon, this.title, this.body);
}

const _slides = [
  _Slide(Icons.bolt_rounded, 'Signals from 8 major markets',
      'EUR/USD, GBP/USD, Gold and more are scanned around the clock. Every signal comes with entry, stop loss and two take-profit targets.'),
  _Slide(Icons.verified_outlined, 'A track record you can check',
      'Every signal is recorded automatically and closed by the rules — TP or SL, win or loss. Nothing is hidden or deleted.'),
  _Slide(Icons.notifications_active_outlined, 'Never miss a move',
      'Get instant alerts for new signals and when targets or stops are hit. Choose exactly which pairs you follow.'),
];

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _page = PageController();
  int _index = 0;

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  void _next() {
    if (_index == _slides.length - 1) {
      context.read<SessionStore>().completeOnboarding();
    } else {
      _page.nextPage(duration: const Duration(milliseconds: 350), curve: Curves.easeOutCubic);
    }
  }

  @override
  Widget build(BuildContext context) {
    final last = _index == _slides.length - 1;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 8, 12, 0),
              child: Row(
                children: [
                  const LogoMark(size: 34),
                  const SizedBox(width: 10),
                  const Text('Forex Signals Live', style: AppText.title),
                  const Spacer(),
                  if (!last)
                    TextButton(
                      onPressed: () => context.read<SessionStore>().completeOnboarding(),
                      child: const Text('Skip', style: TextStyle(color: AppColors.text2)),
                    ),
                ],
              ),
            ),
            Expanded(
              child: PageView.builder(
                controller: _page,
                itemCount: _slides.length,
                onPageChanged: (i) => setState(() => _index = i),
                itemBuilder: (_, i) => _SlideView(slide: _slides[i], index: i),
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                for (var i = 0; i < _slides.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    width: i == _index ? 26 : 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: i == _index ? AppColors.gold : AppColors.border,
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
              child: GoldButton(
                label: last ? 'Get started' : 'Next',
                icon: last ? Icons.arrow_forward_rounded : null,
                onPressed: _next,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SlideView extends StatelessWidget {
  final _Slide slide;
  final int index;
  const _SlideView({required this.slide, required this.index});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 28),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          SizedBox(
            width: 220,
            height: 220,
            child: Stack(
              alignment: Alignment.center,
              children: [
                for (final r in [220.0, 170.0, 120.0])
                  Container(
                    width: r,
                    height: r,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: AppColors.gold.withValues(alpha: 0.08 + (220 - r) / 1000)),
                      gradient: RadialGradient(colors: [
                        AppColors.gold.withValues(alpha: 0.10),
                        Colors.transparent,
                      ]),
                    ),
                  ),
                Container(
                  width: 92,
                  height: 92,
                  decoration: BoxDecoration(
                    gradient: AppColors.goldGradient,
                    borderRadius: BorderRadius.circular(28),
                    boxShadow: [
                      BoxShadow(color: AppColors.gold.withValues(alpha: 0.35), blurRadius: 40),
                    ],
                  ),
                  child: Icon(slide.icon, size: 46, color: const Color(0xFF1A1204)),
                ),
              ],
            ),
          ),
          const SizedBox(height: 40),
          Text(slide.title, style: AppText.h1.copyWith(fontSize: 26), textAlign: TextAlign.center),
          const SizedBox(height: 14),
          Text(slide.body,
              style: AppText.body.copyWith(fontSize: 15), textAlign: TextAlign.center),
        ],
      ),
    );
  }
}
