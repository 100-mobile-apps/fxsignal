<?php
declare(strict_types=1);
/**
 * Compares what a follower does after TP1 on the live engine's signals (two half trades: TP1 and TP2):
 *   be   - move the TP2 trade's stop to entry
 *   lock - move it to TP1 (from the next candle)
 *   mid  - to entry, then to TP1 once price is halfway from TP1 to TP2
 *   all1 - close everything at TP1 (reference)
 * Entry rules mirror backtest.php (next-open entry, entry-drift check, weekend pause, 4h cooldown, 72h expiry).
 * Inside a candle the stop is checked first; a gap through the stop fills at the open. Costs per trade as in the other tests.
 * Usage: php exits_run.php [--csv=history.csv]
 */
ob_start();
require __DIR__ . '/pa_run.php';      // bt_* helpers, Engine, TradingPolicy
ob_end_clean();

function exits_sim(array $bars, string $mode, float $costPips, float $pip): array {
    $out = []; $open = null; $cool = 0; $n = count($bars);
    for ($i = 300; $i < $n; $i++) {
        $b = $bars[$i];
        if ($open) {
            $d = $open['dir'];
            $hit = fn(float $lvl, string $side) => $side === 'stop'
                ? (($d > 0 ? $b['l'] : $b['h']) - $lvl) * $d <= 0 : (($d > 0 ? $b['h'] : $b['l']) - $lvl) * $d >= 0;
            $res = null;
            if (!empty($open['pend']) && $b['t'] > $open['tp1t']) { $open['stop'] = $open['l1']; $open['pend'] = false; }
            if ($b['t'] >= $open['exp']) $res = ['rest' => $b['o']];
            elseif (($b['o'] - $open['stop']) * $d <= 0) $res = ['rest' => $b['o']];
            elseif ($hit($open['stop'], 'stop')) $res = ['rest' => $open['stop']];
            else {
                if (!$open['tp1'] && $hit($open['l1'], 'tp')) {
                    $open['tp1'] = true; $open['tp1t'] = $b['t'];
                    if ($mode === 'all1') $res = ['rest' => $open['l1']];
                    elseif ($mode !== 'lock') $open['stop'] = $open['entry'];
                    else $open['pend'] = true;                        // stop to TP1 from the next candle
                }
                if (!$res && $open['tp1'] && $hit($open['l2'], 'tp')) $res = ['rest' => $open['l2']];
                if (!$res && $open['tp1'] && $mode === 'mid' && ($open['stop'] - $open['l1']) * $d < 0
                    && $hit(($open['l1'] + $open['l2']) / 2, 'tp')) { $open['pend'] = true; $open['tp1t'] = $b['t']; }
            }
            if ($res) {
                $first = $open['tp1'] ? ($open['l1'] - $open['entry']) * $d : ($res['rest'] - $open['entry']) * $d;
                $pips = (($first + ($res['rest'] - $open['entry']) * $d) / 2) / $pip - $costPips;
                if ($mode === 'all1' && $open['tp1']) $pips = ($open['l1'] - $open['entry']) * $d / $pip - $costPips;
                $out[] = ['t' => $open['t'], 'pips' => $pips, 'r' => $pips / $open['risk']];
                $cool = $b['t'] + 4 * 3600; $open = null;
            }
            continue;
        }
        if ($b['t'] < $cool || $b['t'] !== $bars[$i - 1]['t'] + 3600 || !TradingPolicy::canPublish($b['t'])) continue;
        $a = Engine::analyze(array_slice($bars, $i - 300, 300), 65);
        if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || ($a['confidence'] ?? 0) < 65 || $a['sl'] === null) continue;
        $entry = $b['o'];
        if (!TradingPolicy::entryWithinAtr((float) $entry, (float) $a['entry'], (float) ($a['atr'] ?? 0), 0.5)) continue;
        $d = $a['direction'] === 'BUY' ? 1 : -1; $sh = $entry - $a['entry'];
        $sl = $a['sl'] + $sh; $l1 = $a['tp1'] + $sh; $l2 = $a['tp2'] + $sh;
        if (($entry - $sl) * $d <= 0 || ($l1 - $entry) * $d <= 0 || ($l2 - $l1) * $d <= 0) continue;
        $open = ['dir' => $d, 'entry' => $entry, 'stop' => $sl, 'l1' => $l1, 'l2' => $l2, 'tp1' => false, 'risk' => abs($entry - $sl) / $pip,
                 't' => $b['t'], 'exp' => $b['t'] + 72 * 3600];
        $i--;   // manage the entry candle itself on the next pass
        $cool = PHP_INT_MAX; // (blocks re-entry while open; reset on close)
    }
    return $out;
}

$files = glob(__DIR__ . '/data3y/*.csv');
if (is_file(__DIR__ . '/gold_3y.csv')) $files[] = __DIR__ . '/gold_3y.csv';
$modes = ['be' => 'Stop to entry after TP1', 'lock' => 'Stop to TP1 after TP1', 'mid' => 'Entry, then TP1 at halfway', 'all1' => 'Close all at TP1'];
$cut = strtotime('2026-01-01 00:00:00 UTC');
echo "market|rule|trades|win%|avgWinR|avgLossR|netR|netPips|2024-25 R|2026 R\n";
$tot = [];
foreach ($files as $f) foreach (bt_load_csv($f) as $sym => $bars) {
    $pip = bt_pip_size($sym);
    $pct = str_starts_with($sym, 'BTC/') ? 0.0005 : (str_starts_with($sym, 'ETH/') ? 0.0007 : null);
    $closes = array_map(fn($x) => $x['c'], $bars); sort($closes); $median = $closes[intdiv(count($closes), 2)];
    $cost = $pct !== null ? $pct * $median / $pip : (str_starts_with($sym, 'XAU/') ? 3.0 : 1.5);
    foreach ($modes as $m => $label) {
        $tr = exits_sim($bars, $m, $cost, $pip);
        $w = array_filter($tr, fn($t) => $t['r'] > 0); $l = array_filter($tr, fn($t) => $t['r'] <= 0);
        $R = array_sum(array_column($tr, 'r')); $P = array_sum(array_column($tr, 'pips'));
        $e = array_sum(array_map(fn($t) => $t['t'] < $cut ? $t['r'] : 0, $tr)); $z = $R - $e;
        printf("%s|%s|%d|%.0f|%.2f|%.2f|%+.1f|%+.0f|%+.1f|%+.1f\n", $sym, $label, count($tr), $tr ? 100 * count($w) / count($tr) : 0,
            $w ? array_sum(array_column($w, 'r')) / count($w) : 0, $l ? array_sum(array_column($l, 'r')) / count($l) : 0, $R, $P, $e, $z);
        $tot[$label] = ($tot[$label] ?? 0) + (preg_match('/^(XAU|BTC|ETH)/', $sym) ? 0 : $R);
    }
}
foreach ($tot as $label => $R) printf("FOREX TOTAL|%s|||||%+.1f|||\n", $label, $R);
