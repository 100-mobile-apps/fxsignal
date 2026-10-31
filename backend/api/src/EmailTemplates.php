<?php
declare(strict_types=1);

/** HTML emails (table layout + inline styles so they render in Gmail/Outlook). */
final class EmailTemplates
{
    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function fmt(string $pair, $v): string
    {
        return $v === null ? '—' : number_format((float) $v, price_decimals($pair), '.', '');
    }

    public static function pips(?float $p): string
    {
        if ($p === null) return '—';
        return ($p > 0 ? '+' : '') . number_format($p, 1);
    }

    private static function layout(string $preheader, string $inner, ?string $unsubUrl, string $reason): string
    {
        $app = self::e((string) config('app_name', 'FX Signals'));
        $unsub = $unsubUrl
            ? "<a href=\"" . self::e($unsubUrl) . "\" style=\"color:#8A93A8;text-decoration:underline\">Unsubscribe from these emails</a> · "
            : '';
        $promo = config('promo', ['coffee' => true, 'whatsapp' => true, 'hire' => true]);
        $dev = config('developer', [
            'name' => 'Swiftmynd Technologies',
            'whatsapp' => 'https://wa.me/2347011401387?text=' . rawurlencode('Hi Abdullahi, I use ' . config('app_name', 'FX Signals') . " and I'd like to talk about building an app or website."),
            'coffee' => 'https://buymeacoffee.com/swalafblogM',
        ]);
        $credit = 'Built by ' . (!empty($promo['hire'])
            ? '<a href="' . self::e($dev['whatsapp']) . '" style="color:#C9A24A;text-decoration:none">' . self::e($dev['name']) . ' — need an app? Chat on WhatsApp</a>'
            : self::e($dev['name']))
            . (!empty($promo['coffee']) ? ' · <a href="' . self::e($dev['coffee']) . '" style="color:#C9A24A;text-decoration:none">Buy me a coffee</a>' : '');
        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$app</title></head>
<body style="margin:0;padding:0;background:#0B1019;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#E9EDF5">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{$preheader}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B1019;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
<tr><td style="padding:4px 4px 18px">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td style="width:34px;height:34px;border-radius:10px;background:#F5B942;text-align:center;font-weight:800;color:#1A1204;font-size:16px">FX</td>
    <td style="padding-left:10px;font-size:16px;font-weight:700;color:#F4F6FB">$app</td>
  </tr></table>
</td></tr>
<tr><td style="background:#121a29;border:1px solid #1F2A3F;border-radius:18px;padding:26px 24px">$inner</td></tr>
<tr><td style="padding:18px 8px;font-size:11.5px;line-height:1.6;color:#8A93A8;text-align:center">
  $reason<br>{$unsub}Signals are automated technical analysis, not financial advice. Trading involves significant risk of loss.
  <div style="margin-top:10px">$credit</div>
</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function levelsTable(array $s): string
    {
        $p = $s['pair'];
        $pip = pip_size($p);
        $dir = $s['direction'] === 'BUY' ? 1 : -1;
        $d = fn($v) => self::pips(round(((float) $v - (float) $s['entry']) * $dir / $pip, 1)) . ' pips';
        $rows = [
            ['Entry', self::fmt($p, $s['entry']), '#F5B942', 'Market'],
            ['Stop loss', self::fmt($p, $s['stop_loss']), '#FF5A6E', $d($s['stop_loss'])],
            ['Take profit 1', self::fmt($p, $s['tp1']), '#1FD18B', $d($s['tp1'])],
            ['Take profit 2', self::fmt($p, $s['tp2']), '#1FD18B', $d($s['tp2'])],
        ];
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;border-collapse:collapse">';
        foreach ($rows as $i => [$label, $val, $color, $sub]) {
            $border = $i < 3 ? 'border-bottom:1px solid #1F2A3F;' : '';
            $html .= "<tr><td style=\"padding:11px 0;{$border}font-size:14px;color:#AEB6C8\">$label<br><span style=\"font-size:12px;color:#6B7690\">$sub</span></td>"
                . "<td align=\"right\" style=\"padding:11px 0;{$border}font-size:17px;font-weight:700;color:$color;font-family:Consolas,Menlo,monospace\">$val</td></tr>";
        }
        return $html . '</table>';
    }

    private static function button(string $text): string
    {
        return '<div style="margin-top:22px;padding:13px 0;border-radius:12px;background:#F5B942;color:#1A1204;text-align:center;font-weight:700;font-size:15px">'
            . self::e($text) . '</div>';
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    public static function code(string $name, string $code, string $purpose): array
    {
        $app = (string) config('app_name', 'FX Signals');
        $verify = $purpose === 'verify';
        $subject = $verify ? "Verify your email for $app" : "$app password reset code";
        $lead = $verify
            ? 'Enter this code in the app to confirm your email address and turn on email alerts.'
            : 'Enter this code in the app to choose a new password.';
        $inner = '<div style="font-size:20px;font-weight:700;color:#F4F6FB">Hi ' . self::e($name) . ',</div>'
            . '<p style="font-size:15px;line-height:1.6;color:#AEB6C8;margin:10px 0 0">' . $lead . '</p>'
            . '<div style="margin:22px 0 6px;padding:18px;border-radius:14px;background:#0B1019;border:1px solid #2A3650;text-align:center;font:800 34px Consolas,Menlo,monospace;letter-spacing:10px;color:#F5B942">' . $code . '</div>'
            . '<p style="font-size:13px;color:#6B7690;margin:10px 0 0">The code expires in 15 minutes. If you didn\'t request it, you can ignore this email.</p>';
        $html = self::layout("Your code is $code", $inner, null, 'You received this because a code was requested for your account.');
        $text = "Hi $name,\n\n$lead\n\nYour code: $code\n\nIt expires in 15 minutes.\n\n— $app";
        return [$subject, $html, $text];
    }

    public static function newSignal(array $s, string $name, string $unsub): array
    {
        $buy = $s['direction'] === 'BUY';
        $color = $buy ? '#1FD18B' : '#FF5A6E';
        $pair = $s['pair'];
        $subject = "{$s['direction']} $pair @ " . self::fmt($pair, $s['entry']) . " — new signal";
        $reasons = json_decode($s['reasons'] ?: '[]', true) ?: [];
        $reasonHtml = '';
        foreach ($reasons as $r) {
            $reasonHtml .= '<div style="font-size:13.5px;color:#AEB6C8;padding:3px 0">✓ ' . self::e($r) . '</div>';
        }
        $inner = '<div style="font-size:13px;color:#8A93A8;text-transform:uppercase;letter-spacing:1px">New signal · H1</div>'
            . '<div style="margin-top:6px;font-size:26px;font-weight:800;color:#F4F6FB">' . self::e($pair)
            . ' <span style="font-size:14px;padding:4px 10px;border-radius:20px;border:1px solid ' . $color . ';color:' . $color . ';vertical-align:middle">' . $s['direction'] . '</span></div>'
            . '<div style="margin-top:6px;font-size:13px;color:#8A93A8">Strength ' . (int) $s['confidence'] . '% · Published ' . gmdate('M j, H:i', strtotime($s['opened_at'] . ' UTC')) . ' UTC</div>'
            . self::levelsTable($s)
            . ($reasonHtml ? '<div style="margin-top:18px;font-size:14px;font-weight:700;color:#F4F6FB">Why this signal</div>' . $reasonHtml : '')
            . '<p style="margin:18px 0 0;font-size:13px;line-height:1.55;color:#8A93A8">After TP1 is reached, move your stop loss to entry. Risk 1–2% of your account at most.</p>'
            . self::button('Open the app for the live chart');
        $text = "New signal: {$s['direction']} $pair\nEntry: " . self::fmt($pair, $s['entry']) . "\nStop loss: " . self::fmt($pair, $s['stop_loss'])
            . "\nTP1: " . self::fmt($pair, $s['tp1']) . "\nTP2: " . self::fmt($pair, $s['tp2']) . "\nStrength: {$s['confidence']}%\n\n"
            . implode("\n", array_map(fn($r) => "- $r", $reasons)) . "\n\nUnsubscribe: $unsub";
        $html = self::layout("{$s['direction']} $pair — entry, stop loss and targets inside", $inner, $unsub,
            "You're getting this because new-signal emails are on for " . self::e($pair) . '.');
        return [$subject, $html, $text];
    }

    public static function result(array $s, string $kind, string $name, string $unsub): array
    {
        $pair = $s['pair'];
        $pips = (float) ($kind === 'tp1' ? $s['live_pips'] : $s['pips']);
        $win = $pips > 0;
        [$headline, $sub] = match ($kind) {
            'tp1' => ["TP1 reached on $pair", 'Move your stop loss to entry (' . self::fmt($pair, $s['entry']) . ') to make the rest of the trade risk-free.'],
            'tp2' => ["$pair hit TP2", 'Full target reached.'],
            'sl' => ["$pair stopped out", 'The stop loss was hit. Losses are part of any strategy; keep your risk per trade small.'],
            default => [$win ? "$pair closed in profit" : "$pair closed", $s['result'] === 'tp1'
                ? 'TP1 was reached and the rest closed at break-even.'
                : 'The signal reached its time limit and closed at the market price.'],
        };
        $icon = $kind === 'sl' ? '✕' : ($win ? '✓' : '•');
        $color = $win ? '#1FD18B' : ($pips < 0 ? '#FF5A6E' : '#AEB6C8');
        $subject = ($kind === 'sl' ? '❌ ' : ($win ? '✅ ' : '')) . "$headline " . self::pips($pips) . ' pips';
        $inner = '<div style="width:46px;height:46px;border-radius:23px;background:' . $color . '22;border:1px solid ' . $color . ';color:' . $color . ';font-size:22px;font-weight:800;text-align:center;line-height:46px">' . $icon . '</div>'
            . '<div style="margin-top:14px;font-size:22px;font-weight:800;color:#F4F6FB">' . self::e($headline) . '</div>'
            . '<div style="margin-top:8px;font:800 34px Consolas,Menlo,monospace;color:' . $color . '">' . self::pips($pips) . ' <span style="font-size:14px;color:#8A93A8;font-family:Segoe UI,Arial">pips</span></div>'
            . '<p style="font-size:14.5px;line-height:1.6;color:#AEB6C8;margin:8px 0 0">' . self::e($sub) . '</p>'
            . self::levelsTable($s)
            . self::button('See the full track record');
        $text = "$headline: " . self::pips($pips) . " pips\n$sub\n\n{$s['direction']} $pair from " . self::fmt($pair, $s['entry']) . "\n\nUnsubscribe: $unsub";
        $html = self::layout("$headline " . self::pips($pips) . ' pips', $inner, $unsub,
            "You're getting this because trade-result emails are on.");
        return [$subject, $html, $text];
    }

    public static function report(string $period, array $row, array $signals, array $summary, string $name, string $unsub): array
    {
        $app = (string) config('app_name', 'FX Signals');
        $title = ['daily' => 'Daily report', 'weekly' => 'Weekly report', 'monthly' => 'Monthly report'][$period];
        $pips = (float) $row['pips'];
        $pc = $pips >= 0 ? '#1FD18B' : '#FF5A6E';
        $subject = "$title · {$row['label']}: {$row['wins']}W / {$row['losses']}L · " . self::pips($pips) . ' pips';
        $tile = fn($v, $l, $c = '#F4F6FB') => '<td width="25%" style="padding:12px 6px;text-align:center;background:#0B1019;border-radius:12px"><div style="font:800 20px Consolas,Menlo,monospace;color:' . $c . '">' . $v . '</div><div style="font-size:11.5px;color:#8A93A8;margin-top:3px">' . $l . '</div></td>';
        $tiles = '<table role="presentation" width="100%" cellpadding="0" cellspacing="6" style="margin-top:16px"><tr>'
            . $tile(self::pips($pips), 'Pips', $pc)
            . $tile(number_format((float) $row['win_rate'], 0) . '%', 'Win rate', '#F5B942')
            . $tile($row['wins'] . '/' . $row['losses'], 'Won / lost')
            . $tile((string) $row['opened'], 'New signals')
            . '</tr></table>';
        $list = '';
        $shown = array_slice(array_reverse($signals), 0, 15); // latest 15
        foreach ($shown as $s) {
            $p = (float) $s['pips'];
            $c = $p > 0 ? '#1FD18B' : ($p < 0 ? '#FF5A6E' : '#AEB6C8');
            $res = ['tp2' => 'TP2', 'tp1' => 'TP1', 'sl' => 'SL', 'expired' => 'Expired'][$s['result']] ?? $s['result'];
            $list .= '<tr><td style="padding:9px 0;border-bottom:1px solid #1F2A3F;font-size:14px;color:#E9EDF5"><b>' . self::e($s['pair']) . '</b> <span style="font-size:11.5px;font-weight:800;color:' . ($s['direction'] === 'BUY' ? '#1FD18B' : '#FF5A6E') . '">' . $s['direction'] . '</span><br><span style="font-size:12px;color:#6B7690">' . $res . ' · ' . gmdate('M j, H:i', strtotime($s['closed_at'] . ' UTC')) . ' UTC</span></td>'
                . '<td align="right" style="padding:9px 0;border-bottom:1px solid #1F2A3F;font:700 15px Consolas,Menlo,monospace;color:' . $c . '">' . self::pips($p) . '</td></tr>';
        }
        $more = count($signals) - count($shown);
        $listHtml = $list
            ? '<div style="margin-top:20px;font-size:14px;font-weight:700;color:#F4F6FB">' . ($more > 0 ? 'Latest closed signals' : 'Closed signals') . '</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $list . '</table>'
              . ($more > 0 ? '<p style="margin:10px 0 0;font-size:13px;color:#8A93A8">+ ' . $more . ' more in the app.</p>' : '')
            : '<p style="margin-top:18px;font-size:14px;color:#8A93A8">No signals closed in this period.</p>';
        $month = $summary['month'];
        $inner = '<div style="font-size:13px;color:#8A93A8;text-transform:uppercase;letter-spacing:1px">' . $title . '</div>'
            . '<div style="margin-top:6px;font-size:24px;font-weight:800;color:#F4F6FB">' . self::e($row['label']) . '</div>'
            . $tiles . $listHtml
            . '<p style="margin:18px 0 0;font-size:13px;color:#8A93A8">Month to date: ' . $month['wins'] . ' wins, ' . $month['losses'] . ' losses, '
            . self::pips((float) $month['pips']) . ' pips · ' . (int) $summary['running'] . ' signals running now.</p>'
            . self::button('Open full report in the app');
        $text = "$title — {$row['label']}\nPips: " . self::pips($pips) . "\nWin rate: " . number_format((float) $row['win_rate'], 0)
            . "%\nWins/losses: {$row['wins']}/{$row['losses']}\nNew signals: {$row['opened']}\n\n"
            . implode("\n", array_map(fn($s) => "{$s['pair']} {$s['direction']} {$s['result']} " . self::pips((float) $s['pips']), $signals))
            . "\n\nUnsubscribe: $unsub\n— $app";
        $html = self::layout("{$row['wins']} wins, {$row['losses']} losses, " . self::pips($pips) . ' pips', $inner, $unsub,
            "You're getting this because " . strtolower($title) . ' emails are on.');
        return [$subject, $html, $text];
    }
}
