<?php
declare(strict_types=1);

// cPanel → Cron Jobs → every 20 minutes:
//   /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/api/cron/run.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../src/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/fx_signals_cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is in progress\n";
    exit(0);
}

$log = Cron::run(time(), fn(string $pair, string $tf) => TwelveData::series($pair, $tf, 1200));
echo '[' . gmdate('Y-m-d H:i:s') . "] \n" . implode("\n", $log) . "\n";
