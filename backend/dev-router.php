<?php
// Local development server for the whole backend (website + API + admin), mirroring the nginx config:
//   FX_CONFIG=/path/to/config.php php -S 127.0.0.1:8093 dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (getenv('FX_PAYSTACK_MOCK')) {
    require_once __DIR__ . '/tests/web/paystack_mock.php';
    // Test helper: mark a mock transaction paid (status only; the test then posts the signed webhook itself).
    if ($path === '/__mock/paystack/pay') {
        $s = mock_state(); $ref = (string) ($_GET['reference'] ?? '');
        if (!isset($s[$ref])) { http_response_code(404); echo 'unknown'; return true; }
        $s[$ref]['status'] = 'success'; if (isset($_GET['domain'])) $s[$ref]['domain'] = (string) $_GET['domain']; $s[$ref]['paid_at'] = gmdate('c', time() + (int) ($_GET['offset'] ?? 0));
        if (isset($_GET['newref'])) { $n = $s[$ref]; $n['reference'] = (string) $_GET['newref']; $s[$n['reference']] = $n; }
        mock_save($s); echo 'ok'; return true;
    }
}
if (preg_match('#^/api(/|$)#', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/api/index.php';
    return true;
}
if ($path === '/admin') { header('Location: /admin/', true, 301); return true; }
if (str_starts_with($path, '/admin/')) {
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require __DIR__ . '/admin/index.php';
    return true;
}
if (str_starts_with($path, '/assets/') && is_file(__DIR__ . '/web' . $path)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
    header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile(__DIR__ . '/web' . $path);
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/web/index.php';
return true;
