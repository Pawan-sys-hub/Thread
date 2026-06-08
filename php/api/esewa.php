<?php
require_once '../db.php';
require_once '../esewa_config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function apiResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* Legacy JSON initiate — prefer php/esewa-pay.php */
if ($action === 'initiate' && $method === 'POST') {
    authRequired();
    $input   = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    if (!$orderId) {
        apiResponse(['success' => false, 'error' => 'Order ID required'], 400);
    }
    apiResponse([
        'success'      => true,
        'redirect_url' => SITE_BASE_URL . '/php/esewa-pay.php?order_id=' . $orderId,
    ]);
}

/* JSON verify fallback */
if ($action === 'verify' && $method === 'GET') {
    authRequired();

    if (empty($_GET['data'])) {
        apiResponse(['success' => false, 'error' => 'Invalid callback data'], 400);
    }

    $decoded = base64_decode($_GET['data'], true);
    $callback = $decoded ? json_decode($decoded, true) : null;
    if (!is_array($callback) || strtoupper($callback['status'] ?? '') !== 'COMPLETE') {
        apiResponse(['success' => false, 'error' => 'Payment not completed'], 400);
    }

    $orderId = esewaExtractOrderId($callback['transaction_uuid'] ?? '');
    if (!$orderId) {
        apiResponse(['success' => false, 'error' => 'Invalid order reference'], 400);
    }

    $stmt = $conn->prepare('SELECT payment_status FROM orders WHERE id=? AND user_id=?');
    $stmt->bind_param('ii', $orderId, $_SESSION['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        apiResponse(['success' => false, 'error' => 'Order not found'], 404);
    }

    if ($order['payment_status'] === 'paid') {
        apiResponse(['success' => true, 'order_id' => $orderId]);
    }

    apiResponse(['success' => false, 'error' => 'Use server verify endpoint'], 400);
}

if ($action === 'update_status' && $method === 'POST') {
    adminRequired();
    $input   = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $status  = $input['payment_status'] ?? '';
    if (!$orderId || !in_array($status, ['unpaid', 'paid', 'failed'])) {
        apiResponse(['success' => false, 'error' => 'Invalid input'], 400);
    }
    $stmt = $conn->prepare('UPDATE orders SET payment_status=? WHERE id=?');
    $stmt->bind_param('si', $status, $orderId);
    $stmt->execute();
    $stmt->close();
    apiResponse(['success' => true, 'message' => 'Updated']);
}

apiResponse(['success' => false, 'error' => 'Invalid action'], 400);
