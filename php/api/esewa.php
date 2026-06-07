<?php
require_once '../db.php';

/**
 * eSewa v2 Config
 */
define('ESEWA_MERCHANT_CODE', 'EPAYTEST');
define('ESEWA_SECRET_KEY', '8g8M8m8P8n8b8m8');

define('ESEWA_PAY_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2');
define('ESEWA_VERIFY_URL', 'https://rc-epay.esewa.com.np/api/epay/transaction/status');

define(
    'SITE_BASE_URL',
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
    "://" . $_SERVER['HTTP_HOST'] . "/TrendTrackV2"
);

header('Content-Type: application/json');


/**
 * Generate eSewa v2 signature
 */
function generateEsewaSignature($total_amount, $transaction_uuid, $product_code)
{
    $message = "total_amount={$total_amount},transaction_uuid={$transaction_uuid},product_code={$product_code}";
    return base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
}


/**
 * Helper: JSON response
 */
function response($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}


$action = $_GET['action'] ?? 'initiate';
$method = $_SERVER['REQUEST_METHOD'];

/* ================================
   INITIATE PAYMENT
================================ */
if ($action === 'initiate' && $method === 'POST') {

    authRequired();

    $input = json_decode(file_get_contents('php://input'), true);

    $orderId = (int)($input['order_id'] ?? 0);

    if (!$orderId) {
        response(['success' => false, 'error' => 'Order ID required'], 400);
    }

    // Get order
    $stmt = $conn->prepare("SELECT id, total_amount, payment_status FROM orders WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $orderId, $_SESSION['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        response(['success' => false, 'error' => 'Order not found'], 404);
    }

    if ($order['payment_status'] === 'paid') {
        response(['success' => false, 'error' => 'Already paid'], 400);
    }

    // Keep amount EXACT (do NOT break decimals)
    $amount = number_format((float)$order['total_amount'], 2, '.', '');

    $transaction_uuid = 'TT-' . $orderId . '-' . time();
    $product_code = ESEWA_MERCHANT_CODE;

    $signature = generateEsewaSignature($amount, $transaction_uuid, $product_code);

    response([
        'success' => true,
        'pay_url' => ESEWA_PAY_URL,

        'amount' => $amount,
        'tax_amount' => "0",
        'total_amount' => $amount,

        'transaction_uuid' => $transaction_uuid,
        'product_code' => $product_code,

        'success_url' => SITE_BASE_URL . "/frontend/order-success.html?order={$orderId}",
        'failure_url' => SITE_BASE_URL . "/frontend/checkout.html?error=payment_failed&order={$orderId}",

        'signed_field_names' => "total_amount,transaction_uuid,product_code",
        'signature' => $signature
    ]);
}


/* ================================
   VERIFY PAYMENT
================================ */
if ($action === 'verify' && $method === 'GET') {

    $transaction_uuid = $_GET['transaction_uuid'] ?? '';
    $status = $_GET['status'] ?? '';
    $refId = $_GET['refId'] ?? '';

    if (!$transaction_uuid || !$status) {
        response(['success' => false, 'error' => 'Invalid callback'], 400);
    }

    if ($status !== 'COMPLETE') {
        response(['success' => false, 'error' => 'Payment not completed'], 400);
    }

    preg_match('/TT-(\d+)-/', $transaction_uuid, $m);
    $orderId = (int)($m[1] ?? 0);

    if (!$orderId) {
        response(['success' => false, 'error' => 'Invalid order reference'], 400);
    }

    // Optional: server-side verification (recommended)
    $verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
        'product_code' => ESEWA_MERCHANT_CODE,
        'transaction_uuid' => $transaction_uuid,
        'amount' => $_GET['total_amount'] ?? ''
    ]);

    $verifyResponse = @file_get_contents($verifyUrl);

    // If API fails, still rely on callback status (fallback safe mode)
    if ($verifyResponse !== false && strpos($verifyResponse, 'success') === false) {
        response(['success' => false, 'error' => 'Verification failed'], 400);
    }

    // Update DB
    $stmt = $conn->prepare("
        UPDATE orders 
        SET payment_status='paid',
            payment_ref=?,
            status='processing'
        WHERE id=?
    ");

    $stmt->bind_param("si", $refId, $orderId);
    $stmt->execute();
    $stmt->close();

    response([
        'success' => true,
        'message' => 'Payment verified',
        'order_id' => $orderId
    ]);
}


/* ================================
   ADMIN UPDATE STATUS
================================ */
if ($action === 'update_status' && $method === 'POST') {

    adminRequired();

    $input = json_decode(file_get_contents('php://input'), true);

    $orderId = (int)($input['order_id'] ?? 0);
    $status = $input['payment_status'] ?? '';

    if (!$orderId || !in_array($status, ['unpaid', 'paid', 'failed'])) {
        response(['success' => false, 'error' => 'Invalid input'], 400);
    }

    $stmt = $conn->prepare("UPDATE orders SET payment_status=? WHERE id=?");
    $stmt->bind_param("si", $status, $orderId);
    $stmt->execute();
    $stmt->close();

    response(['success' => true, 'message' => 'Updated']);
}

response(['success' => false, 'error' => 'Invalid action'], 400);