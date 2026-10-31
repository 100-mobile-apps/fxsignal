<?php
declare(strict_types=1);

require __DIR__ . '/backtest.php';
require_once __DIR__ . '/../api/src/Cron.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function near(float $actual, float $expected, string $message): void
{
    check(abs($actual - $expected) < 1e-7, "$message: expected $expected, got $actual");
}

function fails(callable $call, string $contains): void
{
    try {
        $call();
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), $contains), 'Unexpected error: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException("Expected error containing: $contains");
}

$options = bt_options(['backtest.php', '--data', 'unused.csv', '--cost-pips', '2', '--train-months', '1']);
check($options['exit'] === 'tp1-half', 'Default must use explicit 50/50 TP1 model');
near($options['max-entry-drift-atr'], 0.5, 'Default publication drift cap');
check(TradingPolicy::canPublish(strtotime('2024-01-19T20:59:00Z')), 'Friday before cutoff is eligible');
check(!TradingPolicy::canPublish(strtotime('2024-01-19T21:00:00Z')), 'Friday cutoff pauses new signals');
check(!TradingPolicy::canPublish(strtotime('2024-01-20T12:00:00Z')), 'Saturday is paused');
check(!TradingPolicy::canPublish(strtotime('2024-01-21T23:59:00Z')), 'Sunday is paused');
check(TradingPolicy::canPublish(strtotime('2024-01-22T00:00:00Z')), 'Monday resumes publication');
$publish = new ReflectionMethod(Cron::class, 'maybeOpen');
check(str_contains($publish->invoke(null, 'EUR/USD', '1h', 3600, [], [],
    strtotime('2024-01-20T12:00:00Z')), 'weekend'),
    'Cron must reject weekend publication before DB access or notification');
$oldQuote = [['t' => strtotime('2024-01-22T13:00:00Z'), 'c' => 1.1]];
check(str_contains($publish->invoke(null, 'EUR/USD', '1h', 3600, $oldQuote, $oldQuote,
    strtotime('2024-01-22T15:20:00Z')), 'no fresh forming-candle'),
    'Cron must not reuse the analyzed closed candle as a fresh live entry');
check(TradingPolicy::freshFormingQuote(strtotime('2024-01-22T15:00:00Z'), 3600,
    strtotime('2024-01-22T15:20:00Z')), 'Current forming candle is fresh');
check(!TradingPolicy::freshFormingQuote(strtotime('2024-01-22T13:00:00Z'), 3600,
    strtotime('2024-01-22T15:20:00Z')), 'Old quote cannot enter a new signal');
check(TradingPolicy::entryWithinAtr(1.1005, 1.1, 0.001, 0.5), 'Boundary at half ATR is eligible');
check(!TradingPolicy::entryWithinAtr(1.1006, 1.1, 0.001, 0.5), 'Extended live entry is rejected');
check(!TradingPolicy::entryWithinAtr(1.1, 1.1, 0, 0.5), 'Zero ATR is not safe to publish');
$options['max-entry-drift-atr'] = 10.0; // Test exit mechanics with a deliberately shifted synthetic entry.
$start = strtotime('2024-01-03T00:00:00Z');
$bars = [];
for ($i = 0; $i < 302; $i++) {
    $bars[] = ['t' => $start + 3600 * $i, 'o' => 1.1, 'h' => 1.1002, 'l' => 1.0998, 'c' => 1.1];
}
$bars[300] = ['t' => $bars[300]['t'], 'o' => 1.101, 'h' => 1.1012, 'l' => 1.1008, 'c' => 1.101];
$bars[301] = ['t' => $bars[301]['t'], 'o' => 1.1021, 'h' => 1.1022, 'l' => 1.101, 'c' => 1.101];
$seen = [];
$analyzer = static function (array $closed, string $symbol) use (&$seen): array {
    $seen[] = [$symbol, count($closed), $closed[299]['t'], $closed[299]['h']];
    return ['direction' => 'BUY', 'confidence' => 80, 'entry' => 1.1, 'atr' => 0.001,
        'sl' => 1.099, 'tp1' => 1.101, 'tp2' => 1.102];
};
$strict = $options;
$strict['max-entry-drift-atr'] = 0.5;
check(bt_simulate(['EUR/USD' => $bars], $strict, $analyzer) === [], 'No entry after a >0.5 ATR price shift');
$weekendBars = $bars;
foreach ($weekendBars as $i => &$bar) $bar['t'] = strtotime('2024-01-01T00:00:00Z') + $i * 3600;
unset($bar);
check(bt_simulate(['EUR/USD' => $weekendBars], $options, $analyzer) === [], 'No weekend publication despite a valid setup');
check(Engine::analyze(array_slice($bars, 0, 199)) === null, 'Require a complete 200 EMA history');
$invalidBars = $bars;
$invalidBars[299]['h'] = 0;
check(Engine::analyze(array_slice($invalidBars, 0, 300)) === null, 'Invalid market candles cannot produce a signal');
check(!TradingPolicy::validCandles($invalidBars), 'Malformed candle must be rejected before tracking an open signal');
$outOfOrder = $bars;
$outOfOrder[299]['t'] = $outOfOrder[298]['t'];
check(!TradingPolicy::validCandles($outOfOrder), 'Duplicate candle timestamps must not reach the tracker');
check(!TradingPolicy::validCandles($bars, $bars[298]['t']),
    'Future-dated candles must not settle a running signal before they exist');
check(TradingPolicy::validCandles($bars, $bars[301]['t']),
    'A forming candle at the observed timestamp remains valid');
$directions = [];
foreach ([1 => 'BUY', -1 => 'SELL'] as $trend => $expected) {
    for ($phase = 0; $phase < 32; $phase++) {
        $candidate = [];
        for ($i = 0; $i < 300; $i++) {
            $close = 1.1 + $trend * $i * 0.00002 + sin(($i + $phase) * 0.26) * 0.0005;
            $open = $close - $trend * 0.00001;
            $candidate[] = ['t' => $start + 3600 * $i, 'o' => $open,
                'h' => max($open, $close) + 0.00008, 'l' => min($open, $close) - 0.00008, 'c' => $close];
        }
        $analysis = Engine::analyze($candidate, 65);
        if ($analysis['direction'] === $expected) $directions[$expected] = true;
    }
}
check(isset($directions['BUY'], $directions['SELL']), 'Quality filters must still permit aligned up/down setups');
$extended = [];
for ($i = 0; $i < 300; $i++) {
    $close = 1.1 + $i * 0.00003;
    $extended[] = ['t' => $start + $i * 3600, 'o' => $close,
        'h' => $close + 0.00008, 'l' => $close - 0.00008, 'c' => $close];
}
check(Engine::analyze($extended, 65)['direction'] === 'WAIT',
    'Uninterrupted overbought trend must not be mistaken for a fresh high-quality entry');
$options['exit'] = 'tp2-be';
$trades = bt_simulate(['EUR/USD' => $bars], $options, $analyzer);
check(count($trades) === 1 && $trades[0]['entry'] === 1.101, 'Entry must use next bar open');
check($seen[0] === ['EUR/USD', 300, $bars[299]['t'], $bars[299]['h']], 'Analyzer must see only 300 closed bars');
check($trades[0]['result'] === 'be', 'TP1 and return to entry in one bar must settle at BE, not TP1/TP2');
near($trades[0]['gross_pips'], 0.0, 'BE gross');
near($trades[0]['net_pips'], -2.0, 'BE costs');
near($trades[0]['net_r'], -0.2, 'BE net R');
$summary = bt_summary($trades, $start, $start + 20 * 86400);
check($summary['resolved'] === 1 && $summary['losses'] === 1 && $summary['wins'] === 0, 'Outcome classification after costs');
near($summary['closed_trade_max_drawdown_r'], 0.2, 'Peak-to-trough closed-trade drawdown');
$options['exit'] = 'tp1-half';
$half = bt_simulate(['EUR/USD' => $bars], $options, $analyzer);
check($half[0]['result'] === 'be', 'Half of the position closes at TP1 and the remainder at entry');
near($half[0]['gross_pips'], 5.0, 'Half TP1 and half BE gross pips');
near($half[0]['net_pips'], 3.0, 'Half TP1 and half BE net pips');
near($half[0]['net_r'], 0.3, 'Half TP1 and half BE net R');
near(bt_pip_size('XAU/USD'), 0.1, 'Gold pip size');
near(bt_pip_size('USD/JPY'), 0.01, 'JPY pip size');
near(bt_pip_size('JPY/CHF'), 0.01, 'Match persisted live JPY-base pip convention');
near(bt_pip_size('EUR/USD'), 0.0001, 'Other FX pip size');

$options['exit'] = 'tp1-full';
$full = bt_simulate(['EUR/USD' => $bars], $options, $analyzer);
check($full[0]['result'] === 'tp1', 'TP1-full closes entire position at TP1');
near($full[0]['net_pips'], 8.0, 'TP1 net pips after costs');

$ambiguous = $bars;
$ambiguous[300]['h'] = 1.103;
$ambiguous[300]['l'] = 1.099;
$stopped = bt_simulate(['EUR/USD' => $ambiguous], $options, $analyzer);
check($stopped[0]['result'] === 'sl', 'Same bar stop and target must be stop-first');
near($stopped[0]['net_pips'], -12.0, 'Stop loss including costs');
check($seen[0][2] === $ambiguous[299]['t'], 'Current-bar extremes cannot enter analysis');

$options['exit'] = 'tp1-half';
$uncertain = $bars;
$uncertain[301]['o'] = 1.101;
for ($i = 302; $i < 306; $i++) {
    $uncertain[] = ['t' => $start + 3600 * $i, 'o' => 1.101, 'h' => 1.1012, 'l' => 1.1008, 'c' => 1.101];
}
$uncertainTrade = bt_simulate(['EUR/USD' => $uncertain], $options, $analyzer);
check($uncertainTrade[0]['status'] === 'skipped_ambiguous_tp1', 'Do not credit an unordered TP1/entry touch as a win');
check(count($uncertainTrade) === 1, 'Ambiguous outcome must not free a slot for another immediate signal');
$uncertainSummary = bt_summary($uncertainTrade, $start, $start + 20 * 86400);
check($uncertainSummary['resolved'] === 0 && $uncertainSummary['skipped_ambiguous_tp1'] === 1,
    'Ambiguous signals must be visible as exclusions, not wins');
$otherPair = $uncertainTrade[0];
$otherPair['symbol'] = 'GBP/USD';
$mixed = bt_summary([$half[0], $otherPair], $start, $start + 20 * 86400);
near($mixed['resolved_trade_win_rate_pct'], 100.0, 'Resolved-only rate must be labeled');
near($mixed['all_entry_win_rate_lower_bound_pct'], 50.0, 'Unknown outcomes must reduce the conservative bound');
near($mixed['resolved_fraction_pct'], 50.0, 'Report fraction of outcomes actually known');

$options['exit'] = 'tp2-be';
$gap = $bars;
$gap[301]['o'] = 1.104;
$gap[301]['h'] = 1.104;
$gap[301]['l'] = 1.101;
$throughTarget = bt_simulate(['EUR/USD' => $gap], $options, $analyzer);
check($throughTarget[0]['result'] === 'tp2', 'Target reached at known open precedes bar low');
$options['exit'] = 'tp1-half';
$halfTp2 = bt_simulate(['EUR/USD' => $gap], $options, $analyzer);
near($halfTp2[0]['net_pips'], 13.0, 'Half TP1 and half TP2 net pips');

$options['expiry-hours'] = 1;
$exactExpiry = bt_simulate(['EUR/USD' => $bars], $options, $analyzer);
check($exactExpiry[0]['result'] === 'expiry_open', 'Expiry must fill at the exact next bar open before its range');
$missingExpiry = $bars;
$missingExpiry[301]['t'] += 3600;
$skipped = bt_simulate(['EUR/USD' => $missingExpiry], $options, $analyzer);
check($skipped[0]['status'] === 'skipped_expiry_gap', 'Gap past expiry must be labeled as expiry gap');
$options['expiry-hours'] = 72;
$inTradeGap = bt_simulate(['EUR/USD' => $missingExpiry], $options, $analyzer);
check($inTradeGap[0]['status'] === 'skipped_data_gap', 'No invented fill or later target across a missing in-trade bar');
$gapSummary = bt_summary($inTradeGap, $start, $start + 20 * 86400);
check($gapSummary['resolved'] === 0 && $gapSummary['skipped_data_gap'] === 1,
    'Missing in-trade candles are excluded from measured winners');
$completeCoverage = bt_period_coverage($bars, $bars[300]['t'], $bars[301]['t'] + 3600);
check($completeCoverage['complete_uninterrupted_bars'], 'Continuous per-pair data may be marked complete');
$gapCoverage = bt_period_coverage($missingExpiry, $bars[300]['t'], $missingExpiry[301]['t'] + 3600);
check(!$gapCoverage['complete_uninterrupted_bars'] && $gapCoverage['hourly_gaps_or_market_closures'] === 1,
    'Coverage must expose an in-period missing hour');
$shortCoverage = bt_period_coverage(array_slice($bars, 0, 301), $bars[300]['t'], $bars[301]['t'] + 3600);
check(!$shortCoverage['data_reaches_end'], 'Truncated pair cannot complete test period');
$noEligible = bt_period_coverage(array_slice($bars, 0, 300), $bars[299]['t'], $bars[299]['t'] + 3600);
check(!$noEligible['warmup_ready_at_start'] && !$noEligible['complete_uninterrupted_bars'],
    'Exactly 300 bars have no eligible test entry and cannot mark a fold complete');

fails(static fn() => bt_load_csv(sys_get_temp_dir() . '/missing-backtest-' . uniqid() . '.csv'), 'Cannot read dataset');
fails(static fn() => bt_options(['backtest.php', '--data', 'x.csv']), '--cost-pips');
fails(static fn() => bt_options(['backtest.php', '--data', 'x.csv', '--cost-pips', '-1']), '--cost-pips');
fails(static fn() => bt_options(['backtest.php', '--data', 'x.csv', '--cost-pips', '1', '--max-entry-drift-atr', '-1']), '--max-entry-drift-atr');

$temp = tempnam(sys_get_temp_dir(), 'bt_csv_');
if ($temp === false) throw new RuntimeException('Cannot create temporary test CSV');
try {
    file_put_contents($temp, "symbol,time,open,high,low,close\nEUR/USD,2024-01-01T00:00:00Z,1,1,1,1\nEUR/USD,2024-01-01T00:00:00Z,1,1,1,1\n");
    fails(static fn() => bt_load_csv($temp), 'Duplicate');
    file_put_contents($temp, "symbol,time,open,high,low,close\nEUR/USD,2024-01-01T00:00:00Z,1,0.5,1,1\n");
    fails(static fn() => bt_load_csv($temp), 'Invalid OHLC');
    file_put_contents($temp, "symbol,time,open,high,low,close\nEUR/USD,2024-01-01T01:00:00Z,1,1,1,1\nEUR/USD,2024-01-01T00:00:00Z,1,1,1,1\n");
    fails(static fn() => bt_load_csv($temp), 'out-of-order');
    file_put_contents($temp, "symbol,time,open,high,low,close\nEUR/USD,2024-02-30T00:00:00Z,1,1,1,1\n");
    fails(static fn() => bt_load_csv($temp), 'Invalid timestamp');
} finally {
    unlink($temp);
}

$sparse = array_slice($bars, 0, 300);
$sparse[] = ['t' => strtotime('2024-03-01T00:00:00Z'), 'o' => 1.1, 'h' => 1.1, 'l' => 1.1, 'c' => 1.1];
$sparse[] = ['t' => strtotime('2024-04-01T00:00:00Z'), 'o' => 1.1, 'h' => 1.1, 'l' => 1.1, 'c' => 1.1];
$shortPair = array_slice($sparse, 0, 301);
$sparse[] = ['t' => strtotime('2024-05-01T00:00:00Z'), 'o' => 1.1, 'h' => 1.1, 'l' => 1.1, 'c' => 1.1];
$report = bt_report(['EUR/USD' => $sparse, 'GBP/USD' => $shortPair,
    'USD/CHF' => array_slice($sparse, 0, 300)], $options);
check($report['folds'][0]['test_utc'] === ['2024-04-01', '2024-05-01'], 'Calendar UTC fold boundaries');
check($report['folds'][0]['per_pair']['EUR/USD']['coverage']['data_reaches_end'], 'First pair has an observed test endpoint');
check(!$report['folds'][0]['per_pair']['GBP/USD']['coverage']['data_reaches_end'], 'Missing second pair cannot borrow first pair coverage');
check(!$report['folds'][0]['per_pair']['USD/CHF']['test_coverage']['warmup_ready_at_start'],
    'Pair with exactly 300 bars cannot claim test warmup');
check($report['folds'][0]['complete_period'] === false, 'Partial historical coverage must be flagged');
check($report['folds'][0]['test']['resolved'] === 0, 'Empty OOS fold must not invent performance');

echo "backtest self-test passed\n";
