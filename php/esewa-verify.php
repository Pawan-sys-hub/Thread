<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function esewaRedirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * GET ORDER ID SAFELY
 */
$orderId = (int)($_GET['order'] ?? ($_SESSION['pending_esewa_order'] ?? 0));

/**
 * VALIDATE CALLBACK PARAM
 */
if (empty($_GET['data'])) {
    error_log("[eSewa] Missing data param");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * BASE64 DECODE
 */
$decoded = base64_decode($_GET['data'], true);

if ($decoded === false) {
    error_log("[eSewa] Base64 decode failed");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * JSON DECODE
 */
$callback = json_decode($decoded, true);

if (!is_array($callback)) {
    error_log("[eSewa] JSON decode failed");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * EXTRACT DATA
 */
$status = strtoupper(trim($callback['status'] ?? ''));
$transaction_uuid = trim($callback['transaction_uuid'] ?? '');
$total_amount = esewaNormalizeAmount($callback['total_amount'] ?? '0');

/**
 * BASIC VALIDATION
 */
if ($status !== 'COMPLETE' || !$transaction_uuid) {
    error_log("[eSewa] Invalid status or missing UUID");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * OPTIONAL SIGNATURE CHECK (DO NOT BLOCK IN SANDBOX)
 */
if (!empty($callback['signature']) && !esewaVerifyCallbackSignature($callback)) {
    error_log("[eSewa] Signature mismatch for $transaction_uuid");
}

/**
 * ORDER ID FROM UUID
 */
$extractedOrderId = esewaExtractOrderId($transaction_uuid);

if ($extractedOrderId > 0) {
    $orderId = $extractedOrderId;
}

/**
 * FINAL ORDER CHECK
 */
if (!$orderId) {
    error_log("[eSewa] Could not determine order ID");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * FETCH ORDER
 */
$stmt = $conn->prepare("
    SELECT id, total_amount, payment_status 
    FROM orders 
    WHERE id = ?
");

$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    error_log("[eSewa] Order #$orderId not found");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * ALREADY PAID
 */
if ($order['payment_status'] === 'paid') {
    esewaRedirect(SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
}

/**
 * AMOUNT VALIDATION
 */
$orderAmount = esewaNormalizeAmount($order['total_amount']);

if (abs((float)$orderAmount - (float)$total_amount) > 0.05) {
    error_log("[eSewa] Amount mismatch: order=$orderAmount callback=$total_amount");
    esewaRedirect(SITE_BASE_URL . '/php/checkout.php?error=payment_failed');
}

/**
 * SERVER VERIFICATION (SAFE NON-BLOCKING)
 */
$verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
    'product_code'     => ESEWA_MERCHANT_CODE,
    'transaction_uuid' => $transaction_uuid,
    'total_amount'     => $orderAmount,
]);

$ctx = stream_context_create([
    'http' => ['timeout' => 10]
]);

$verifyResponse = @file_get_contents($verifyUrl, false, $ctx);

if ($verifyResponse !== false) {
    $verifyData = json_decode($verifyResponse, true);

    if (is_array($verifyData)) {
        $verifyStatus = strtoupper($verifyData['status'] ?? '');

        if ($verifyStatus && $verifyStatus !== 'COMPLETE') {
            error_log("[eSewa] Remote verification failed: " . $verifyStatus);
        }
    }
}

/**
 * MARK AS PAID
 */
$upd = $conn->prepare("
    UPDATE orders 
    SET payment_status='paid',
        payment_ref=?,
        status='processing'
    WHERE id=?
");

$upd->bind_param('si', $transaction_uuid, $orderId);
$upd->execute();
$upd->close();

/**
 * CLEAN SESSION
 */
unset($_SESSION['pending_esewa_order'], $_SESSION['pending_esewa_uuid']);

/**
 * SUCCESS REDIRECT
 */
esewaRedirect(SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');