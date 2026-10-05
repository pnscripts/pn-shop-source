<?php

// A local stand-in for PayPal's REST API (Orders v2, Payments v2, OAuth) and its approval
// page, for trying the PayPal plugin end to end without a PayPal account:
//
//     php -S 127.0.0.1:8125 tests/Support/PayPalStandIn/server.php
//
// and in the shop: 'paypal' => ['api_url' => env('PAYPAL_API_URL')] in config/services.php,
// PAYPAL_API_URL=http://127.0.0.1:8125 in .env, any client ID and secret in the plugin
// settings. It enforces approve-before-capture, one capture per order and refund limits like
// PayPal, replays create-order by PayPal-Request-Id, and appends every API request to
// requests.log (JSON lines). State lives in state.json next to this file. Not shipped to shops.

$dir = __DIR__;
$stateFile = $dir.'/state.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : ['orders' => [], 'captures' => []];
$save = function () use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
};
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$self = 'http://'.$_SERVER['HTTP_HOST'];

if (str_starts_with($path, '/v1/') || str_starts_with($path, '/v2/')) {
    file_put_contents($dir.'/requests.log', json_encode(['method' => $method, 'path' => $path, 'headers' => array_intersect_key($headers, array_flip(['Authorization', 'PayPal-Request-Id', 'Prefer', 'Content-Type'])), 'body' => $body])."\n", FILE_APPEND);
}

$json = function (int $status, array $data) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};
$id = fn () => strtoupper(substr(bin2hex(random_bytes(9)), 0, 17));
$error = fn (int $status, string $issue, string $description) => $json($status, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'details' => [['issue' => $issue, 'description' => $description]]]);

// OAuth
if ($method === 'POST' && $path === '/v1/oauth2/token') {
    if (! str_starts_with((string) ($headers['Authorization'] ?? ''), 'Basic ')) {
        $json(401, ['error' => 'invalid_client']);
    }
    $json(200, ['access_token' => 'A21.standin.'.bin2hex(random_bytes(8)), 'token_type' => 'Bearer', 'expires_in' => 32400]);
}

$bearer = str_starts_with((string) ($headers['Authorization'] ?? ''), 'Bearer A21.standin.');

// Create order
if ($method === 'POST' && $path === '/v2/checkout/orders') {
    $bearer || $json(401, ['name' => 'AUTHENTICATION_FAILURE']);
    $request = json_decode($body, true);
    $requestId = (string) ($headers['PayPal-Request-Id'] ?? '');
    foreach ($state['orders'] as $existing) {
        if ($requestId !== '' && $existing['request_id'] === $requestId) {
            $json(201, $existing['response']);   // idempotent replay
        }
    }
    $orderId = $id();
    $response = ['id' => $orderId, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
        ['href' => $self.'/v2/checkout/orders/'.$orderId, 'rel' => 'self', 'method' => 'GET'],
        ['href' => $self.'/checkoutnow?token='.$orderId, 'rel' => 'payer-action', 'method' => 'GET'],
    ]];
    $state['orders'][$orderId] = ['request_id' => $requestId, 'request' => $request, 'status' => 'PAYER_ACTION_REQUIRED', 'response' => $response, 'capture' => null];
    $save();
    $json(201, $response);
}

// Approval page (what the buyer sees on PayPal)
if ($method === 'GET' && $path === '/checkoutnow') {
    $order = $state['orders'][$_GET['token'] ?? ''] ?? null;
    if ($order === null) {
        http_response_code(404);
        exit('Unknown order');
    }
    $unit = $order['request']['purchase_units'][0];
    $ctx = $order['request']['payment_source']['paypal']['experience_context'];
    $token = htmlspecialchars((string) $_GET['token']);
    echo '<!doctype html><meta charset="utf-8"><title>PayPal (local stand-in)</title><body style="font-family:sans-serif;max-width:420px;margin:60px auto">';
    echo '<p style="color:#888">Local stand-in for PayPal, not paypal.com</p><h1>Pay '.htmlspecialchars((string) $ctx['brand_name']).'</h1>';
    echo '<p>'.htmlspecialchars((string) $unit['description']).'</p><p style="font-size:28px">'.htmlspecialchars($unit['amount']['value'].' '.$unit['amount']['currency_code']).'</p>';
    echo '<a href="/approve?token='.$token.'" style="display:inline-block;background:#ffc439;padding:12px 24px;border-radius:24px;color:#111;text-decoration:none">Pay now</a> ';
    echo '<a href="'.htmlspecialchars((string) $ctx['cancel_url']).'" style="margin-left:16px">Cancel and return</a></body>';
    exit;
}

if ($method === 'GET' && $path === '/approve') {
    $token = (string) ($_GET['token'] ?? '');
    $order = $state['orders'][$token] ?? null;
    if ($order === null) {
        http_response_code(404);
        exit('Unknown order');
    }
    $state['orders'][$token]['status'] = 'APPROVED';
    $save();
    $return = $order['request']['payment_source']['paypal']['experience_context']['return_url'];
    header('Location: '.$return.(str_contains($return, '?') ? '&' : '?').'token='.$token.'&PayerID=STANDINPAYER1');
    http_response_code(302);
    exit;
}

$orderView = function (string $orderId) use (&$state) {
    $order = $state['orders'][$orderId];
    $unit = $order['request']['purchase_units'][0];
    $out = ['id' => $orderId, 'status' => $order['status'], 'purchase_units' => [[
        'reference_id' => $unit['reference_id'] ?? 'default', 'custom_id' => $unit['custom_id'] ?? null, 'invoice_id' => $unit['invoice_id'] ?? null, 'amount' => $unit['amount'],
    ]]];
    if ($order['capture'] !== null) {
        $out['purchase_units'][0]['payments'] = ['captures' => [$state['captures'][$order['capture']]]];
    }

    return $out;
};

// Capture
if ($method === 'POST' && preg_match('#^/v2/checkout/orders/([A-Z0-9]+)/capture$#', $path, $m)) {
    $bearer || $json(401, ['name' => 'AUTHENTICATION_FAILURE']);
    $order = $state['orders'][$m[1]] ?? null;
    $order === null && $error(404, 'INVALID_RESOURCE_ID', 'Specified resource ID does not exist.');
    $order['status'] === 'PAYER_ACTION_REQUIRED' && $error(422, 'ORDER_NOT_APPROVED', 'Payer has not yet approved the Order for payment.');
    $order['capture'] !== null && $error(422, 'ORDER_ALREADY_CAPTURED', 'Order already captured.');
    $unit = $order['request']['purchase_units'][0];
    $captureId = $id();
    $state['captures'][$captureId] = ['id' => $captureId, 'status' => 'COMPLETED', 'amount' => $unit['amount'], 'custom_id' => $unit['custom_id'] ?? null, 'invoice_id' => $unit['invoice_id'] ?? null, 'final_capture' => true, 'refunded' => '0'];
    $state['orders'][$m[1]]['capture'] = $captureId;
    $state['orders'][$m[1]]['status'] = 'COMPLETED';
    $save();
    $json(201, $orderView($m[1]));
}

if ($method === 'GET' && preg_match('#^/v2/checkout/orders/([A-Z0-9]+)$#', $path, $m)) {
    isset($state['orders'][$m[1]]) || $error(404, 'INVALID_RESOURCE_ID', 'Specified resource ID does not exist.');
    $json(200, $orderView($m[1]));
}

// Refund
if ($method === 'POST' && preg_match('#^/v2/payments/captures/([A-Z0-9]+)/refund$#', $path, $m)) {
    $bearer || $json(401, ['name' => 'AUTHENTICATION_FAILURE']);
    $capture = $state['captures'][$m[1]] ?? null;
    $capture === null && $error(404, 'INVALID_RESOURCE_ID', 'Specified resource ID does not exist.');
    $request = json_decode($body, true) ?: [];
    $amount = $request['amount']['value'] ?? $capture['amount']['value'];
    if (bccomp(bcadd($capture['refunded'], $amount, 2), $capture['amount']['value'], 2) > 0) {
        $error(422, 'REFUND_AMOUNT_EXCEEDED', 'The refund amount must be less than or equal to the capture amount that has not yet been refunded.');
    }
    $state['captures'][$m[1]]['refunded'] = bcadd($capture['refunded'], $amount, 2);
    $save();
    $json(201, ['id' => $id(), 'status' => 'COMPLETED', 'amount' => ['value' => $amount, 'currency_code' => $capture['amount']['currency_code']]]);
}

$json(404, ['name' => 'RESOURCE_NOT_FOUND', 'path' => $path]);
