import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../core/theme.dart';
import '../data/api.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import 'legal_screen.dart';
import 'verify_email_screen.dart';

String? validateEmail(String? v) {
  final s = (v ?? '').trim();
  if (s.isEmpty) return 'Enter your email';
  if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s)) return 'Enter a valid email';
  return null;
}

String? validateNewPassword(String? v) {
  final s = v ?? '';
  if (s.length < 8) return 'At least 8 characters';
  if (!RegExp(r'[A-Za-z]').hasMatch(s) || !RegExp(r'\d').hasMatch(s)) {
    return 'Use letters and numbers';
  }
  return null;
}

class PasswordField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  final String? Function(String?)? validator;
  final TextInputAction action;
  final VoidCallback? onSubmitted;
  const PasswordField(
      {super.key,
      required this.controller,
      this.label = 'Password',
      this.validator,
      this.action = TextInputAction.done,
      this.onSubmitted});

  @override
  State<PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<PasswordField> {
  bool _hide = true;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: widget.controller,
      obscureText: _hide,
      autocorrect: false,
      enableSuggestions: false,
      textInputAction: widget.action,
      onFieldSubmitted: (_) => widget.onSubmitted?.call(),
      validator: widget.validator ?? (v) => (v ?? '').isEmpty ? 'Enter your password' : null,
      decoration: InputDecoration(
        labelText: widget.label,
        prefixIcon: const Icon(Icons.lock_outline_rounded, color: AppColors.text3),
        suffixIcon: IconButton(
          icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined,
              color: AppColors.text3),
          onPressed: () => setState(() => _hide = !_hide),
        ),
      ),
    );
  }
}

class _AuthScaffold extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<Widget> children;
  final bool showBack;
  const _AuthScaffold(
      {required this.title,
      required this.subtitle,
      required this.children,
      this.showBack = false});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: showBack ? AppBar() : null,
      body: SafeArea(
        child: ListView(
          padding: EdgeInsets.fromLTRB(24, showBack ? 0 : 40, 24, 24),
          children: [
            if (!showBack) ...[
              const Align(alignment: Alignment.centerLeft, child: LogoMark(size: 56)),
              const SizedBox(height: 28),
            ],
            Text(title, style: AppText.h1),
            const SizedBox(height: 8),
            Text(subtitle, style: AppText.body.copyWith(fontSize: 15)),
            const SizedBox(height: 28),
            ...children,
          ],
        ),
      ),
    );
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _busy = true);
    try {
      await context.read<SessionStore>().login(_email.text, _password.text);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      title: 'Welcome back',
      subtitle: 'Log in to see today\'s signals and your alerts.',
      children: [
        if (AppConfig.isDemo)
          const Padding(
            padding: EdgeInsets.only(bottom: 18),
            child: InfoBanner(
              icon: Icons.science_outlined,
              color: AppColors.warn,
              text: 'Demo mode: any email and an 8+ character password will log you in.',
            ),
          ),
        Form(
          key: _form,
          child: Column(
            children: [
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                textInputAction: TextInputAction.next,
                validator: validateEmail,
                decoration: const InputDecoration(
                  labelText: 'Email',
                  prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.text3),
                ),
              ),
              const SizedBox(height: 14),
              PasswordField(controller: _password, onSubmitted: _submit),
            ],
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => ForgotPasswordScreen(initialEmail: _email.text))),
            child: const Text('Forgot password?', style: TextStyle(color: AppColors.gold)),
          ),
        ),
        const SizedBox(height: 8),
        GoldButton(label: 'Log in', loading: _busy, onPressed: _submit),
        const SizedBox(height: 24),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Text('New here? ', style: AppText.body),
            GestureDetector(
              onTap: () => Navigator.of(context)
                  .push(MaterialPageRoute(builder: (_) => const RegisterScreen())),
              child: const Text('Create an account',
                  style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w700)),
            ),
          ],
        ),
      ],
    );
  }
}

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _accepted = false;
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    if (!_accepted) {
      showSnack(context, 'Please accept the Terms and Risk Disclaimer.', error: true);
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => _busy = true);
    try {
      await context.read<SessionStore>().register(_name.text, _email.text, _password.text);
      if (mounted) {
        Navigator.of(context).pushReplacement(MaterialPageRoute(
            builder: (_) => const VerifyEmailScreen(afterSignup: true)));
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _openLegal(LegalDoc doc) => Navigator.of(context)
      .push(MaterialPageRoute(builder: (_) => LegalScreen(doc: doc)));

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      showBack: true,
      title: 'Create your account',
      subtitle: 'Free forever. Get signals, alerts and the full track record.',
      children: [
        Form(
          key: _form,
          child: Column(
            children: [
              TextFormField(
                controller: _name,
                textCapitalization: TextCapitalization.words,
                textInputAction: TextInputAction.next,
                validator: (v) => (v ?? '').trim().length < 2 ? 'Enter your name' : null,
                decoration: const InputDecoration(
                  labelText: 'Full name',
                  prefixIcon: Icon(Icons.person_outline_rounded, color: AppColors.text3),
                ),
              ),
              const SizedBox(height: 14),
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                textInputAction: TextInputAction.next,
                validator: validateEmail,
                decoration: const InputDecoration(
                  labelText: 'Email',
                  prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.text3),
                ),
              ),
              const SizedBox(height: 14),
              PasswordField(
                controller: _password,
                validator: validateNewPassword,
                onSubmitted: _submit,
              ),
            ],
          ),
        ),
        const SizedBox(height: 6),
        const Text('  At least 8 characters with letters and numbers.', style: AppText.label),
        const SizedBox(height: 18),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 28,
              height: 28,
              child: Checkbox(
                  value: _accepted,
                  onChanged: (v) => setState(() => _accepted = v ?? false)),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Wrap(
                children: [
                  const Text('I am 18+ and agree to the ', style: AppText.body),
                  GestureDetector(
                    onTap: () => _openLegal(LegalDoc.terms),
                    child: const Text('Terms',
                        style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w600)),
                  ),
                  const Text(', ', style: AppText.body),
                  GestureDetector(
                    onTap: () => _openLegal(LegalDoc.privacy),
                    child: const Text('Privacy Policy',
                        style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w600)),
                  ),
                  const Text(' and ', style: AppText.body),
                  GestureDetector(
                    onTap: () => _openLegal(LegalDoc.risk),
                    child: const Text('Risk Disclaimer',
                        style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w600)),
                  ),
                  const Text('.', style: AppText.body),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 24),
        GoldButton(label: 'Create account', loading: _busy, onPressed: _submit),
        const SizedBox(height: 20),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Text('Already have an account? ', style: AppText.body),
            GestureDetector(
              onTap: () => Navigator.of(context).pop(),
              child: const Text('Log in',
                  style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w700)),
            ),
          ],
        ),
      ],
    );
  }
}

class ForgotPasswordScreen extends StatefulWidget {
  final String initialEmail;
  const ForgotPasswordScreen({super.key, this.initialEmail = ''});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _form = GlobalKey<FormState>();
  late final _email = TextEditingController(text: widget.initialEmail);
  final _code = TextEditingController();
  final _password = TextEditingController();
  bool _codeSent = false;
  bool _busy = false;
  String? _info;

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _busy = true);
    final session = context.read<SessionStore>();
    try {
      if (!_codeSent) {
        final msg = await session.forgotPassword(_email.text);
        if (!mounted) return;
        setState(() {
          _codeSent = true;
          _info = msg;
        });
      } else {
        await session.resetPassword(_email.text, _code.text, _password.text);
        if (mounted) {
          Navigator.of(context).popUntil((r) => r.isFirst);
          showSnack(context, 'Password updated. You\'re logged in.');
        }
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      showBack: true,
      title: _codeSent ? 'Check your email' : 'Reset password',
      subtitle: _codeSent
          ? 'Enter the 6-digit code we sent to ${_email.text.trim()} and choose a new password.'
          : 'Enter the email you signed up with and we\'ll send you a reset code.',
      children: [
        if (_info != null) ...[
          InfoBanner(icon: Icons.mark_email_read_outlined, text: _info!),
          const SizedBox(height: 18),
        ],
        Form(
          key: _form,
          child: Column(
            children: [
              TextFormField(
                controller: _email,
                enabled: !_codeSent,
                keyboardType: TextInputType.emailAddress,
                validator: validateEmail,
                decoration: const InputDecoration(
                  labelText: 'Email',
                  prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.text3),
                ),
              ),
              if (_codeSent) ...[
                const SizedBox(height: 14),
                TextFormField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  validator: (v) =>
                      RegExp(r'^\d{6}$').hasMatch((v ?? '').trim()) ? null : 'Enter the 6-digit code',
                  style: AppText.mono(20, weight: FontWeight.w700).copyWith(letterSpacing: 6),
                  decoration: const InputDecoration(
                    labelText: 'Reset code',
                    counterText: '',
                    prefixIcon: Icon(Icons.pin_outlined, color: AppColors.text3),
                  ),
                ),
                const SizedBox(height: 14),
                PasswordField(
                  controller: _password,
                  label: 'New password',
                  validator: validateNewPassword,
                  onSubmitted: _submit,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 24),
        GoldButton(
          label: _codeSent ? 'Reset password' : 'Send code',
          loading: _busy,
          onPressed: _submit,
        ),
        if (_codeSent)
          Center(
            child: TextButton(
              onPressed: _busy
                  ? null
                  : () => setState(() {
                        _codeSent = false;
                        _info = null;
                      }),
              child: const Text('Didn\'t get it? Send again',
                  style: TextStyle(color: AppColors.text2)),
            ),
          ),
      ],
    );
  }
}
