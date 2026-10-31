<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/src/Engine.php';
require_once __DIR__ . '/../api/src/TradingPolicy.php';

function bt_options(array $argv): array
{
    $allowed = ['data', 'cost-pips', 'train-months', 'test-months', 'min-confidence', 'cooldown-hours', 'expiry-hours', 'max-entry-drift-atr', 'exit'];
    $values = [];
    for ($i = 1; $i < count($argv); $i++) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $argv[$i], $m) || !in_array($m[1], $allowed, true) || isset($values[$m[1]])) {
            throw new InvalidArgumentException('Unknown, repeated or malformed option: ' . $argv[$i]);
        }
        $key = $m[1];
        $value = array_key_exists(2, $m) ? $m[2] : ($argv[++$i] ?? '');
        if ($value === '' || str_starts_with($value, '--')) throw new InvalidArgumentException("Missing value for --$key");
        $values[$key] = $value;
    }
    foreach (['data', 'cost-pips'] as $key) {
        if (!isset($values[$key])) throw new InvalidArgumentException("Required option --$key is missing");
    }
    $options = ['data' => $values['data'], 'exit' => $values['exit'] ?? 'tp1-half'];
    if (!in_array($options['exit'], ['tp1-full', 'tp1-half', 'tp2-be'], true)) throw new InvalidArgumentException('Invalid --exit');
    foreach (['train-months' => 6, 'test-months' => 1, 'min-confidence' => 65, 'cooldown-hours' => 4, 'expiry-hours' => 72] as $key => $default) {
        $value = $values[$key] ?? (string) $default;
        if (!preg_match('/^\d+$/D', $value) || (float) $value > PHP_INT_MAX) throw new InvalidArgumentException("Invalid --$key");
        $options[$key] = (int) $value;
    }
    if ($options['train-months'] < 1 || $options['test-months'] < 1 || $options['expiry-hours'] < 1 || $options['min-confidence'] > 100 ||
        $options['train-months'] > 1200 || $options['test-months'] > 1200 ||
        $options['expiry-hours'] > intdiv(PHP_INT_MAX, 3600) || $options['cooldown-hours'] > intdiv(PHP_INT_MAX, 3600)) {
        throw new InvalidArgumentException('Train/test/expiry must be positive; confidence must be 0..100');
    }
    $cost = $values['cost-pips'];
    if (!is_numeric($cost) || !is_finite((float) $cost) || (float) $cost < 0) throw new InvalidArgumentException('Invalid --cost-pips (nonnegative total spread + slippage required)');
    $options['cost-pips'] = (float) $cost;
    $drift = $values['max-entry-drift-atr'] ?? '0.5';
    if (!is_numeric($drift) || !is_finite((float) $drift) || (float) $drift < 0) {
        throw new InvalidArgumentException('Invalid --max-entry-drift-atr');
    }
    $options['max-entry-drift-atr'] = (float) $drift;
    return $options;
}

function bt_load_csv(string $path): array
{
    if (!is_file($path) || !is_readable($path) || ($handle = @fopen($path, 'rb')) === false) {
        throw new InvalidArgumentException("Cannot read dataset: $path");
    }
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header !== false) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if ($header !== ['symbol', 'time', 'open', 'high', 'low', 'close']) throw new InvalidArgumentException('CSV header must be symbol,time,open,high,low,close');
        $series = [];
        $line = 1;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($row) !== 6) throw new InvalidArgumentException("Invalid CSV row at line $line");
            foreach ($row as $field) {
                if (preg_match('//u', $field) !== 1) throw new InvalidArgumentException("Invalid UTF-8 CSV row at line $line");
            }
            [$symbol, $time, $o, $h, $l, $c] = $row;
            if (!preg_match('/^[A-Z]{3}\/[A-Z]{3}$/D', $symbol) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:00:00(?:Z|\+00:00)$/D', $time)) {
                throw new InvalidArgumentException("Invalid symbol or UTC hourly bar-open timestamp at line $line");
            }
            $ts = strtotime($time);
            if ($ts === false || gmdate('Y-m-d\TH:i:s', $ts) !== substr($time, 0, 19)) throw new InvalidArgumentException("Invalid timestamp at line $line");
            $prices = [$o, $h, $l, $c];
            foreach ($prices as &$price) {
                if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) throw new InvalidArgumentException("Invalid price at line $line");
                $price = (float) $price;
            }
            unset($price);
            [$o, $h, $l, $c] = $prices;
            if ($l > min($o, $c) || $h < max($o, $c) || $l > $h) throw new InvalidArgumentException("Invalid OHLC at line $line");
            $last = isset($series[$symbol]) ? $series[$symbol][count($series[$symbol]) - 1]['t'] : null;
            if ($last !== null && ($ts <= $last || ($ts - $last) % 3600 !== 0)) {
                throw new InvalidArgumentException("Duplicate, out-of-order or off-grid bar for $symbol at line $line");
            }
            $series[$symbol][] = ['t' => $ts, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c];
        }
        if (!$series) throw new InvalidArgumentException('Dataset is empty');
        foreach ($series as $symbol => $bars) {
            if (count($bars) < 300) throw new InvalidArgumentException("$symbol needs at least 300 warmup candles");
        }
        ksort($series);
        return $series;
    } finally {
        fclose($handle);
    }
}

function bt_pip_size(string $symbol): float
{
    return str_starts_with($symbol, 'XAU/') ? 0.1 : (str_contains($symbol, 'JPY') ? 0.01 : 0.0001);
}

function bt_finish(array &$trade, string $result, float $price, int $at, float $cost): void
{
    $gross = ($price - $trade['entry']) * $trade['dir'] / $trade['pip'];
    if ($trade['exit'] === 'tp1-half' && $trade['tp1_hit']) {
        $firstLeg = ($trade['tp1'] - $trade['entry']) * $trade['dir'] / $trade['pip'];
        $gross = 0.5 * ($firstLeg + $gross);
    }
    $net = $gross - $cost;
    $trade['status'] = 'closed';
    $trade['result'] = $result;
    $trade['closed_t'] = $at;
    $trade['gross_pips'] = $gross;
    $trade['net_pips'] = $net;
    $trade['net_r'] = $net / $trade['risk_pips'];
}

function bt_check_bar(array &$trade, array $bar, array $options): bool
{
    $t = $bar['t'];
    if ($t >= $trade['expires']) {
        if ($t === $trade['expires']) bt_finish($trade, 'expiry_open', $bar['o'], $t, $options['cost-pips']);
        else { $trade['status'] = 'skipped_expiry_gap'; $trade['closed_t'] = $t; }
        return true;
    }
    $dir = $trade['dir'];
    $stop = $trade['tp1_hit'] ? $trade['entry'] : $trade['sl'];
    $hit = static fn(float $price, float $level): bool => ($price - $level) * $dir >= 0;
    $stopped = static fn(float $price, float $level): bool => ($price - $level) * $dir <= 0;
    // The open is observable first; a gap through a stop fills at the worse open.
    if ($stopped($bar['o'], $stop)) {
        bt_finish($trade, $trade['tp1_hit'] ? 'be' : 'sl', $bar['o'], $t + 3600, $options['cost-pips']);
        return true;
    }
    if ($trade['tp1_hit'] && $hit($bar['o'], $trade['tp2'])) {
        bt_finish($trade, 'tp2', $trade['tp2'], $t + 3600, $options['cost-pips']);
        return true;
    }
    if (!$trade['tp1_hit'] && $options['exit'] === 'tp2-be' && $hit($bar['o'], $trade['tp2'])) {
        bt_finish($trade, 'tp2', $trade['tp2'], $t + 3600, $options['cost-pips']);
        return true;
    }
    if (!$trade['tp1_hit'] && $options['exit'] === 'tp1-half' && $hit($bar['o'], $trade['tp2'])) {
        $trade['tp1_hit'] = true;
        bt_finish($trade, 'tp2', $trade['tp2'], $t + 3600, $options['cost-pips']);
        return true;
    }
    if (!$trade['tp1_hit'] && $hit($bar['o'], $trade['tp1'])) {
        if ($options['exit'] === 'tp1-full') {
            bt_finish($trade, 'tp1', $trade['tp1'], $t + 3600, $options['cost-pips']);
            return true;
        }
        $trade['tp1_hit'] = true;
        $stop = $trade['entry'];
    }
    $adverse = $dir === 1 ? $bar['l'] : $bar['h'];
    $favorable = $dir === 1 ? $bar['h'] : $bar['l'];
    if ($stopped($adverse, $stop)) {
        bt_finish($trade, $trade['tp1_hit'] ? 'be' : 'sl', $stop, $t + 3600, $options['cost-pips']);
        return true;
    }
    if (!$trade['tp1_hit'] && $hit($favorable, $trade['tp1'])) {
        if ($options['exit'] === 'tp1-full') {
            bt_finish($trade, 'tp1', $trade['tp1'], $t + 3600, $options['cost-pips']);
            return true;
        }
        // Hourly OHLC cannot order a fresh TP1 touch against a return to entry.
        if ($stopped($adverse, $trade['entry'])) {
            $trade['status'] = 'skipped_ambiguous_tp1';
            $trade['closed_t'] = $t + 3600;
            return true;
        }
        $trade['tp1_hit'] = true;
    }
    if ($trade['tp1_hit'] && $stopped($adverse, $trade['entry'])) {
        bt_finish($trade, 'be', $trade['entry'], $t + 3600, $options['cost-pips']);
        return true;
    }
    if ($trade['tp1_hit'] && $hit($favorable, $trade['tp2'])) {
        bt_finish($trade, 'tp2', $trade['tp2'], $t + 3600, $options['cost-pips']);
        return true;
    }
    return false;
}

function bt_simulate(array $series, array $options, ?callable $analyzer = null): array
{
    $trades = [];
    foreach ($series as $symbol => $bars) {
        $active = null;
        $cooldownUntil = 0;
        $pip = bt_pip_size($symbol);
        for ($i = 300, $n = count($bars); $i < $n; $i++) {
            $bar = $bars[$i];
            $occupied = $active !== null;
            if ($occupied && $bar['t'] !== $bars[$i - 1]['t'] + 3600) {
                $trades[$active]['status'] = $bar['t'] > $trades[$active]['expires']
                    ? 'skipped_expiry_gap' : 'skipped_data_gap';
                $trades[$active]['closed_t'] = $bar['t'];
                $cooldownUntil = max($trades[$active]['expires'], $bar['t']) + $options['cooldown-hours'] * 3600;
                $active = null;
                continue;
            }
            if ($occupied && bt_check_bar($trades[$active], $bar, $options)) {
                $settled = $trades[$active];
                // An ambiguous trade may still be open in reality; reserve its slot to expiry.
                $cooldownUntil = ($settled['status'] === 'skipped_ambiguous_tp1'
                    ? $settled['expires'] : $settled['closed_t']) + $options['cooldown-hours'] * 3600;
                $active = null;
            }
            if ($occupied || $active !== null || $bar['t'] < $cooldownUntil ||
                $bar['t'] !== $bars[$i - 1]['t'] + 3600 || !TradingPolicy::canPublish($bar['t'])) continue;
            // The current bar is not passed to the engine; only the last 300 closed bars are visible.
            $closed = array_slice($bars, $i - 300, 300);
            $a = $analyzer ? $analyzer($closed, $symbol) : Engine::analyze($closed, $options['min-confidence']);
            if (!$a || !in_array($a['direction'] ?? '', ['BUY', 'SELL'], true) || ($a['confidence'] ?? 0) < $options['min-confidence']) continue;
            $dir = $a['direction'] === 'BUY' ? 1 : -1;
            $entry = $bar['o'];
            if (!TradingPolicy::entryWithinAtr((float) $entry, (float) $a['entry'],
                (float) ($a['atr'] ?? 0), $options['max-entry-drift-atr'])) continue;
            $shift = $entry - (float) $a['entry'];
            $sl = (float) $a['sl'] + $shift;
            $tp1 = (float) $a['tp1'] + $shift;
            $tp2 = (float) $a['tp2'] + $shift;
            if (!is_finite($sl) || !is_finite($tp1) || !is_finite($tp2) || $entry <= 0 ||
                ($entry - $sl) * $dir <= 0 || ($tp1 - $entry) * $dir <= 0 || ($tp2 - $tp1) * $dir <= 0) continue;
            $active = count($trades);
            $trades[] = [
                'symbol' => $symbol, 'entry_t' => $bar['t'], 'entry' => $entry, 'dir' => $dir,
                'sl' => $sl, 'tp1' => $tp1, 'tp2' => $tp2, 'pip' => $pip,
                'risk_pips' => abs($entry - $sl) / $pip,
                'expires' => $bar['t'] + $options['expiry-hours'] * 3600,
                'tp1_hit' => false, 'exit' => $options['exit'], 'status' => 'open',
            ];
            if (bt_check_bar($trades[$active], $bar, $options)) {
                $settled = $trades[$active];
                $cooldownUntil = ($settled['status'] === 'skipped_ambiguous_tp1'
                    ? $settled['expires'] : $settled['closed_t']) + $options['cooldown-hours'] * 3600;
                $active = null;
            }
        }
    }
    return $trades;
}

function bt_summary(array $trades, int $start, int $end): array
{
    $entries = array_values(array_filter($trades, static fn($t) => $t['entry_t'] >= $start && $t['entry_t'] < $end));
    $months = $weeks = [];
    $resolved = [];
    $skipped = $ambiguous = $dataGaps = 0;
    foreach ($entries as $trade) {
        $month = gmdate('Y-m', $trade['entry_t']);
        $week = gmdate('o-\WW', $trade['entry_t']);
        $months[$month] = ($months[$month] ?? 0) + 1;
        $weeks[$week] = ($weeks[$week] ?? 0) + 1;
        if ($trade['status'] === 'closed' && $trade['closed_t'] <= $end) $resolved[] = $trade;
        if ($trade['status'] === 'skipped_expiry_gap' && $trade['closed_t'] <= $end) $skipped++;
        if ($trade['status'] === 'skipped_ambiguous_tp1' && $trade['closed_t'] <= $end) $ambiguous++;
        if ($trade['status'] === 'skipped_data_gap' && $trade['closed_t'] <= $end) $dataGaps++;
    }
    ksort($months);
    ksort($weeks);
    usort($resolved, static fn($a, $b) => [$a['closed_t'], $a['symbol'], $a['entry_t']] <=> [$b['closed_t'], $b['symbol'], $b['entry_t']]);
    $wins = $losses = $zeros = 0;
    $sum = $equity = $peak = $drawdown = 0.0;
    foreach ($resolved as $trade) {
        $net = $trade['net_pips'];
        if ($net > 1e-9) $wins++;
        elseif ($net < -1e-9) $losses++;
        else $zeros++;
        $sum += $trade['net_r'];
        $equity += $trade['net_r'];
        $peak = max($peak, $equity);
        $drawdown = max($drawdown, $peak - $equity);
    }
    $count = count($resolved);
    $monthCount = ((int) gmdate('Y', $end) - (int) gmdate('Y', $start)) * 12 +
        (int) gmdate('n', $end) - (int) gmdate('n', $start);
    return [
        'signals' => count($entries), 'resolved' => $count,
        'censored' => count($entries) - $count - $skipped - $ambiguous - $dataGaps,
        'skipped_expiry_gap' => $skipped, 'skipped_ambiguous_tp1' => $ambiguous, 'skipped_data_gap' => $dataGaps,
        'wins' => $wins, 'losses' => $losses, 'zeros' => $zeros,
        'resolved_trade_win_rate_pct' => $count ? round(100 * $wins / $count, 2) : null,
        'all_entry_win_rate_lower_bound_pct' => $entries ? round(100 * $wins / count($entries), 2) : null,
        'resolved_fraction_pct' => $entries ? round(100 * $count / count($entries), 2) : null,
        'resolved_trade_net_expectancy_r' => $count ? round($sum / $count, 4) : null,
        'closed_trade_net_r_total' => round($sum, 4), 'closed_trade_max_drawdown_r' => round($drawdown, 4),
        'signals_per_week' => round(count($entries) * 604800 / ($end - $start), 3),
        'signals_per_month' => round(count($entries) / max(1, $monthCount), 3),
        'signals_by_month' => $months, 'signals_by_utc_iso_week' => $weeks,
    ];
}

function bt_period_coverage(array $bars, int $start, int $end): array
{
    $firstEligible = $bars[300]['t'] ?? null;
    $lastEnd = $bars[count($bars) - 1]['t'] + 3600;
    $gaps = 0;
    for ($i = 1, $n = count($bars); $i < $n; $i++) {
        $gapStart = $bars[$i - 1]['t'] + 3600;
        $gapEnd = $bars[$i]['t'];
        if ($gapStart < $end && $gapEnd > $start && $gapEnd > $gapStart) $gaps++;
    }
    return [
        'warmup_ready_at_start' => $firstEligible !== null && $firstEligible <= $start,
        'data_reaches_end' => $lastEnd >= $end,
        'hourly_gaps_or_market_closures' => $gaps,
        'complete_uninterrupted_bars' => $firstEligible !== null && $firstEligible <= $start && $lastEnd >= $end && $gaps === 0,
    ];
}

function bt_report(array $series, array $options): array
{
    $trades = bt_simulate($series, $options);
    $first = PHP_INT_MAX;
    $last = 0;
    foreach ($series as $bars) {
        if (count($bars) > 300) $first = min($first, $bars[300]['t']);
        $last = max($last, $bars[count($bars) - 1]['t']);
    }
    if ($first === PHP_INT_MAX) throw new InvalidArgumentException('No candles after the 300-bar warmup');
    $utc = new DateTimeZone('UTC');
    $origin = (new DateTimeImmutable('@' . $first))->setTimezone($utc)->modify('first day of this month')->setTime(0, 0);
    $trainLength = $options['train-months'];
    $testLength = $options['test-months'];
    $folds = [];
    $symbols = array_keys($series);
    $coverage = [];
    foreach ($series as $symbol => $bars) {
        $coverage[$symbol] = [
            'bars' => count($bars), 'first_bar_utc' => gmdate('Y-m-d\TH:i:s\Z', $bars[0]['t']),
            'last_bar_utc' => gmdate('Y-m-d\TH:i:s\Z', $bars[count($bars) - 1]['t']),
        ];
    }
    for ($trainStart = $origin; ; $trainStart = $trainStart->modify("+$testLength months")) {
        $testStart = $trainStart->modify("+$trainLength months");
        if ($testStart->getTimestamp() > $last) break;
        $testEnd = $testStart->modify("+$testLength months");
        $trainEndTs = $testStart->getTimestamp();
        $testEndTs = $testEnd->getTimestamp();
        $fold = [
            'train_utc' => [$trainStart->format('Y-m-d'), $testStart->format('Y-m-d')],
            'test_utc' => [$testStart->format('Y-m-d'), $testEnd->format('Y-m-d')],
            'partial_train_data' => false,
            'partial_test_data' => false,
            'complete_period' => true,
            'hourly_gaps_or_market_closures' => 0,
            'train' => bt_summary($trades, $trainStart->getTimestamp(), $trainEndTs),
            'test' => bt_summary($trades, $trainEndTs, $testEndTs),
            'per_pair' => [],
        ];
        foreach ($symbols as $symbol) {
            $pairCoverage = bt_period_coverage($series[$symbol], $trainStart->getTimestamp(), $testEndTs);
            $testCoverage = bt_period_coverage($series[$symbol], $trainEndTs, $testEndTs);
            $fold['partial_train_data'] = $fold['partial_train_data'] || !$pairCoverage['warmup_ready_at_start'];
            $fold['partial_test_data'] = $fold['partial_test_data'] ||
                !$testCoverage['warmup_ready_at_start'] || !$testCoverage['data_reaches_end'];
            $fold['hourly_gaps_or_market_closures'] += $pairCoverage['hourly_gaps_or_market_closures'];
            $fold['complete_period'] = $fold['complete_period'] && $pairCoverage['complete_uninterrupted_bars'];
            $pairTrades = array_values(array_filter($trades, static fn($t) => $t['symbol'] === $symbol));
            $fold['per_pair'][$symbol] = [
                'coverage' => $pairCoverage,
                'test_coverage' => $testCoverage,
                'train' => bt_summary($pairTrades, $trainStart->getTimestamp(), $trainEndTs),
                'test' => bt_summary($pairTrades, $trainEndTs, $testEndTs),
            ];
        }
        $folds[] = $fold;
    }
    if (!$folds) throw new InvalidArgumentException('Not enough calendar months after warmup for a test fold');
    return [
        'model' => 'Hourly mid-price next-open scenario, not a replay or performance validation of the live :20/:40 cron strategy; bid/ask target touches and fills are unknown.',
        'entry_policy' => 'No new signals Friday 21:00 UTC through Monday 00:00 UTC; previously opened trades continue to be tracked. Closed-candle trend/RSI confirmation and max-entry-drift-atr are shared with the live engine.',
        'assumptions' => 'Fixed parameters, no fitting/selection on train data; rolling descriptive UTC periods are NOT out-of-sample after you use them to choose parameters. Coverage checks each pair; complete_period=false when any bar is missing, including expected market closures, until a trading calendar is supplied. Signals assigned by entry date; unresolved at fold end are censored. Stop-first intrabar, gaps through stops fill at open; targets capped at level. Fresh TP1 and entry touches in the same hourly bar are excluded for uncertain ordering and block new trades until expiry. Missing candles while in a trade mark its result unknown. Expiry at exact bar open, otherwise skipped. Gaps and partial per-pair coverage can understate signal frequency. Sum and drawdown use unlevered fixed-risk net R, not account equity.',
        'cost_pips_per_trade' => $options['cost-pips'], 'max_entry_drift_atr' => $options['max-entry-drift-atr'],
        'cost_model' => 'Explicit total spread + slippage deducted once from gross pair pips on every resolved trade; win/loss/zero and expectancy are net of cost. Costs and target-touch checks use mid-price approximations, not bid/ask fills.',
        'exit' => $options['exit'],
        'pip_size' => 'XAU/* 0.1; pairs containing JPY 0.01 (legacy live convention); otherwise 0.0001',
        'warmup_bars' => 300, 'coverage' => $coverage, 'folds' => $folds,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = bt_options($argv);
        echo json_encode(bt_report(bt_load_csv($options['data']), $options), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\nUsage: php backend/tests/backtest.php --data PATH --cost-pips N [--train-months 6] [--test-months 1] [--min-confidence 65] [--max-entry-drift-atr 0.5] [--cooldown-hours 4] [--expiry-hours 72] [--exit tp1-half|tp1-full|tp2-be]\n");
        exit(2);
    }
}
