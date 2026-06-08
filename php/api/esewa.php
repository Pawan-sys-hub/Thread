<?php
require_once '../db.php';

/**
 * eSewa v2 Config (sandbox)
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


function generateEsewaSignature($total_amount, $transaction_uuid, $product_code)
{
    $message = "total_amount={$total_amount},transaction_uuid={$transaction_uuid},product_code={$product_code}";
    return base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
}


function verifyEsewaCallbackSignature(array $data)
{
    if (empty($data['signature']) || empty($data['signed_field_names'])) {
        return false;
    }
    $fields = explode(',', $data['signed_field_names']);
    $parts  = [];
    foreach ($fields as $field) {
        $field = trim($field);
        if ($field === '' || !array_key_exists($field, $data)) {
            continue;
        }
        $parts[] = "{$field}={$data[$field]}";
    }
    $message   = implode(',', $parts);
    $expected  = base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
    return hash_equals($expected, $data['signature']);
}


function normalizeAmount($amount)
{
    return number_format((float)str_replace(',', '', (string)$amount), 2, '.', '');
}


function parseEsewaCallbackData()
{
    // eSewa v2 sends base64 JSON in the `data` query param
    if (!empty($_GET['data'])) {
        $decoded = base64_decode($_GET['data'], true);
        if ($decoded === false) {
            return null;
        }
        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    // Legacy / direct params fallback
    if (!empty($_GET['transaction_uuid']) && !empty($_GET['status'])) {
        return [
            'transaction_uuid'  => $_GET['transaction_uuid'],
            'status'            => $_GET['status'],
            'total_amount'      => $_GET['total_amount'] ?? '',
            'transaction_code'  => $_GET['refId'] ?? ($_GET['transaction_code'] ?? ''),
            'product_code'      => ESEWA_MERCHANT_CODE,
        ];
    }

    return null;
}


function extractOrderIdFromUuid($transaction_uuid)
{
    if (preg_match('/TT-(\d+)-/', $transaction_uuid, $m)) {
        return (int)$m[1];
    }
    return 0;
}


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

    $input   = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);

    if (!$orderId) {
        response(['success' => false, 'error' => 'Order ID required'], 400);
    }

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

    $amount           = normalizeAmount($order['total_amount']);
    $transaction_uuid = 'TT-' . $orderId . '-' . time();
    $product_code     = ESEWA_MERCHANT_CODE;
    $signature        = generateEsewaSignature($amount, $transaction_uuid, $product_code);

    // Store pending transaction ref for verification
    $refStmt = $conn->prepare("UPDATE orders SET payment_ref=? WHERE id=? AND user_id=?");
    $refStmt->bind_param("sii", $transaction_uuid, $orderId, $_SESSION['user_id']);
    $refStmt->execute();
    $refStmt->close();

    response([
        'success'                  => true,
        'pay_url'                  => ESEWA_PAY_URL,
        'amount'                   => $amount,
        'tax_amount'               => "0",
        'product_service_charge'   => "0",
        'product_delivery_charge'  => "0",
        'total_amount'             => $amount,
        'transaction_uuid'         => $transaction_uuid,
        'product_code'             => $product_code,
        'success_url'              => SITE_BASE_URL . "/frontend/order-success.html?order={$orderId}",
        'failure_url'              => SITE_BASE_URL . "/frontend/checkout.html?error=payment_failed&order={$orderId}",
        'signed_field_names'       => "total_amount,transaction_uuid,product_code",
        'signature'                => $signature,
    ]);
}


/* ================================
   VERIFY PAYMENT
================================ */
if ($action === 'verify' && $method === 'GET') {

    authRequired();

    $callback = parseEsewaCallbackData();
    if (!$callback) {
        response(['success' => false, 'error' => 'Invalid callback data'], 400);
    }

    $status            = strtoupper(trim($callback['status'] ?? ''));
    $transaction_uuid  = trim($callback['transaction_uuid'] ?? '');
    $transaction_code  = trim($callback['transaction_code'] ?? ($_GET['refId'] ?? ''));
    $total_amount      = normalizeAmount($callback['total_amount'] ?? '0');
    $expectedOrderId   = (int)($_GET['order'] ?? 0);

    if (!$transaction_uuid || $status !== 'COMPLETE') {
        response(['success' => false, 'error' => 'Payment not completed'], 400);
    }

    // Verify callback signature when present (eSewa v2)
    if (!empty($callback['signature']) && !verifyEsewaCallbackSignature($callback)) {
        response(['success' => false, 'error' => 'Invalid payment signature'], 400);
    }

    $orderId = extractOrderIdFromUuid($transaction_uuid);
    if (!$orderId) {
        response(['success' => false, 'error' => 'Invalid order reference'], 400);
    }

    if ($expectedOrderId && $expectedOrderId !== $orderId) {
        response(['success' => false, 'error' => 'Order mismatch'], 400);
    }

    // Ensure order belongs to logged-in user
    $stmt = $conn->prepare("SELECT id, total_amount, payment_status, payment_ref FROM orders WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $orderId, $_SESSION['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        response(['success' => false, 'error' => 'Order not found'], 404);
    }

    if ($order['payment_status'] === 'paid') {
        response(['success' => true, 'message' => 'Already verified', 'order_id' => $orderId]);
    }

    // Amount must match order total
    if (normalizeAmount($order['total_amount']) !== $total_amount) {
        response(['success' => false, 'error' => 'Amount mismatch'], 400);
    }

    // Server-side status check with eSewa
    $verifyUrl = ESEWA_VERIFY_URL . '?' . http_build_query([
        'product_code'       => ESEWA_MERCHANT_CODE,
        'transaction_uuid'   => $transaction_uuid,
        'total_amount'       => $total_amount,
    ]);

    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $verifyResponse = @file_get_contents($verifyUrl, false, $ctx);

    if ($verifyResponse !== false) {
        $verifyData = json_decode($verifyResponse, true);
        if (is_array($verifyData)) {
            $remoteStatus = strtoupper($verifyData['status'] ?? '');
            if ($remoteStatus && $remoteStatus !== 'COMPLETE') {
                response(['success' => false, 'error' => 'eSewa verification failed'], 400);
            }
        } elseif (stripos($verifyResponse, 'COMPLETE') === false && stripos($verifyResponse, 'success') === false) {
            response(['success' => false, 'error' => 'eSewa verification failed'], 400);
        }
    }

    $paymentRef = $transaction_code ?: $transaction_uuid;

    $stmt = $conn->prepare("
        UPDATE orders
        SET payment_status='paid',
            payment_ref=?,
            status='processing'
        WHERE id=? AND user_id=?
    ");
    $stmt->bind_param("sii", $paymentRef, $orderId, $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();

    response([
        'success'  => true,
        'message'  => 'Payment verified',
        'order_id' => $orderId,
    ]);
}


/* ================================
   ADMIN UPDATE PAYMENT STATUS
================================ */
if ($action === 'update_status' && $method === 'POST') {

    adminRequired();

    $input   = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $status  = $input['payment_status'] ?? '';

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
