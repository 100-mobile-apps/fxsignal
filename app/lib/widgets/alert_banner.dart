import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../screens/verify_email_screen.dart';
import '../state/session_store.dart';
import 'common.dart';

/// One banner on the Signals tab when the user would miss alerts:
/// push blocked on this phone first, otherwise email not verified.
/// Shows nothing when both are fine.
class AlertBanner extends StatefulWidget {
  const AlertBanner({super.key});

  @override
  State<AlertBanner> createState() => _AlertBannerState();
}

class _AlertBannerState extends State<AlertBanner> with WidgetsBindingObserver {
  bool? _pushAllowed;
  bool _asked = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _check();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // The user may have turned notifications on in phone settings; check again on return.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _check();
  }

  Future<void> _check() async {
    final push = context.read<SessionStore>().push;
    if (!push.available) return;
    try {
      final ok = await push.permissionGranted();
      if (mounted) setState(() => _pushAllowed = ok);
    } catch (_) {}
  }

  Future<void> _allow() async {
    final push = context.read<SessionStore>().push;
    try {
      final ok = await push.requestPermission();
      if (mounted) {
        setState(() {
          _pushAllowed = ok;
          _asked = true;
        });
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final verified = context.select<SessionStore, bool>((s) => s.user?.emailVerified ?? true);

    final Widget? banner;
    if (_pushAllowed == false) {
      banner = InfoBanner(
        icon: Icons.notifications_off_outlined,
        color: AppColors.warn,
        text: _asked
            ? 'Notifications are still off. Turn them on in your phone\'s Settings for this app, '
                'or you will miss new signals and stop-loss moves.'
            : 'Notifications are off on this phone, so you will miss new signals and stop-loss moves. '
                'Tap to turn them on.',
        onTap: _allow,
      );
    } else if (!verified) {
      banner = InfoBanner(
        icon: Icons.mark_email_unread_outlined,
        color: AppColors.warn,
        text: 'Your email is not verified, so signal emails and reports are not being sent. Tap to verify.',
        onTap: () => Navigator.of(context)
            .push(MaterialPageRoute(builder: (_) => const VerifyEmailScreen())),
      );
    } else {
      banner = null;
    }

    if (banner == null) return const SizedBox.shrink();
    return Padding(padding: const EdgeInsets.only(bottom: 14), child: banner);
  }
}
