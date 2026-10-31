import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/api.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';

/// Shown right after sign-up, and from Profile/Notifications while unverified.
class VerifyEmailScreen extends StatefulWidget {
  final bool afterSignup;
  const VerifyEmailScreen({super.key, this.afterSignup = false});

  @override
  State<VerifyEmailScreen> createState() => _VerifyEmailScreenState();
}

class _VerifyEmailScreenState extends State<VerifyEmailScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  bool _resending = false;
  String? _info;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  void _close() => Navigator.of(context).popUntil((r) => r.isFirst);

  Future<void> _verify() async {
    if (!RegExp(r'^\d{6}$').hasMatch(_code.text.trim())) {
      showSnack(context, 'Enter the 6-digit code from the email.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      await context.read<SessionStore>().verifyEmail(_code.text);
      if (!mounted) return;
      showSnack(context, 'Email verified. Email alerts are on.');
      _close();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() => _resending = true);
    try {
      final msg = await context.read<SessionStore>().resendVerification();
      if (mounted) setState(() => _info = msg);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final email = context.watch<SessionStore>().user?.email ?? 'your email';
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !widget.afterSignup,
        actions: [
          if (widget.afterSignup)
            TextButton(
                onPressed: _close,
                child: const Text('Skip for now', style: TextStyle(color: AppColors.text2))),
        ],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
          children: [
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                color: AppColors.gold.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppColors.gold.withValues(alpha: 0.3)),
              ),
              child: const Icon(Icons.mark_email_unread_outlined, color: AppColors.gold, size: 32),
            ),
            const SizedBox(height: 24),
            const Text('Verify your email', style: AppText.h1),
            const SizedBox(height: 8),
            Text(
              'We sent a 6-digit code to $email. Verifying lets us email you new signals, '
              'trade results and your daily, weekly and monthly reports.',
              style: AppText.body.copyWith(fontSize: 15),
            ),
            const SizedBox(height: 24),
            if (_info != null) ...[
              InfoBanner(icon: Icons.mark_email_read_outlined, text: _info!),
              const SizedBox(height: 16),
            ],
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              autofocus: true,
              textAlign: TextAlign.center,
              style: AppText.mono(26, weight: FontWeight.w800).copyWith(letterSpacing: 10),
              decoration: const InputDecoration(counterText: '', hintText: '••••••'),
              onSubmitted: (_) => _verify(),
            ),
            const SizedBox(height: 20),
            GoldButton(label: 'Verify email', loading: _busy, onPressed: _verify),
            const SizedBox(height: 8),
            Center(
              child: TextButton(
                onPressed: _resending ? null : _resend,
                child: Text(_resending ? 'Sending…' : 'Didn\'t get it? Send a new code',
                    style: const TextStyle(color: AppColors.gold)),
              ),
            ),
            const SizedBox(height: 8),
            const Text('Check your spam folder if it hasn\'t arrived within a minute.',
                textAlign: TextAlign.center, style: AppText.label),
          ],
        ),
      ),
    );
  }
}
