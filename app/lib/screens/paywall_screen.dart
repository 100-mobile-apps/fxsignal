import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../core/theme.dart';
import '../state/billing_store.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import 'legal_screen.dart';

/// Opens the paywall. Returns true if the user is premium when it closes.
Future<bool> openPaywall(BuildContext context) async {
  final r = await Navigator.of(context).push<bool>(
    MaterialPageRoute(fullscreenDialog: true, builder: (_) => const PaywallScreen()),
  );
  return r ?? false;
}

class PaywallScreen extends StatefulWidget {
  const PaywallScreen({super.key});

  @override
  State<PaywallScreen> createState() => _PaywallScreenState();
}

class _PaywallScreenState extends State<PaywallScreen> {
  Plan _plan = Plan.yearly;
  bool _closing = false;

  @override
  void initState() {
    super.initState();
    final billing = context.read<BillingStore>();
    if (billing.offers.isEmpty && !billing.loading) billing.loadOffers();
  }

  @override
  Widget build(BuildContext context) {
    final billing = context.watch<BillingStore>();
    final premium = context.select<SessionStore, bool>((s) => s.user?.premium ?? false);
    if (premium && !_closing) {
      _closing = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Premium is active. Live signals are unlocked.')));
        Navigator.of(context).pop(true);
      });
    }

    final yearly = billing.offers[Plan.yearly];
    final monthly = billing.offers[Plan.monthly];
    final yearPrice = yearly?.price ?? r'$36';
    final monthPrice = monthly?.price ?? r'$5';
    final perMonth = yearly?.perMonth ?? r'$3';
    var savePct = 40;
    if (yearly != null && monthly != null && monthly.base.rawPrice > 0) {
      savePct = (100 * (1 - yearly.base.rawPrice / (monthly.base.rawPrice * 12))).round();
    }
    final selected = _plan == Plan.yearly ? yearly : monthly;
    final trial = selected?.hasFreeTrial ?? AppConfig.isDemo;
    final regularAfter = _plan == Plan.yearly ? '$yearPrice/year' : '$monthPrice/month';
    final intro = selected?.introPrice;
    final priceAfter = intro == null
        ? regularAfter
        : _plan == Plan.yearly
            ? '$intro for the first year, then $regularAfter'
            : '$intro/month for ${selected!.introCycles} months, then $regularAfter';

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(0, -1.1),
            radius: 1.2,
            colors: [Color(0xFF2A2110), AppColors.bg],
            stops: [0, 0.55],
          ),
        ),
        child: SafeArea(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 8, 22, 22),
            children: [
              Row(
                children: [
                  IconButton(
                    tooltip: 'Close',
                    onPressed: () => Navigator.of(context).pop(false),
                    style: IconButton.styleFrom(backgroundColor: AppColors.surface2),
                    icon: const Icon(Icons.close_rounded, color: AppColors.text2),
                  ),
                  const Spacer(),
                  TextButton(
                    onPressed: billing.busy ? null : billing.restore,
                    child: const Text('Restore purchase', style: TextStyle(color: AppColors.text2)),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Center(
                child: Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                      gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(20)),
                  child: const Icon(Icons.workspace_premium_rounded, size: 36, color: Color(0xFF1A1204)),
                ),
              ),
              const SizedBox(height: 14),
              const Text('Unlock live signals', textAlign: TextAlign.center, style: AppText.h1),
              const SizedBox(height: 8),
              const Text(
                'Get the exact entry, stop loss and take profit the moment each signal is published.',
                textAlign: TextAlign.center,
                style: AppText.body,
              ),
              const SizedBox(height: 20),
              for (final perk in const [
                'Live entry, stop loss, TP1 and TP2',
                'Instant push and email alerts with full levels',
                'Why-this-signal reasons and chart levels',
                'Gold, Bitcoin and Ethereum signals',
                'Strongest forex setups (above 70% strength)',
                'Trading bot: auto-pilot, copy-trade and Deriv',
              ])
                Padding(
                  padding: const EdgeInsets.only(bottom: 10, left: 4),
                  child: Row(children: [
                    Container(
                      width: 22,
                      height: 22,
                      decoration: BoxDecoration(
                          color: AppColors.buy.withValues(alpha: 0.14), shape: BoxShape.circle),
                      child: const Icon(Icons.check_rounded, size: 14, color: AppColors.buy),
                    ),
                    const SizedBox(width: 10),
                    Expanded(child: Text(perk, style: const TextStyle(fontSize: 14))),
                  ]),
                ),
              const SizedBox(height: 12),
              _PlanTile(
                selected: _plan == Plan.yearly,
                title: 'Yearly',
                badge: yearly?.introPrice != null ? 'LAUNCH OFFER' : (savePct > 0 ? 'SAVE $savePct%' : null),
                subtitle: yearly?.introPrice != null
                    ? 'First year, then $yearPrice/year'
                    : 'Just $perMonth/month, billed $yearPrice yearly',
                price: yearly?.introPrice ?? yearPrice,
                note: yearly?.introPrice != null
                    ? yearPrice
                    : monthly != null
                        ? '${monthly.base.currencySymbol}${(monthly.base.rawPrice * 12).toStringAsFixed(0)}'
                        : r'$60',
                strike: true,
                onTap: () => setState(() => _plan = Plan.yearly),
              ),
              const SizedBox(height: 10),
              _PlanTile(
                selected: _plan == Plan.monthly,
                title: 'Monthly',
                badge: monthly?.introPrice != null ? 'LAUNCH OFFER' : null,
                subtitle: monthly?.introPrice != null
                    ? 'For ${monthly!.introCycles} months, then $monthPrice/month'
                    : 'Billed every month',
                price: monthly?.introPrice ?? monthPrice,
                note: monthly?.introPrice != null ? monthPrice : 'per month',
                strike: monthly?.introPrice != null,
                onTap: () => setState(() => _plan = Plan.monthly),
              ),
              const SizedBox(height: 18),
              if (billing.error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: InfoBanner(icon: Icons.error_outline_rounded, color: AppColors.sell, text: billing.error!),
                ),
              if (billing.notice != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: InfoBanner(icon: Icons.info_outline_rounded, color: AppColors.gold, text: billing.notice!),
                ),
              GoldButton(
                label: trial ? 'Start 7-day free trial' : 'Subscribe',
                loading: billing.busy || billing.loading,
                onPressed: (billing.available || AppConfig.isDemo) ? () => billing.buy(_plan) : null,
              ),
              const SizedBox(height: 10),
              Text(
                trial
                    ? 'Free for 7 days, then $priceAfter. Cancel anytime in Google Play.'
                    : '$priceAfter. Renews automatically. Cancel anytime in Google Play.',
                textAlign: TextAlign.center,
                style: AppText.caption,
              ),
              const SizedBox(height: 6),
              Wrap(
                alignment: WrapAlignment.center,
                children: [
                  TextButton(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => const LegalScreen(doc: LegalDoc.terms))),
                    child: const Text('Terms', style: TextStyle(fontSize: 12, color: AppColors.text2)),
                  ),
                  TextButton(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => const LegalScreen(doc: LegalDoc.privacy))),
                    child: const Text('Privacy', style: TextStyle(fontSize: 12, color: AppColors.text2)),
                  ),
                  TextButton(
                    onPressed: billing.manage,
                    child: const Text('Manage subscription', style: TextStyle(fontSize: 12, color: AppColors.text2)),
                  ),
                ],
              ),
              const Text('Results, reports, prices and tools stay free.',
                  textAlign: TextAlign.center, style: TextStyle(fontSize: 11.5, color: AppColors.text3)),
            ],
          ),
        ),
      ),
    );
  }
}

class _PlanTile extends StatelessWidget {
  final bool selected;
  final String title, subtitle, price, note;
  final String? badge;
  final bool strike;
  final VoidCallback onTap;
  const _PlanTile({
    required this.selected,
    required this.title,
    required this.subtitle,
    required this.price,
    required this.note,
    required this.onTap,
    this.badge,
    this.strike = false,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: selected,
      button: true,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          decoration: BoxDecoration(
            color: selected ? AppColors.gold.withValues(alpha: 0.08) : AppColors.surface,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: selected ? AppColors.gold : AppColors.border, width: 1.5),
          ),
          child: Row(
            children: [
              Container(
                width: 20,
                height: 20,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: selected ? AppColors.gold : AppColors.text3, width: 2),
                ),
                alignment: Alignment.center,
                child: selected
                    ? Container(
                        width: 10,
                        height: 10,
                        decoration: const BoxDecoration(color: AppColors.gold, shape: BoxShape.circle))
                    : null,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Text(title, style: AppText.title),
                      if (badge != null) ...[
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                              color: AppColors.gold, borderRadius: BorderRadius.circular(30)),
                          child: Text(badge!,
                              style: const TextStyle(
                                  fontSize: 10.5, fontWeight: FontWeight.w800, color: Color(0xFF1A1204))),
                        ),
                      ],
                    ]),
                    const SizedBox(height: 3),
                    Text(subtitle, style: AppText.caption.copyWith(fontSize: 12.5)),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(price, style: AppText.mono(18, weight: FontWeight.w800)),
                  if (note.isNotEmpty)
                    Text(note,
                        style: TextStyle(
                            fontSize: 11.5,
                            color: AppColors.text2,
                            decoration: strike ? TextDecoration.lineThrough : null)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
