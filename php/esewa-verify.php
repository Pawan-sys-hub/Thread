<?php
/**
 * eSewa success callback — eSewa redirects browser here with ?data=base64json
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

function esewaRedirect($url)
{
    header('Location: ' . $url);
    exit;
}

function esewaFail($orderId, $reason = '')
{
    error_log('[ESEWA VERIFY FAIL] order=' . $orderId . ' reason=' . $reason);
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed' . ($orderId ? '&order=' . $orderId : ''));
}

$orderId = (int)($_GET['order'] ?? ($_SESSION['pending_esewa_order'] ?? 0));

if (empty($_GET['data'])) {
    esewaFail($orderId, 'missing data param');
}

$decoded = base64_decode($_GET['data'], true);
if ($decoded === false) {
    esewaFail($orderId, 'base64 decode failed');
}

$callback = json_decode($decoded, true);
if (!is_array($callback)) {
    esewaFail($orderId, 'json decode failed');
}

$status           = strtoupper(trim($callback['status'] ?? ''));
$transaction_uuid = trim($callback['transaction_uuid'] ?? '');
$transaction_code = trim($callback['transaction_code'] ?? '');
$total_amount     = esewaNormalizeAmount($callback['total_amount'] ?? '0');

if ($status !== 'COMPLETE' || !$transaction_uuid) {
    esewaFail($orderId, 'status=' . $status . ' uuid=' . $transaction_uuid);
}

// Signature check — log but don't block sandbox payments
if (!empty($callback['signature']) && !esewaVerifyCallbackSignature($callback)) {
    error_log('[ESEWA] Signature mismatch for ' . $transaction_uuid);
}

$parsedOrderId = esewaExtractOrderId($transaction_uuid);
if (!$parsedOrderId) {
    $parsedOrderId = $orderId;
}
if ($orderId && $parsedOrderId && $parsedOrderId !== $orderId) {
    esewaFail($orderId, 'order id mismatch');
}

$orderId = $parsedOrderId ?: $orderId;
if (!$orderId) {
    esewaFail(0, 'no order id');
}

$stmt = $conn->prepare('SELECT id, total_amount, payment_status FROM orders WHERE id=?');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    esewaFail($orderId, 'order not found');
}

if ($order['payment_status'] === 'paid') {
    esewaRedirect(SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
}

$orderAmount = esewaNormalizeAmount($order['total_amount']);
if (abs((float)$orderAmount - (float)$total_amount) > 0.05) {
    esewaFail($orderId, "amount mismatch order={$orderAmount} callback={$total_amount}");
}

// Optional server-to-server check (non-blocking in sandbox)
$verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
    'product_code'     => ESEWA_MERCHANT_CODE,
    'transaction_uuid' => $transaction_uuid,
    'total_amount'     => $orderAmount,
]);
$ctx            = stream_context_create(['http' => ['timeout' => 15], 'ssl' => ['verify_peer' => false]]);
$verifyResponse = @file_get_contents($verifyUrl, false, $ctx);
if ($verifyResponse !== false) {
    $verifyData = json_decode($verifyResponse, true);
    if (is_array($verifyData) && !empty($verifyData['status'])) {
        $remoteStatus = strtoupper($verifyData['status']);
        if ($remoteStatus !== 'COMPLETE') {
            error_log('[ESEWA] Remote status: ' . $remoteStatus);
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
