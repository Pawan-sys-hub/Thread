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
define('ESEWA_VERIFY_URL', 'https://uat.esewa.com.np/epay/transrec');  // Test URL
define('ESEWA_PAY_URL',    'https://uat.esewa.com.np/epay/main');       // Test URL
define('SITE_BASE_URL',    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/TrendTrackV2");

header('Content-Type: application/json');

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
    $pid  = 'TT-ORDER-' . $orderId . '-' . time();

    // Return fields needed for eSewa redirect form
    jsonResponse([
        'success'       => true,
        'pay_url'       => ESEWA_PAY_URL,
        'merchant_code' => ESEWA_MERCHANT_CODE,
        'amount'        => $tAmt,
        'tax_amount'    => 0,
        'service_charge'=> 0,
        'delivery_charge'=> 0,
        'total_amount'  => $tAmt,
        'product_id'    => $pid,
        'success_url'   => SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId,
        'failure_url'   => SITE_BASE_URL . '/frontend/checkout.html?error=payment_failed&order=' . $orderId,
    ]);
}

// -----------------------------------------------------------
// VERIFY PAYMENT (called after eSewa redirects back)
// -----------------------------------------------------------
if ($action === 'verify' && $method === 'GET') {
    $oid = $_GET['oid'] ?? '';
    $amt = $_GET['amt'] ?? '';
    $ref = $_GET['refId'] ?? '';

    if (!$oid || !$amt || !$ref) {
        jsonResponse(['success' => false, 'error' => 'Missing verification parameters.'], 400);
    }

    // Extract order ID from product_id (format: TT-ORDER-{id}-{timestamp})
    preg_match('/TT-ORDER-(\d+)-/', $oid, $matches);
    $orderId = (int)($matches[1] ?? 0);

    if (!$orderId) {
        jsonResponse(['success' => false, 'error' => 'Invalid order reference.'], 400);
    }

    // Verify with eSewa server
    $verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
        'amt'  => $amt,
        'rid'  => $ref,
        'pid'  => $oid,
        'scd'  => ESEWA_MERCHANT_CODE,
    ]);

    $ctx     = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($verifyUrl, false, $ctx);

    if ($response === false || strpos($response, '<response_code>Success</response_code>') === false) {
        // Mark as failed
        $stmt = $conn->prepare("UPDATE orders SET payment_status='failed' WHERE id=?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $stmt->close();
        jsonResponse(['success' => false, 'error' => 'Payment verification failed.'], 400);
    }

    // Payment verified — update order
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
