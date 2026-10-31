import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/config.dart';
import '../core/theme.dart';
import '../services/developer.dart';
import 'common.dart';

const _waGreen = Color(0xFF25D366);
const _coffee = Color(0xFFFFDD00);

/// Floating "click to chat" WhatsApp button (bottom-right).
class WhatsAppFab extends StatelessWidget {
  const WhatsAppFab({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: Developer.promo,
      builder: (context, _, __) {
        if (!Developer.showWhatsApp) return const SizedBox.shrink();
        return Tooltip(
          message: 'Chat on WhatsApp',
          child: Material(
            color: _waGreen,
            shape: const CircleBorder(),
            elevation: 6,
            shadowColor: Colors.black54,
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: () async {
                final ok = await Developer.open(Developer.whatsappUrl());
                if (!ok && context.mounted) {
                  showSnack(context, 'WhatsApp isn\'t installed. Message +${Developer.whatsappNumber}', error: true);
                }
              },
              child: const SizedBox(
                width: 54,
                height: 54,
                child: Icon(Icons.chat_rounded, color: Colors.white, size: 26),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// "Who built this" card for the Profile tab.
class DeveloperCard extends StatelessWidget {
  const DeveloperCard({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: Developer.promo,
      builder: (context, _, __) => GlassCard(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 46,
                  height: 46,
                  decoration: const BoxDecoration(gradient: AppColors.goldGradient, shape: BoxShape.circle),
                  alignment: Alignment.center,
                  child: const Text('AA',
                      style: TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF1A1204))),
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Built by ${Developer.name}', style: AppText.title),
                      SizedBox(height: 2),
                      Text(Developer.company,
                          style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w600, fontSize: 13)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            const Text(Developer.pitch, style: AppText.body),
            const SizedBox(height: 16),
            if (Developer.showHire)
              _LinkButton(
                icon: Icons.chat_rounded,
                color: _waGreen,
                label: 'Hire me: message on WhatsApp',
                onTap: () => Developer.open(Developer.whatsappUrl(Developer.hireMessage)),
              ),
            _LinkButton(
              icon: Icons.work_outline_rounded,
              color: const Color(0xFF0A66C2),
              label: 'Connect on LinkedIn',
              onTap: () => Developer.open(Developer.linkedIn),
            ),
            if (Developer.showCoffee)
              _LinkButton(
                icon: Icons.local_cafe_rounded,
                color: _coffee,
                label: 'Buy me a coffee',
                onTap: () => Developer.open(Developer.buyMeACoffee),
              ),
            _LinkButton(
              icon: Icons.mail_outline_rounded,
              color: AppColors.text2,
              label: Developer.email,
              onTap: () async {
                final ok = await Developer.openEmail();
                if (!ok && context.mounted) showSnack(context, 'Email address copied');
              },
            ),
            _LinkButton(
              icon: Icons.star_rounded,
              color: AppColors.gold,
              label: 'Rate ${AppConfig.appName} on Google Play',
              onTap: Developer.rateApp,
            ),
          ],
        ),
      ),
    );
  }
}

class _LinkButton extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback onTap;
  const _LinkButton({required this.icon, required this.color, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: AppColors.bg,
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.border),
            ),
            child: Row(
              children: [
                Icon(icon, color: color, size: 20),
                const SizedBox(width: 12),
                Expanded(child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600))),
                const Icon(Icons.open_in_new_rounded, size: 16, color: AppColors.text3),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// "Before you go" sheet shown when leaving the app (see Developer rules).
/// Every option lets the user leave; nothing blocks the exit.
Future<void> showBeforeYouGo(BuildContext context) async {
  await Developer.markExitPromptShown();
  if (!context.mounted) return;
  final leave = await showModalBottomSheet<bool>(
    context: context,
    backgroundColor: AppColors.surface,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    builder: (ctx) {
      Widget action(IconData icon, Color color, String title, String sub, VoidCallback onTap) => ListTile(
            onTap: onTap,
            contentPadding: const EdgeInsets.symmetric(horizontal: 4),
            leading: Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: color.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(12)),
              child: Icon(icon, color: color),
            ),
            title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(sub, style: AppText.caption),
          );
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                    width: 40, height: 4, decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(4))),
              ),
              const SizedBox(height: 18),
              const Text('Before you go', style: AppText.h2),
              const SizedBox(height: 6),
              const Text(
                '${AppConfig.appName} is free and built by one developer. If it helps your trading, '
                'here are a few quick ways to support it.',
                style: AppText.body,
              ),
              const SizedBox(height: 12),
              action(Icons.star_rounded, AppColors.gold, 'Rate us 5 stars', 'Takes 10 seconds and helps other traders find the app', () {
                Developer.rateApp();
                Navigator.pop(ctx, false);
              }),
              if (Developer.showCoffee)
                action(Icons.local_cafe_rounded, _coffee, 'Buy me a coffee', 'Support free signals and new features', () {
                  Developer.open(Developer.buyMeACoffee);
                  Navigator.pop(ctx, false);
                }),
              if (Developer.showHire)
                action(Icons.chat_rounded, _waGreen, 'Need an app or website?', 'Chat with the developer on WhatsApp', () {
                  Developer.open(Developer.whatsappUrl(Developer.hireMessage));
                  Navigator.pop(ctx, false);
                }),
              const SizedBox(height: 10),
              Row(
                children: [
                  TextButton(
                    onPressed: () async {
                      await Developer.neverAskAgain();
                      if (ctx.mounted) Navigator.pop(ctx, true);
                    },
                    child: const Text('Don\'t ask again', style: TextStyle(color: AppColors.text3)),
                  ),
                  const Spacer(),
                  FilledButton(
                    style: FilledButton.styleFrom(
                        backgroundColor: AppColors.surface2, foregroundColor: AppColors.text),
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text('Exit app'),
                  ),
                ],
              ),
            ],
          ),
        ),
      );
    },
  );
  if (leave == true) SystemNavigator.pop();
}
