<?php
declare(strict_types=1);

final class Market
{
    public static function prices(): void
    {
        Auth::user();
        $rows = Db::all('SELECT * FROM prices');
        $bySymbol = [];
        foreach ($rows as $r) $bySymbol[$r['symbol']] = $r;
        $items = [];
        foreach (config('pairs') as $pair) {
            $r = $bySymbol[$pair] ?? null;
            if (!$r) continue;
            $items[] = [
                'symbol' => $pair,
                'price' => (float) $r['price'],
                'change_pct' => (float) $r['change_pct'],
                'high' => (float) $r['high'],
                'low' => (float) $r['low'],
                'spark' => json_decode($r['spark'] ?: '[]', true),
                'updated_at' => iso($r['updated_at']),
            ];
        }
        Http::send(['items' => $items]);
    }

    public static function health(): void
    {
        $last = Db::one("SELECT v FROM meta WHERE k = 'last_cron'");
        Http::send([
            'ok' => true,
            'app' => config('app_name'),
            'pairs' => config('pairs'),
            'timeframe' => config('timeframe'),
            'last_update' => $last ? json_decode($last['v'], true) : null,
            'push_enabled' => Fcm::enabled(),
            // Switch developer links on/off without an app update
            // What is free vs Premium, and how prices are presented (editable on /admin)
            'premium' => [
                'enforced' => Billing::enforced(),
                'markets' => Billing::premiumMarkets(),
                'free_max_strength' => Billing::freeMaxStrength(),
                'price_display' => config('price_display', (object) []),
            ],
            'promo' => config('promo', ['coffee' => true, 'whatsapp' => true, 'hire' => true]),
        ]);
    }
}
