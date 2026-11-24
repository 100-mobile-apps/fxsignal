<?php
// Local tests only: fake Paystack API. Loaded by dev-router.php when FX_PAYSTACK_MOCK=1.
// Checkout returns a link straight back to the site's return page; every started
// transaction is stored in tests/web/paystack_state.json, and tests can mark it paid
// with POST /__mock/paystack/pay?reference=... (which also sends a signed webhook).
require_once __DIR__ . '/../../api/src/Paystack.php';
const MOCK_STATE = __DIR__ . '/paystack_state.json';
function mock_state(): array { return is_file(MOCK_STATE) ? (json_decode((string) file_get_contents(MOCK_STATE), true) ?: []) : []; }
function mock_save(array $s): void { file_put_contents(MOCK_STATE, json_encode($s, JSON_PRETTY_PRINT)); }
Paystack::$http = function (string $method, string $path, ?array $body): array {
    $s = mock_state();
    if ($method === 'POST' && $path === '/transaction/initialize') {
        $ref = (string) $body['reference'];
        $s[$ref] = ['reference' => $ref, 'status' => 'abandoned', 'amount' => $body['amount'], 'currency' => $body['currency'],
            'domain' => 'live', 'metadata' => $body['metadata'], 'plan' => $body['plan'] ?? null,
            'customer' => ['email' => $body['email'], 'customer_code' => 'CUS_' . substr(md5($body['email']), 0, 10)]];
        mock_save($s);
        return ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/mock-' . $ref, 'reference' => $ref]];
    }
    if ($method === 'GET' && preg_match('#^/transaction/verify/(.+)$#', $path, $m)) {
        $t = $s[rawurldecode($m[1])] ?? null;
        if (!$t) throw new ApiError('Transaction reference not found', 422);
        return ['status' => true, 'data' => $t];
    }
    if ($method === 'POST' && $path === '/plan') return ['status' => true, 'data' => ['plan_code' => 'PLN_mock' . substr(md5(json_encode($body)), 0, 6)]];
    if ($method === 'POST' && $path === '/subscription/disable') return ['status' => true, 'data' => []];
    throw new ApiError("mock: unhandled $method $path", 500);
};
