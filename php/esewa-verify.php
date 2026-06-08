<?php
/**
 * eSewa success callback — eSewa redirects here with ?data=base64json
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

function esewaRedirect($url)
{
    header('Location: ' . $url);
    exit;
}

$orderId = (int)($_GET['order'] ?? ($_SESSION['pending_esewa_order'] ?? 0));

if (empty($_GET['data'])) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed' . ($orderId ? '&order=' . $orderId : ''));
}

$decoded = base64_decode($_GET['data'], true);
if ($decoded === false) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

$callback = json_decode($decoded, true);
if (!is_array($callback)) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

$status           = strtoupper(trim($callback['status'] ?? ''));
$transaction_uuid = trim($callback['transaction_uuid'] ?? '');
$transaction_code = trim($callback['transaction_code'] ?? '');
$total_amount     = esewaNormalizeAmount($callback['total_amount'] ?? '0');

if ($status !== 'COMPLETE' || !$transaction_uuid) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

if (!esewaVerifyCallbackSignature($callback)) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

$parsedOrderId = esewaExtractOrderId($transaction_uuid);
if (!$parsedOrderId) {
    $parsedOrderId = $orderId;
}
if ($orderId && $parsedOrderId !== $orderId) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

$orderId = $parsedOrderId;

$stmt = $conn->prepare('SELECT id, total_amount, payment_status, user_id FROM orders WHERE id=?');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed');
}

if ($order['payment_status'] === 'paid') {
    esewaRedirect(SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
}

if (esewaNormalizeAmount($order['total_amount']) !== $total_amount) {
    esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
}

// Server-to-server status check with eSewa
$verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
    'product_code'     => ESEWA_MERCHANT_CODE,
    'transaction_uuid' => $transaction_uuid,
    'total_amount'     => $total_amount,
]);

$ctx            = stream_context_create(['http' => ['timeout' => 20]]);
$verifyResponse = @file_get_contents($verifyUrl, false, $ctx);

if ($verifyResponse !== false) {
    $verifyData = json_decode($verifyResponse, true);
    if (is_array($verifyData)) {
        $remoteStatus = strtoupper($verifyData['status'] ?? '');
        if ($remoteStatus && $remoteStatus !== 'COMPLETE') {
            esewaRedirect(SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId);
        }
    }
}

$paymentRef = $transaction_code ?: $transaction_uuid;

$upd = $conn->prepare("UPDATE orders SET payment_status='paid', payment_ref=?, status='processing' WHERE id=?");
$upd->bind_param('si', $paymentRef, $orderId);
$upd->execute();
$upd->close();

unset($_SESSION['pending_esewa_order'], $_SESSION['pending_esewa_uuid']);

esewaRedirect(SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
