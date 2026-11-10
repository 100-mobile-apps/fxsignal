import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:in_app_purchase/in_app_purchase.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/config.dart';
import '../data/api.dart';
import 'session_store.dart';

enum Plan { yearly, monthly }

/// One plan as shown on the paywall, built from Google Play's product details.
class PlanOffer {
  final Plan plan;

  /// What gets bought: the free-trial offer when the user is eligible, otherwise the base plan.
  final ProductDetails purchase;

  /// The paid base plan (regular recurring price, localised by Google, e.g. "$36.00").
  final ProductDetails base;
  final bool hasFreeTrial;
  const PlanOffer(this.plan, this.purchase, this.base, this.hasFreeTrial);

  String get price => base.price;

  /// Yearly price spread over 12 months, e.g. "$3.00".
  String get perMonth => '${base.currencySymbol}${(base.rawPrice / 12).toStringAsFixed(2)}';
}

/// Google Play subscriptions. The app only starts the purchase; the server
/// confirms every purchase with Google before premium is switched on.
class BillingStore extends ChangeNotifier {
  final Api api;
  final SessionStore session;
  BillingStore(this.api, this.session);

  final _iap = InAppPurchase.instance;
  StreamSubscription<List<PurchaseDetails>>? _sub;

  bool available = false;
  bool loading = false;
  bool busy = false;
  String? error;
  String? notice;
  Map<Plan, PlanOffer> offers = {};

  static String productId(Plan p) =>
      p == Plan.yearly ? AppConfig.yearlyProduct : AppConfig.monthlyProduct;

  Future<void> init() async {
    if (kIsWeb || AppConfig.isDemo) {
      available = AppConfig.isDemo;
      return;
    }
    try {
      available = await _iap.isAvailable();
    } catch (_) {
      available = false;
    }
    if (!available) return;
    _sub ??= _iap.purchaseStream.listen(_onPurchases, onError: (Object e) {
      busy = false;
      error = 'Google Play error: $e';
      notifyListeners();
    });
    await loadOffers();
  }

  Future<void> loadOffers() async {
    if (AppConfig.isDemo || !available) return;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final r = await _iap.queryProductDetails({AppConfig.monthlyProduct, AppConfig.yearlyProduct});
      final next = <Plan, PlanOffer>{};
      for (final plan in Plan.values) {
        // Google returns one entry per offer: the base plan and, if eligible, the free-trial offer.
        final entries = r.productDetails.where((d) => d.id == productId(plan)).toList();
        if (entries.isEmpty) continue;
        final paid = entries.where((d) => d.rawPrice > 0).toList()
          ..sort((a, b) => a.rawPrice.compareTo(b.rawPrice));
        final trial = entries.where((d) => d.rawPrice == 0).toList();
        final base = paid.isNotEmpty ? paid.first : entries.first;
        next[plan] = PlanOffer(plan, trial.isNotEmpty ? trial.first : base, base, trial.isNotEmpty);
      }
      offers = next;
      if (offers.isEmpty) {
        error = 'Subscriptions are not available right now. Please try again later.';
      }
    } catch (e) {
      error = 'Could not load subscription prices. Check your connection and try again.';
    }
    loading = false;
    notifyListeners();
  }

  /// Starts Google Play checkout. Returns true when premium is active (demo mode only — the
  /// real flow completes through [_onPurchases]).
  Future<bool> buy(Plan plan) async {
    error = null;
    notice = null;
    final user = session.user;
    if (user == null) return false;
    if (AppConfig.isDemo) {
      busy = true;
      notifyListeners();
      session.setUser(await api.verifyPurchase(productId(plan), 'demo'));
      busy = false;
      notifyListeners();
      return true;
    }
    final offer = offers[plan];
    if (offer == null) {
      error = 'This plan is not available right now. Please try again later.';
      notifyListeners();
      return false;
    }
    busy = true;
    notifyListeners();
    try {
      await _iap.buyNonConsumable(
        purchaseParam: PurchaseParam(
          productDetails: offer.purchase,
          // Ties the purchase to this account (obfuscatedAccountId); the server checks it.
          applicationUserName: user.billingAccountId,
        ),
      );
    } catch (e) {
      busy = false;
      error = 'Could not open Google Play checkout. Please try again.';
      notifyListeners();
    }
    return false;
  }

  Future<void> restore() async {
    error = null;
    notice = null;
    if (AppConfig.isDemo) return;
    final user = session.user;
    if (!available || user == null) {
      error = 'Google Play is not available on this device.';
      notifyListeners();
      return;
    }
    busy = true;
    notifyListeners();
    _restoreFound = false;
    try {
      await _iap.restorePurchases(applicationUserName: user.billingAccountId);
      // Restored purchases arrive on the purchase stream; give it a moment.
      await Future<void>.delayed(const Duration(seconds: 4));
      if (!_restoreFound && !(session.user?.premium ?? false)) {
        notice = 'No active subscription was found for this Google account.';
      }
    } catch (_) {
      error = 'Could not restore purchases. Please try again.';
    }
    busy = false;
    notifyListeners();
  }

  bool _restoreFound = false;

  Future<void> _onPurchases(List<PurchaseDetails> list) async {
    for (final p in list) {
      switch (p.status) {
        case PurchaseStatus.pending:
          busy = true;
          notice = 'Payment pending. Premium unlocks as soon as Google Play confirms it.';
          break;
        case PurchaseStatus.error:
          busy = false;
          error = p.error?.message.isNotEmpty == true
              ? 'Purchase failed: ${p.error!.message}'
              : 'Purchase failed. You have not been charged.';
          break;
        case PurchaseStatus.canceled:
          busy = false;
          break;
        case PurchaseStatus.purchased:
        case PurchaseStatus.restored:
          _restoreFound = true;
          await _verify(p);
          break;
      }
      if (p.pendingCompletePurchase && p.status != PurchaseStatus.purchased && p.status != PurchaseStatus.restored) {
        try {
          await _iap.completePurchase(p);
        } catch (_) {}
      }
    }
    notifyListeners();
  }

  Future<void> _verify(PurchaseDetails p) async {
    busy = true;
    notifyListeners();
    try {
      final token = p.verificationData.serverVerificationData;
      final user = await api.verifyPurchase(p.productID, token);
      session.setUser(user);
      // Acknowledge only after the server has accepted it, so a failed check can be retried.
      if (p.pendingCompletePurchase) await _iap.completePurchase(p);
      if (user.premium) notice = null;
    } on ApiException catch (e) {
      error = e.message;
    } catch (_) {
      error = 'Could not confirm your purchase. Use "Restore purchase" to try again.';
    }
    busy = false;
  }

  /// Opens Google Play's subscription page so the user can cancel or change plan.
  Future<void> manage() async {
    final sku = session.user?.premiumPlan == 'yearly' ? AppConfig.yearlyProduct : AppConfig.monthlyProduct;
    final uri = Uri.parse(
        'https://play.google.com/store/account/subscriptions?sku=$sku&package=${AppConfig.playPackage}');
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }
}
