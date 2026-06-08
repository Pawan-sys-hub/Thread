<?php
/**
 * Server-side eSewa redirect — matches working AURANOIR pattern.
 * After order is created, user is sent here and auto-posted to eSewa.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_BASE_URL . '/frontend/login.html?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    header('Location: ' . SITE_BASE_URL . '/frontend/checkout.html');
    exit;
}

$stmt = $conn->prepare("SELECT id, total_amount, payment_status, payment_method FROM orders WHERE id=? AND user_id=?");
$stmt->bind_param('ii', $orderId, $_SESSION['user_id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: ' . SITE_BASE_URL . '/frontend/checkout.html');
    exit;
}

if ($order['payment_method'] !== 'esewa') {
    header('Location: ' . SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId);
    exit;
}

if ($order['payment_status'] === 'paid') {
    header('Location: ' . SITE_BASE_URL . '/frontend/order-success.html?order=' . $orderId . '&verified=1');
    exit;
}

$total            = $order['total_amount'];
$transaction_uuid = esewaGenerateOrderRef($orderId);
$product_code     = ESEWA_MERCHANT_CODE;
$signature        = esewaGenerateSignature($total, $transaction_uuid, $product_code);

$refStmt = $conn->prepare('UPDATE orders SET payment_ref=? WHERE id=? AND user_id=?');
$refStmt->bind_param('sii', $transaction_uuid, $orderId, $_SESSION['user_id']);
$refStmt->execute();
$refStmt->close();

$_SESSION['pending_esewa_order'] = $orderId;
$_SESSION['pending_esewa_uuid']  = $transaction_uuid;

$successUrl = ESEWA_SUCCESS_URL . '?order=' . $orderId;
$failureUrl = ESEWA_FAILURE_URL . '&order=' . $orderId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Redirecting to eSewa — TrendTrack</title>
  <link rel="stylesheet" href="<?= SITE_BASE_URL ?>/css/style.css">
  <style>
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--gray-100, #f1f3f6); }
    .redirect-box { text-align: center; padding: 48px; }
    .spinner { width: 44px; height: 44px; border: 4px solid #e5e7eb; border-top-color: #111; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 20px; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="redirect-box">
    <div class="spinner"></div>
    <p style="color:#6b7280;font-size:1rem;">Redirecting to eSewa…</p>
    <p style="color:#9ca3af;font-size:0.85rem;margin-top:8px;">Please do not close this window.</p>
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

  <script>document.getElementById('esewa-form').submit();</script>
</body>
</html>
