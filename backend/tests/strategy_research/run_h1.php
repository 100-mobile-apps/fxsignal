<?php
declare(strict_types=1);
require __DIR__ . '/../backtest.php';
ini_set('memory_limit', '2G');

$series = bt_load_csv($argv[1]);
$only = $argv[2] ?? null;
$COST = fn($s) => str_starts_with($s, 'XAU') ? (float)(getenv("CX")?:3.0) : (float)(getenv("CF")?:1.2); // spread+slippage in pips
$IS = [strtotime('2022-03-01Z'), strtotime('2025-01-01Z')];
$OOS = [strtotime('2025-01-01Z'), strtotime('2026-11-01Z')];

function adx(array $c, int $p = 14): array {
    $n = count($c); $out = array_fill(0, $n, null);
    if ($n < 2 * $p + 1) return $out;
    $tr = $pdm = $mdm = [0.0];
    for ($i = 1; $i < $n; $i++) {
        $up = $c[$i]['h'] - $c[$i-1]['h']; $dn = $c[$i-1]['l'] - $c[$i]['l'];
        $pdm[] = ($up > $dn && $up > 0) ? $up : 0.0; $mdm[] = ($dn > $up && $dn > 0) ? $dn : 0.0;
        $tr[] = max($c[$i]['h'] - $c[$i]['l'], abs($c[$i]['h'] - $c[$i-1]['c']), abs($c[$i]['l'] - $c[$i-1]['c']));
    }
    $atr = array_sum(array_slice($tr, 1, $p)); $sp = array_sum(array_slice($pdm, 1, $p)); $sm = array_sum(array_slice($mdm, 1, $p));
    $dx = [];
    for ($i = $p + 1; $i < $n; $i++) {
        $atr = $atr - $atr / $p + $tr[$i]; $sp = $sp - $sp / $p + $pdm[$i]; $sm = $sm - $sm / $p + $mdm[$i];
        $pdi = $atr > 0 ? 100 * $sp / $atr : 0; $mdi = $atr > 0 ? 100 * $sm / $atr : 0;
        $dx[$i] = ($pdi + $mdi) > 0 ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0;
        if ($i === 2 * $p) { $a = array_sum(array_slice($dx, 0, $p)) / $p; $out[$i] = $a; }
        elseif ($i > 2 * $p) { $a = ($out[$i-1] * ($p - 1) + $dx[$i]) / $p; $out[$i] = $a; }
    }
    return $out;
}

function variant(string $name): callable {
    return function (array $closed, string $sym) use ($name) {
        $cfg = [
            'base' => [65], 't75' => [75], 't80' => [80],
        ];
        $p = explode('+', $name);
        if (str_starts_with($name, 'mr')) return mr($closed, $sym, $name);
        if ($name === 'live') { $a = Engine::analyze($closed, 65, $sym); return ($a && $a['direction'] !== 'WAIT' && $a['confidence'] >= 65) ? $a : null; }
        $thr = in_array('t75', $p) ? 75 : (in_array('t80', $p) ? 80 : 65);
        $a = Engine::analyze($closed, $thr);
        if (!$a || $a['direction'] === 'WAIT' || $a['sl'] === null) return $a;
        $c = array_map(fn($x) => (float) $x['c'], $closed);
        $L = count($c) - 1; $dir = $a['direction'] === 'BUY' ? 1 : -1; $at = $a['atr']; $px = $c[$L];
        $e20 = Engine::ema($c, 20); $e50 = Engine::ema($c, 50); $e200 = Engine::ema($c, 200);
        $hour = (int) gmdate('G', $closed[$L]['t'] + 3600);
        foreach ($p as $f) {
            if (preg_match('/^adx(\d+)$/', $f, $m)) { $x = adx($closed); if (($x[$L] ?? 0) < (int) $m[1]) return null; }
            if (preg_match('/^pb([\d.]+)$/', $f, $m)) { if (abs($px - $e20[$L]) > (float) $m[1] * $at) return null; }      // near 20 EMA (no chasing)
            if ($f === 'sess') { if ($hour < 7 || $hour >= 17) return null; }                                               // London + NY
            if ($f === 'slope') { if (($e200[$L] - $e200[$L - 24]) * $dir <= 0 || ($e50[$L] - $e50[$L - 12]) * $dir <= 0) return null; }
            if ($f === 'htf') { // 4h trend via 4h-sampled closes EMA50 (~ last 200 4h bars not available: use 300h → 75 bars) 
                $h4 = []; for ($i = $L % 4; $i <= $L; $i += 4) $h4[] = $c[$i];
                $e = Engine::ema($h4, 50); $k = count($h4) - 1; if ($e[$k] === null || ($h4[$k] - $e[$k]) * $dir <= 0) return null; }
            if (preg_match('/^rsimax(\d+)$/', $f, $m)) { $r = $a['rsi']; if ($dir === 1 ? $r > (int) $m[1] : $r < 100 - (int) $m[1]) return null; }
            if (preg_match('/^atrq$/', $f)) { $atrs = Engine::atr($closed, 14); $hist = array_filter(array_slice($atrs, -240)); sort($hist);
                $q = $hist[(int) (count($hist) * 0.2)]; if ($at < $q) return null; }                                    // skip dead, low-volatility markets
            if (preg_match('/^geo([\d.]+)_([\d.]+)_([\d.]+)$/', $f, $m)) { [$s, $t1, $t2] = [(float) $m[1], (float) $m[2], (float) $m[3]];
                $a['sl'] = $px - $dir * $s * $at; $a['tp1'] = $px + $dir * $t1 * $at; $a['tp2'] = $px + $dir * $t2 * $at; }
        }
        return $a;
    };
}

function sma(array $v, int $p, int $L): float { return array_sum(array_slice($v, $L - $p + 1, $p)) / $p; }
function sd(array $v, int $p, int $L): float { $m = sma($v, $p, $L); $s = 0; for ($i = $L - $p + 1; $i <= $L; $i++) $s += ($v[$i] - $m) ** 2; return sqrt($s / $p); }
/** mr_k2_adx20_s1.5_a1_b2_r30_x  (x = also require a reversal candle) */
function mr(array $closed, string $sym, string $name): ?array {
    preg_match_all('/_([a-z]+)([\d.]+)/', $name, $m, PREG_SET_ORDER); $o = ['k' => 2, 'adx' => 20, 's' => 1.5, 'a' => 1, 'b' => 2, 'r' => 0, 'e' => 0, 'h' => -1];
    foreach ($m as $x) $o[$x[1]] = (float) $x[2];
    $c = array_map(fn($x) => (float) $x['c'], $closed); $L = count($c) - 1; $px = $c[$L];
    $at = Engine::atr($closed, 14)[$L]; if (!$at) return null;
    $ad = adx($closed)[$L] ?? 99; if ($ad >= $o['adx']) return null;
    $mid = sma($c, 20, $L); $dev = sd($c, 20, $L);
    $dir = 0;
    if ($px < $mid - $o['k'] * $dev) $dir = 1; elseif ($px > $mid + $o['k'] * $dev) $dir = -1; else return null;
    if ($o['r'] > 0) { $r = Engine::rsi($c, 14)[$L]; if ($dir === 1 ? $r > $o['r'] : $r < 100 - $o['r']) return null; }
    if ($o['e'] > 0) { $e = Engine::ema($c, 200)[$L]; if (abs($px - $e) > $o['e'] * $at) return null; } // not in a runaway trend
    if (str_contains($name, '_x')) { $b = $closed[$L]; if (($b['c'] - $b['o']) * $dir <= 0) return null; }
    $hour = (int) gmdate('G', $closed[$L]['t'] + 3600);
    if ($o['h'] >= 0 && str_contains($name, '_asia') && !($hour >= 21 || $hour < 7)) return null;
    if (str_contains($name, '_day') && ($hour < 7 || $hour >= 20)) return null;
    $t1 = $o['a'] > 0 ? $o['a'] * $at : abs($mid - $px);
    return ['direction' => $dir === 1 ? 'BUY' : 'SELL', 'confidence' => 70, 'entry' => $px, 'atr' => $at, 'rsi' => 50, 'reasons' => [],
        'sl' => $px - $dir * $o['s'] * $at, 'tp1' => $px + $dir * $t1, 'tp2' => $px + $dir * max($o['b'] * $at, $t1 * 1.5)];
}

function stats(array $trades, array $win, callable $cost): array {
    $r = []; foreach ($trades as $t) {
        if ($t['entry_t'] < $win[0] || $t['entry_t'] >= $win[1] || $t['status'] !== 'closed') continue;
        $net = $t['gross_pips'] - $cost($t['symbol']); $r[] = [$t['closed_t'], $net / $t['risk_pips'], $t['symbol'], $t['result']];
    }
    usort($r, fn($a, $b) => $a[0] <=> $b[0]);
    $n = count($r); $w = 0; $sum = 0; $eq = 0; $pk = 0; $dd = 0; $tp1 = 0; $byS = [];
    foreach ($r as [$t, $x, $s, $res]) { if ($x > 0) $w++; if ($res !== 'sl') $tp1++; $sum += $x; $eq += $x; $pk = max($pk, $eq); $dd = max($dd, $pk - $eq); $byS[$s] = ($byS[$s] ?? 0) + $x; }
    $weeks = ($win[1] - $win[0]) / 604800;
    ksort($byS);
    return ['n' => $n, 'per_wk' => round($n / $weeks, 1), 'win%' => $n ? round(100 * $w / $n, 1) : 0, 'tp1%' => $n ? round(100 * $tp1 / $n, 1) : 0,
        'expR' => $n ? round($sum / $n, 3) : 0, 'totR' => round($sum, 1), 'ddR' => round($dd, 1), 'pairs' => array_map(fn($v) => round($v, 1), $byS)];
}

$variants = array_slice($argv, 3) ?: ["base"]; if (getenv("EXITS") === "none") $variants = [];
foreach (explode(',', getenv('EXITS') ?: 'tp1-full') as $exit) {
  foreach ($variants as $v) {
    $opt = ['cost-pips' => 0.0, 'min-confidence' => 0, 'cooldown-hours' => 4, 'expiry-hours' => 72, 'max-entry-drift-atr' => 0.5, 'exit' => $exit];
    $trades = bt_simulate($series, $opt, variant($v));
    $a = stats($trades, $GLOBALS['IS'], $COST); $b = stats($trades, $GLOBALS['OOS'], $COST);
    printf("%-9s %-34s | IS n=%4d %4.1f/wk win=%5.1f%% tp1=%5.1f%% exp=%+.3fR tot=%+6.1fR dd=%5.1fR | OOS n=%4d %4.1f/wk win=%5.1f%% tp1=%5.1f%% exp=%+.3fR tot=%+6.1fR dd=%5.1fR\n",
      $exit, $v, $a['n'], $a['per_wk'], $a['win%'], $a['tp1%'], $a['expR'], $a['totR'], $a['ddR'], $b['n'], $b['per_wk'], $b['win%'], $b['tp1%'], $b['expR'], $b['totR'], $b['ddR']);
    if ($only === 'pairs') echo '   IS ', json_encode($a['pairs']), "\n   OOS ", json_encode($b['pairs']), "\n";
  }
}
