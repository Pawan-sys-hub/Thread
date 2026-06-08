<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * AUTH CHECK
 */
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_BASE_URL . '/frontend/login.html?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) {
    header('Location: ' . SITE_BASE_URL . '/frontend/cart.html');
    exit;
}

/**
 * FETCH ORDER SAFELY
 */
$stmt = $conn->prepare("
    SELECT id, total_amount, payment_status, payment_method 
    FROM orders 
    WHERE id=? AND user_id=?
");
$stmt->bind_param('ii', $orderId, $_SESSION['user_id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: ' . SITE_BASE_URL . '/frontend/cart.html');
    exit;
}

/**
 * NON-ESEWA ORDERS SKIP
 */
if ($order['payment_method'] !== 'esewa') {
    header('Location: ' . SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId);
    exit;
}

/**
 * ALREADY PAID
 */
if ($order['payment_status'] === 'paid') {
    header('Location: ' . SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
    exit;
}

/**
 * NORMALIZE AMOUNT (IMPORTANT FIX)
 */
$total = esewaNormalizeAmount($order['total_amount']);

/**
 * GENERATE TRANSACTION
 */
$transaction_uuid = esewaGenerateOrderRef($orderId);
$product_code     = ESEWA_MERCHANT_CODE;

/**
 * FIXED SIGNATURE (must use normalized total)
 */
$signature = esewaGenerateSignature($total, $transaction_uuid, $product_code);

/**
 * SAVE PAYMENT REF
 */
$refStmt = $conn->prepare("
    UPDATE orders 
    SET payment_ref=? 
    WHERE id=? AND user_id=?
");
$refStmt->bind_param('sii', $transaction_uuid, $orderId, $_SESSION['user_id']);
$refStmt->execute();
$refStmt->close();

/**
 * SESSION TRACKING
 */
$_SESSION['pending_esewa_order'] = $orderId;
$_SESSION['pending_esewa_uuid']  = $transaction_uuid;

/**
 * CALLBACK URLs (FIXED CONSISTENCY)
 */
$successUrl = ESEWA_SUCCESS_URL . '?order=' . $orderId;
$failureUrl = ESEWA_FAILURE_URL . '&order=' . $orderId;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redirecting to eSewa</title>
<style>
body {
    margin:0;
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:system-ui;
    background:#f3f4f6;
}
.box { text-align:center; }
.spinner {
    width:44px;height:44px;
    border:4px solid #ddd;
    border-top-color:#111;
    border-radius:50%;
    animation:spin .8s linear infinite;
    margin:0 auto 15px;
}
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>

<body>
<div class="box">
    <div class="spinner"></div>
    <p>Redirecting to eSewa…</p>
</div>

<form id="esewa-form" action="<?= htmlspecialchars(ESEWA_PAY_URL) ?>" method="POST">
    <input type="hidden" name="amount" value="<?= htmlspecialchars($total) ?>">
    <input type="hidden" name="tax_amount" value="0">
    <input type="hidden" name="total_amount" value="<?= htmlspecialchars($total) ?>">
    <input type="hidden" name="transaction_uuid" value="<?= htmlspecialchars($transaction_uuid) ?>">
    <input type="hidden" name="product_code" value="<?= htmlspecialchars($product_code) ?>">
    <input type="hidden" name="product_service_charge" value="0">
    <input type="hidden" name="product_delivery_charge" value="0">
    <input type="hidden" name="success_url" value="<?= htmlspecialchars($successUrl) ?>">
    <input type="hidden" name="failure_url" value="<?= htmlspecialchars($failureUrl) ?>">
    <input type="hidden" name="signed_field_names" value="total_amount,transaction_uuid,product_code">
    <input type="hidden" name="signature" value="<?= htmlspecialchars($signature) ?>">
</form>

<script>
setTimeout(() => {
    document.getElementById('esewa-form').submit();
}, 300);
</script>

</body>
</html>