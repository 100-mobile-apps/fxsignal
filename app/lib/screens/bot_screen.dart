import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/api.dart';
import '../models/money.dart';
import '../state/session_store.dart';
import '../widgets/common.dart';

/// Auto-trade bot: the user installs a free MetaTrader 5 robot, pastes their personal key, and the
/// robot places the two trades for every signal on their own account. Nothing about their broker
/// login ever reaches us.
class BotScreen extends StatefulWidget {
  const BotScreen({super.key});

  @override
  State<BotScreen> createState() => _BotScreenState();
}

class _BotScreenState extends State<BotScreen> {
  BotKey? _key;
  String? _error;
  bool _busy = false;

  static const _download = 'https://forexsignal.bulksend.top/downloads/FxSignalBot.mq5';

  @override
  void initState() {
    super.initState();
    _load(false);
  }

  Future<void> _load(bool rotate) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final k = await context.read<Api>().botKey(rotate: rotate);
      if (mounted) setState(() => _key = k);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _copy(String text, String what) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$what copied')));
  }

  Future<void> _newKey() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Make a new key?'),
        content: const Text('The old key stops working right away. Paste the new one into the robot.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('Make new key')),
        ],
      ),
    );
    if (ok == true) _load(true);
  }

  @override
  Widget build(BuildContext context) {
    final prefs = context.watch<SessionStore>().user?.prefs;
    final risk = prefs == null ? '1' : (prefs.riskPct == prefs.riskPct.roundToDouble() ? prefs.riskPct.toStringAsFixed(0) : prefs.riskPct.toStringAsFixed(1));
    return Scaffold(
      appBar: AppBar(title: const Text('Auto-trade bot')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        children: [
          GlassCard(
            borderColor: AppColors.gold.withValues(alpha: 0.5),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Never miss a trade', style: AppText.h2),
                SizedBox(height: 6),
                Text(
                  'A free robot for MetaTrader 5 places every signal on your own account for you: two trades per signal, '
                  'the stop moved to entry after the first target, then to the first target at halfway. '
                  'Your broker login stays on your computer. The robot only reads signals with the key below.',
                  style: AppText.body,
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          if (_error != null) InfoBanner(icon: Icons.error_outline, text: _error!, color: AppColors.sell),
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Your bot key', style: AppText.title),
                const SizedBox(height: 8),
                if (_key == null)
                  const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: LinearProgressIndicator())
                else ...[
                  SelectableText(_key!.key, style: AppText.title.copyWith(letterSpacing: 0.5)),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(child: GoldButton(label: 'Copy key', icon: Icons.copy_rounded, onPressed: () => _copy(_key!.key, 'Key'))),
                    const SizedBox(width: 10),
                    TextButton(onPressed: _busy ? null : _newKey, child: const Text('New key')),
                  ]),
                ],
                const SizedBox(height: 8),
                const Text('Keep it private. Anyone with it can read the signals meant for you, but cannot trade or see your account.', style: AppText.caption),
              ],
            ),
          ),
          const SizedBox(height: 14),
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Set it up in 5 steps', style: AppText.title),
                const SizedBox(height: 10),
                _Step(1, 'Download the robot', 'Open this link on your computer and save the file:', copy: _download, onCopy: () => _copy(_download, 'Link')),
                const _Step(2, 'Add it to MetaTrader 5', 'File > Open Data Folder > MQL5 > Experts, paste FxSignalBot.mq5 there, then restart MetaTrader (or press F4 and compile it).'),
                _Step(3, 'Allow the server', 'Tools > Options > Expert Advisors > tick "Allow WebRequest" and add:', copy: _key?.url, onCopy: _key == null ? null : () => _copy(_key!.url, 'Address')),
                const _Step(4, 'Attach it to any chart', 'Drag FxSignalBot onto a chart, paste your key into ApiKey, and switch on Algo Trading. Use a demo account first.'),
                _Step(5, 'Risk follows the app', 'Leave RiskPercent at 0 and it uses the $risk% you chose in Your money. Small accounts: the smallest trade size may risk more than that, and the robot skips signals where it would be too much.'),
              ],
            ),
          ),
          const SizedBox(height: 14),
          const InfoBanner(
            icon: Icons.warning_amber_rounded,
            color: AppColors.warn,
            text: 'Trading loses money. High risk settings can wipe out an account in a losing streak. Needs a hedging account and the computer or a VPS running MetaTrader. Start on a demo.',
          ),
        ],
      ),
    );
  }
}

class _Step extends StatelessWidget {
  final int n;
  final String title, body;
  final String? copy;
  final VoidCallback? onCopy;
  const _Step(this.n, this.title, this.body, {this.copy, this.onCopy});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(radius: 12, backgroundColor: AppColors.gold, child: Text('$n', style: const TextStyle(color: Colors.black, fontWeight: FontWeight.w700, fontSize: 12))),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(color: AppColors.text, fontWeight: FontWeight.w600)),
                const SizedBox(height: 2),
                Text(body, style: AppText.body),
                if (copy != null)
                  GestureDetector(
                    onTap: onCopy,
                    child: Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Row(children: [
                        Flexible(child: Text(copy!, style: const TextStyle(color: AppColors.info, fontSize: 13))),
                        const SizedBox(width: 6),
                        const Icon(Icons.copy_rounded, size: 14, color: AppColors.info),
                      ]),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
