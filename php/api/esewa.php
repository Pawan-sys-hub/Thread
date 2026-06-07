<?php
/**
 * eSewa Payment API
 * 
 * eSewa test credentials:
 *   Merchant Code: EPAYTEST
 *   Test number: 9806800001
 *   Password: Nepal@123
 *   MPIN: 1122
 *
 * Endpoints:
 *   POST /php/api/esewa.php?action=initiate  → returns form fields for eSewa redirect
 *   GET  /php/api/esewa.php?action=verify    → verifies payment after eSewa callback
 */
require_once '../db.php';

// eSewa Config
define('ESEWA_MERCHANT_CODE', 'EPAYTEST');           // Use your live merchant code in production
define('ESEWA_SECRET_KEY',   '8g8M8m8P8n8b8m8');     // Use your live secret key in production
define('ESEWA_PAY_URL',      'https://rc-epay.esewa.com.np/api/epay/main/v2');
define('SITE_BASE_URL',      (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/TrendTrackV2");

header('Content-Type: application/json');

/**
 * Signature generator for eSewa v2
 */
function generateEsewaSignature($total_amount, $transaction_uuid, $product_code) {
    $s = "total_amount=$total_amount,transaction_uuid=$transaction_uuid,product_code=$product_code";
    $hash = hash_hmac('sha256', $s, ESEWA_SECRET_KEY, true);
    return base64_encode($hash);
}

// ... existing code ...

$action = $_GET['action'] ?? 'initiate';
$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------
// INITIATE PAYMENT
// -----------------------------------------------------------
if ($action === 'initiate' && $method === 'POST') {
    authRequired();

    $input    = json_decode(file_get_contents('php://input'), true);
    $orderId  = (int)($input['order_id'] ?? 0);
    $amount   = (float)($input['amount'] ?? 0);

    if (!$orderId || !$amount) {
        jsonResponse(['success' => false, 'error' => 'Order ID and amount are required.'], 400);
    }

    // Verify order belongs to this user
    $stmt = $conn->prepare("SELECT id, total_amount, payment_status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orderId, $_SESSION['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Order not found.'], 404);
    }
    if ($order['payment_status'] === 'paid') {
        jsonResponse(['success' => false, 'error' => 'Order is already paid.'], 400);
    }

    $tAmt = round((float)$order['total_amount'], 2);
    $uuid = 'TT-' . $orderId . '-' . time();
    $prod = ESEWA_MERCHANT_CODE; // Or a specific product code

    $sig = generateEsewaSignature($tAmt, $uuid, $prod);

    // Return fields needed for eSewa v2 redirect form
    jsonResponse([
        'success'           => true,
        'pay_url'           => ESEWA_PAY_URL,
        'amount'            => $tAmt,
        'tax_amount'        => 0,
        'total_amount'      => $tAmt,
        'transaction_uuid'  => $uuid,
        'product_code'      => $prod,
        'product_service_charge'  => 0,
        'product_delivery_charge' => 0,
        'success_url'       => SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId,
        'failure_url'       => SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId,
        'signed_field_names'=> 'total_amount,transaction_uuid,product_code',
        'signature'         => $sig
    ]);
}

// -----------------------------------------------------------
// VERIFY PAYMENT (called after eSewa redirects back)
// -----------------------------------------------------------
if ($action === 'verify' && $method === 'GET') {
    $data = $_GET['data'] ?? ''; // eSewa v2 returns data in a single 'data' param (base64 encoded json)

    if (!$data) {
        jsonResponse(['success' => false, 'error' => 'Missing verification data.'], 400);
    }

    $decoded = json_decode(base64_decode($data), true);
    if (!$decoded || ($decoded['status'] ?? '') !== 'COMPLETE') {
        jsonResponse(['success' => false, 'error' => 'Payment not completed.'], 400);
    }

    // Extract order ID from transaction_uuid (format: TT-{id}-{timestamp})
    preg_match('/TT-(\d+)-/', $decoded['transaction_uuid'], $matches);
    $orderId = (int)($matches[1] ?? 0);

    if (!$orderId) {
        jsonResponse(['success' => false, 'error' => 'Invalid order reference.'], 400);
    }

    // Payment verified — update order
    $ref = $decoded['transaction_code'] ?? 'eSewa-v2-' . time();
    $stmt = $conn->prepare("UPDATE orders SET payment_status='paid', payment_ref=?, status='processing' WHERE id=?");
    $stmt->bind_param("si", $ref, $orderId);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true, 'message' => 'Payment verified!', 'order_id' => $orderId]);
}


// -----------------------------------------------------------
// UPDATE STATUS (admin only)
// -----------------------------------------------------------
if ($action === 'update_status' && $method === 'POST') {
    adminRequired();
    $input    = json_decode(file_get_contents('php://input'), true);
    $orderId  = (int)($input['order_id'] ?? 0);
    $status   = $input['payment_status'] ?? '';

    if (!$orderId || !in_array($status, ['unpaid','paid','failed'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid parameters.'], 400);
    }

    $stmt = $conn->prepare("UPDATE orders SET payment_status=? WHERE id=?");
    $stmt->bind_param("si", $status, $orderId);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true, 'message' => 'Payment status updated.']);
}

jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);
