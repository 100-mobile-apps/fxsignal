import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../data/api.dart';
import '../models/models.dart';
import '../services/push_service.dart';
import '../state/billing_store.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';
import 'auth_screens.dart';
import 'legal_screen.dart';
import 'paywall_screen.dart';
import 'verify_email_screen.dart';
import '../widgets/developer_widgets.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  void _push(BuildContext context, Widget page) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));

  Future<void> _logout(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: const Text('Log out?'),
        content: const Text('You will stop receiving signal alerts on this device.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Log out', style: TextStyle(color: AppColors.sell))),
        ],
      ),
    );
    if (ok == true && context.mounted) await context.read<SessionStore>().logout();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionStore>().user;
    if (user == null) return const SizedBox.shrink();

    return SafeArea(
      bottom: false,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
        children: [
          const Text('Profile', style: AppText.h1),
          const SizedBox(height: 18),
          GlassCard(
            padding: const EdgeInsets.all(18),
            child: Row(
              children: [
                Container(
                  width: 58,
                  height: 58,
                  decoration: const BoxDecoration(gradient: AppColors.goldGradient, shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: Text(Fmt.initials(user.name),
                      style: const TextStyle(
                          fontSize: 20, fontWeight: FontWeight.w800, color: Color(0xFF1A1204))),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(user.name, style: AppText.title.copyWith(fontSize: 18)),
                      const SizedBox(height: 2),
                      Text(user.email, style: AppText.caption),
                      const SizedBox(height: 6),
                      Text('Member since ${Fmt.date(user.createdAt)}', style: AppText.label),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.edit_outlined, color: AppColors.text2),
                  onPressed: () => _push(context, const EditProfileScreen()),
                ),
              ],
            ),
          ),
          if (!user.emailVerified) ...[
            const SizedBox(height: 12),
            InfoBanner(
              icon: Icons.mark_email_unread_outlined,
              color: AppColors.warn,
              text: 'Verify your email to receive signal alerts and daily, weekly and monthly reports by email. Tap to verify.',
              onTap: () => _push(context, const VerifyEmailScreen()),
            ),
          ],
          const SizedBox(height: 22),
          const _GroupTitle('Subscription'),
          _Group(children: [
            if (user.premium) ...[
              _Tile(Icons.workspace_premium_rounded, 'Premium · ${user.premiumPlan == 'yearly' ? 'Yearly' : user.premiumPlan == 'complimentary' ? 'Complimentary' : 'Monthly'}',
                  subtitle: user.premiumUntil == null
                      ? 'Live signals unlocked'
                      : 'Paid through ${Fmt.date(user.premiumUntil)}',
                  chevron: false),
              if (user.premiumPlan != 'complimentary')
                _Tile(Icons.manage_accounts_outlined, 'Manage subscription',
                    subtitle: 'Change plan or cancel in Google Play',
                    onTap: () => context.read<BillingStore>().manage()),
            ] else ...[
              _Tile(Icons.workspace_premium_rounded, 'Unlock live signals',
                  subtitle: '7-day free trial · \$5/month or \$36/year',
                  onTap: () => openPaywall(context)),
              _Tile(Icons.restore_rounded, 'Restore purchase',
                  subtitle: 'Already subscribed on this Google account?',
                  onTap: () async {
                    final billing = context.read<BillingStore>();
                    await billing.restore();
                    if (context.mounted) {
                      showSnack(context, billing.error ?? billing.notice ??
                          (context.read<SessionStore>().user?.premium == true
                              ? 'Premium restored.'
                              : 'Checked with Google Play.'));
                    }
                  }),
            ],
          ]),
          const _GroupTitle('Account'),
          _Group(children: [
            _Tile(Icons.notifications_none_rounded, 'Notifications',
                subtitle: 'Push ${user.notifications.signals ? 'on' : 'off'} · Email ${user.emailVerified ? (user.notifications.email.signals ? 'on' : 'off') : 'not verified'}',
                onTap: () => _push(context, const NotificationSettingsScreen())),
            _Tile(Icons.lock_outline_rounded, 'Change password',
                onTap: () => _push(context, const ChangePasswordScreen())),
          ]),
          const _GroupTitle('Learn'),
          _Group(children: [
            _Tile(Icons.auto_graph_rounded, 'How signals work',
                onTap: () => _push(context, const LegalScreen(doc: LegalDoc.methodology))),
            _Tile(Icons.shield_outlined, 'Risk disclaimer',
                onTap: () => _push(context, const LegalScreen(doc: LegalDoc.risk))),
          ]),
          const _GroupTitle('About'),
          _Group(children: [
            _Tile(Icons.support_agent_rounded, 'Contact support',
                subtitle: AppConfig.supportEmail, onTap: () {
              Clipboard.setData(const ClipboardData(text: AppConfig.supportEmail));
              showSnack(context, 'Support email copied');
            }),
            _Tile(Icons.description_outlined, 'Terms of use',
                onTap: () => _push(context, const LegalScreen(doc: LegalDoc.terms))),
            _Tile(Icons.privacy_tip_outlined, 'Privacy policy',
                onTap: () => _push(context, const LegalScreen(doc: LegalDoc.privacy))),
            _Tile(Icons.info_outline_rounded, 'Version',
                subtitle: '${AppConfig.version}${AppConfig.isDemo ? ' · demo' : ''}', chevron: false),
          ]),
          const _GroupTitle('Developer'),
          const DeveloperCard(),
          const SizedBox(height: 20),
          OutlineButton2(
              label: 'Log out',
              icon: Icons.logout_rounded,
              color: AppColors.sell,
              onPressed: () => _logout(context)),
          const SizedBox(height: 10),
          Center(
            child: TextButton(
              onPressed: () => showDialog<void>(
                  context: context, builder: (_) => const _DeleteAccountDialog()),
              child: const Text('Delete account', style: TextStyle(color: AppColors.text3)),
            ),
          ),
        ],
      ),
    );
  }
}

class _GroupTitle extends StatelessWidget {
  final String text;
  const _GroupTitle(this.text);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 8),
        child: Text(text.toUpperCase(),
            style: const TextStyle(
                fontSize: 11.5, letterSpacing: 1.2, color: AppColors.text3, fontWeight: FontWeight.w700)),
      );
}

class _Group extends StatelessWidget {
  final List<Widget> children;
  const _Group({required this.children});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 20),
        child: GlassCard(
          padding: EdgeInsets.zero,
          radius: 18,
          child: Column(children: [
            for (var i = 0; i < children.length; i++) ...[
              children[i],
              if (i < children.length - 1)
                const Divider(height: 1, indent: 56),
            ]
          ]),
        ),
      );
}

class _Tile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback? onTap;
  final bool chevron;
  const _Tile(this.icon, this.title, {this.subtitle, this.onTap, this.chevron = true});

  @override
  Widget build(BuildContext context) => ListTile(
        onTap: onTap,
        leading: Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: AppColors.surface2,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, size: 19, color: AppColors.gold),
        ),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
        subtitle: subtitle == null ? null : Text(subtitle!, style: AppText.caption),
        trailing: chevron ? const Icon(Icons.chevron_right_rounded, color: AppColors.text3) : null,
      );
}

class _DeleteAccountDialog extends StatefulWidget {
  const _DeleteAccountDialog();

  @override
  State<_DeleteAccountDialog> createState() => _DeleteAccountDialogState();
}

class _DeleteAccountDialogState extends State<_DeleteAccountDialog> {
  final _pw = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _pw.dispose();
    super.dispose();
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<SessionStore>().deleteAccount(_pw.text);
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = e.message;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.surface,
      title: const Text('Delete account'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('This permanently deletes your account and settings. This cannot be undone. '
              'Deleting your account does not cancel a Google Play subscription: cancel it in Google Play first.',
              style: AppText.body),
          const SizedBox(height: 16),
          TextField(
            controller: _pw,
            obscureText: true,
            decoration: InputDecoration(labelText: 'Confirm password', errorText: _error),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: const Text('Cancel')),
        TextButton(
          onPressed: _busy ? null : _delete,
          child: _busy
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Delete', style: TextStyle(color: AppColors.sell)),
        ),
      ],
    );
  }
}

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  late final _name = TextEditingController(text: context.read<SessionStore>().user?.name ?? '');
  final _form = GlobalKey<FormState>();
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<SessionStore>().updateName(_name.text);
      if (mounted) {
        Navigator.pop(context);
        showSnack(context, 'Profile updated');
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionStore>().user;
    return Scaffold(
      appBar: AppBar(title: const Text('Edit profile')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            TextFormField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              validator: (v) => (v ?? '').trim().length < 2 ? 'Enter your name' : null,
              decoration: const InputDecoration(
                  labelText: 'Full name',
                  prefixIcon: Icon(Icons.person_outline_rounded, color: AppColors.text3)),
            ),
            const SizedBox(height: 14),
            TextFormField(
              initialValue: user?.email,
              enabled: false,
              decoration: const InputDecoration(
                  labelText: 'Email',
                  prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.text3)),
            ),
            const SizedBox(height: 24),
            GoldButton(label: 'Save changes', loading: _busy, onPressed: _save),
          ],
        ),
      ),
    );
  }
}

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<SessionStore>().changePassword(_current.text, _next.text);
      if (mounted) {
        Navigator.pop(context);
        showSnack(context, 'Password changed. Other devices have been logged out.');
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Change password')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            PasswordField(controller: _current, label: 'Current password', action: TextInputAction.next),
            const SizedBox(height: 14),
            PasswordField(
                controller: _next,
                label: 'New password',
                validator: validateNewPassword,
                onSubmitted: _save),
            const SizedBox(height: 24),
            GoldButton(label: 'Update password', loading: _busy, onPressed: _save),
          ],
        ),
      ),
    );
  }
}

class NotificationSettingsScreen extends StatefulWidget {
  const NotificationSettingsScreen({super.key});

  @override
  State<NotificationSettingsScreen> createState() => _NotificationSettingsScreenState();
}

class _NotificationSettingsScreenState extends State<NotificationSettingsScreen> {
  bool? _permission;

  @override
  void initState() {
    super.initState();
    context.read<PushService>().permissionGranted().then((v) {
      if (mounted) setState(() => _permission = v);
    });
  }

  Future<void> _update(NotificationPrefs prefs) async {
    try {
      await context.read<SessionStore>().updateNotifications(prefs);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final push = context.read<PushService>();
    final user = context.watch<SessionStore>().user;
    final prefs = user?.notifications ?? const NotificationPrefs();
    final email = prefs.email;
    final verified = user?.emailVerified ?? false;
    final selected = prefs.pairs ?? AppConfig.pairs;

    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
        children: [
          if (!push.available)
            const Padding(
              padding: EdgeInsets.only(bottom: 16),
              child: InfoBanner(
                icon: Icons.notifications_off_outlined,
                color: AppColors.warn,
                text: 'Push notifications are not set up in this build yet (Firebase not configured). '
                    'Your preferences are still saved.',
              ),
            )
          else if (_permission == false)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: InfoBanner(
                icon: Icons.notifications_off_outlined,
                color: AppColors.warn,
                text: 'Notifications are blocked on this phone. Tap to allow them.',
                onTap: () async {
                  final ok = await push.requestPermission();
                  if (mounted) setState(() => _permission = ok);
                },
              ),
            ),
          GlassCard(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
            child: Column(
              children: [
                SwitchListTile(
                  value: prefs.signals,
                  onChanged: (v) => _update(prefs.copyWith(signals: v)),
                  title: const Text('New signals', style: TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: const Text('Entry, stop loss and targets as soon as a signal is published',
                      style: AppText.caption),
                ),
                const Divider(height: 1),
                SwitchListTile(
                  value: prefs.results,
                  onChanged: (v) => _update(prefs.copyWith(results: v)),
                  title: const Text('Trade updates', style: TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: const Text('When TP1, TP2 or the stop loss is hit', style: AppText.caption),
                ),
                const Divider(height: 1),
                SwitchListTile(
                  value: prefs.reports,
                  onChanged: (v) => _update(prefs.copyWith(reports: v)),
                  title: const Text('Daily & weekly summary', style: TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: const Text('Wins, losses and pips at the end of each day and week', style: AppText.caption),
                ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          const SectionHeader('Email'),
          if (!verified)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: InfoBanner(
                icon: Icons.mark_email_unread_outlined,
                color: AppColors.warn,
                text: 'Verify your email address to start receiving emails. Tap to verify.',
                onTap: () => Navigator.of(context)
                    .push(MaterialPageRoute(builder: (_) => const VerifyEmailScreen())),
              ),
            ),
          Opacity(
            opacity: verified ? 1 : 0.5,
            child: GlassCard(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
              child: Column(
                children: [
                  for (final (i, title, sub, value, next) in [
                    (0, 'New signals', 'Full trade plan for every new signal', email.signals, (bool v) => email.copyWith(signals: v)),
                    (1, 'Trade results', 'When a signal wins or loses', email.results, (bool v) => email.copyWith(results: v)),
                    (2, 'Daily report', 'Every trading day after the New York close', email.daily, (bool v) => email.copyWith(daily: v)),
                    (3, 'Weekly report', 'Saturday morning: the full week', email.weekly, (bool v) => email.copyWith(weekly: v)),
                    (4, 'Monthly report', 'On the 1st: last month\'s performance', email.monthly, (bool v) => email.copyWith(monthly: v)),
                  ]) ...[
                    if (i > 0) const Divider(height: 1),
                    SwitchListTile(
                      value: value,
                      onChanged: verified ? (v) => _update(prefs.copyWith(email: next(v))) : null,
                      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(sub, style: AppText.caption),
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader('Markets',
              action: prefs.pairs == null ? null : 'Select all',
              onAction: () => _update(prefs.copyWith(allPairs: true))),
          GlassCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(
              children: [
                for (final p in AppConfig.pairs)
                  CheckboxListTile(
                    value: selected.contains(p),
                    onChanged: (v) {
                      final next = [...selected];
                      if (v == true) {
                        next.add(p);
                      } else {
                        next.remove(p);
                      }
                      _update(next.length == AppConfig.pairs.length
                          ? prefs.copyWith(allPairs: true)
                          : prefs.copyWith(pairs: next));
                    },
                    secondary: PairAvatar(pair: p, size: 30),
                    title: Text(p, style: const TextStyle(fontWeight: FontWeight.w600)),
                    controlAffinity: ListTileControlAffinity.trailing,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
