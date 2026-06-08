<?php

error_reporting(E_ALL);
ini_set('display_errors', 1); // Remove after debugging

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_BASE_URL . '/frontend/login.html?redirect=' . urlencode('/TrendTrackV2/php/checkout.php'));
    exit;
}
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ' . SITE_BASE_URL . '/frontend/admin/index.html');
    exit;
}

$userId      = (int)$_SESSION['user_id'];
$deliveryFee = 100;
$error       = '';
$buyNowId    = (int)($_GET['buy_now'] ?? 0);
$buyNowQty   = max(1, (int)($_GET['qty'] ?? 1));

// Helper functions
function formatNpr($amount) {
    return 'रू ' . number_format((float)$amount, 2);
}
function redirectTo($path) {
    global $conn;
    $conn->close();
    header('Location: ' . SITE_BASE_URL . $path);
    exit;
}

// Load user saved data
$user = ['name' => '', 'phone' => '', 'address' => ''];
$stmt = $conn->prepare('SELECT name, phone, address FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $user = $row;
}
$stmt->close();

// Build cart items
$cartItems = [];
if ($buyNowId) {
    $stmt = $conn->prepare('SELECT id, name, price, stock, image_url FROM products WHERE id = ?');
    $stmt->bind_param('i', $buyNowId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$product) redirectTo('/frontend/trends.html');
    if ($product['stock'] < $buyNowQty) $error = 'Insufficient stock.';
    else {
        $cartItems[] = [
            'product_id' => $product['id'],
            'name'       => $product['name'],
            'price'      => $product['price'],
            'quantity'   => $buyNowQty,
            'stock'      => $product['stock'],
            'image_url'  => $product['image_url'] ?? '',
        ];
    }
} else {
    $stmt = $conn->prepare(
        'SELECT c.product_id, c.quantity, p.name, p.price, p.stock, p.image_url
         FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $cartItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($cartItems) && empty($_GET['success'])) redirectTo('/frontend/cart.html');
}

// Calculate subtotal
$subtotal = 0;
foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$total = $subtotal + ($cartItems ? $deliveryFee : 0);

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order']) && !$error) {
    $name    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city'] ?? '');
    $zip     = trim($_POST['zip'] ?? '');
    $payment = $_POST['payment_method'] ?? 'cod';

    if (!$name || !$address || !$city) $error = 'Please fill all required fields.';
    elseif (!preg_match('/^[0-9]{7,15}$/', preg_replace('/\s/', '', $phone))) $error = 'Invalid phone number.';
    elseif (!in_array($payment, ['cod', 'esewa'])) $error = 'Invalid payment method.';
    else {
        foreach ($cartItems as $item) {
            if ($item['stock'] < $item['quantity']) {
                $error = "'{$item['name']}' has insufficient stock.";
                break;
            }
        }
    }

    if (!$error) {
        $shippingAddr = json_encode(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'city' => $city, 'zip' => $zip]);
        $notes = "City: $city" . ($zip ? ", ZIP: $zip" : "");
        $paymentStatus = 'unpaid'; // ✅ FIX: use variable instead of literal

        $stmt = $conn->prepare('INSERT INTO orders (user_id, total_amount, shipping_address, phone, payment_method, payment_status, notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('idsssss', $userId, $total, $shippingAddr, $phone, $payment, $paymentStatus, $notes);
        if (!$stmt->execute()) {
            $error = 'Could not place order: ' . $stmt->error;
        } else {
            $orderId = $stmt->insert_id;
            $stmt->close();

            foreach ($cartItems as $item) {
                $stmt = $conn->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)');
                $stmt->bind_param('iiid', $orderId, $item['product_id'], $item['quantity'], $item['price']);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
                $stmt->bind_param('ii', $item['quantity'], $item['product_id']);
                $stmt->execute();
                $stmt->close();
            }

            if (!$buyNowId) {
                $stmt = $conn->prepare('DELETE FROM cart WHERE user_id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }

            // eSewa payment
            if ($payment === 'esewa') {
                $totalStr = number_format((float)$total, 2, '.', '');
                $transaction_uuid = esewaGenerateOrderRef($orderId);
                $signature = esewaGenerateSignature($totalStr, $transaction_uuid, ESEWA_MERCHANT_CODE);
                $stmt = $conn->prepare('UPDATE orders SET payment_ref=? WHERE id=?');
                $stmt->bind_param('si', $transaction_uuid, $orderId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['pending_esewa_order'] = $orderId;
                $_SESSION['pending_esewa_uuid'] = $transaction_uuid;
                session_write_close();

                $successUrl = ESEWA_SUCCESS_URL . '?order=' . $orderId;
                $failureUrl = ESEWA_FAILURE_URL . '&order=' . $orderId;
                ?>
                <!DOCTYPE html>
                <html>
                <head><meta charset="UTF-8"><title>Processing eSewa Payment</title>
                <style>
                    body{font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;background:#f1f3f6;margin:0}
                    .box{text-align:center;background:#fff;padding:40px 32px;border-radius:28px;box-shadow:0 20px 35px -10px rgba(0,0,0,0.1);max-width:400px;width:90%}
                    .spinner{width:48px;height:48px;border:4px solid #e5e7eb;border-top-color:#000;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 24px}
                    @keyframes spin{to{transform:rotate(360deg)}}
                    h3{margin:0 0 8px;font-size:1.4rem}
                    p{color:#4b5563;margin-bottom:24px}
                    .manual-btn{background:#60BB46;color:#fff;border:none;padding:12px 28px;border-radius:40px;font-weight:600;cursor:pointer}
                    .manual-btn:hover{background:#4fa33a}
                </style>
                </head>
                <body>
                <div class="box">
                    <div class="spinner"></div>
                    <h3>Redirecting to eSewa</h3>
                    <p>Please wait while we take you to the secure payment gateway...</p>
                    <button class="manual-btn" onclick="document.getElementById('esewaForm').submit();">Click here if not redirected</button>
                </div>
                <form id="esewaForm" action="<?= htmlspecialchars(ESEWA_PAY_URL) ?>" method="POST">
                    <input type="hidden" name="amount" value="<?= htmlspecialchars($totalStr) ?>">
                    <input type="hidden" name="tax_amount" value="0">
                    <input type="hidden" name="total_amount" value="<?= htmlspecialchars($totalStr) ?>">
                    <input type="hidden" name="transaction_uuid" value="<?= htmlspecialchars($transaction_uuid) ?>">
                    <input type="hidden" name="product_code" value="<?= htmlspecialchars(ESEWA_MERCHANT_CODE) ?>">
                    <input type="hidden" name="product_service_charge" value="0">
                    <input type="hidden" name="product_delivery_charge" value="0">
                    <input type="hidden" name="success_url" value="<?= htmlspecialchars($successUrl) ?>">
                    <input type="hidden" name="failure_url" value="<?= htmlspecialchars($failureUrl) ?>">
                    <input type="hidden" name="signed_field_names" value="total_amount,transaction_uuid,product_code">
                    <input type="hidden" name="signature" value="<?= htmlspecialchars($signature) ?>">
                </form>
                <script>
                    window.addEventListener('DOMContentLoaded', function() {
                        setTimeout(function() { document.getElementById('esewaForm').submit(); }, 300);
                    });
                </script>
                </body>
                </html>
                <?php
                exit;
            }
            // COD success
            redirectTo('/frontend/order-success.html?order=' . $orderId);
        }
    }
}

// Display checkout form
$paymentFailed = isset($_GET['error']) && $_GET['error'] === 'payment_failed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — TrendTrack</title>
    <link rel="stylesheet" href="<?= SITE_BASE_URL ?>/css/style.css">
    <style>
        .payment-logo, .payment-logo--square { width: 56px !important; height: 40px !important; object-fit: contain; }
        .payment-logo--square { background: #f8f8f8; padding: 4px; border-radius: 8px; }
        .pay-option { transition: all 0.2s; border: 2px solid var(--border); border-radius: 12px; padding: 16px; cursor: pointer; display: flex; align-items: center; gap: 14px; }
        .pay-option.selected { border-color: var(--black); background: #fafafa; }
        .alert.error { padding: 14px 18px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 10px; color: #991b1b; margin-bottom: 20px; }
        .step { text-align: center; }
        .step div { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: bold; }
        .step.active div { background: var(--black); color: white; }
        .step p { margin-top: 8px; font-size: 0.8rem; }
        .badge-recommended { background: #60BB46; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .text-xs { font-size: 0.75rem; color: var(--gray-500); }
        .text-center { text-align: center; }
    </style>
</head>
<body>
<section class="section" style="padding-top:48px">
    <div class="container">
        <?php if ($paymentFailed): ?>
            <div class="alert error">⚠️ eSewa payment was not completed. Please try again or choose Cash on Delivery.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="display:flex; justify-content:center; gap:40px; margin-bottom:40px;">
            <div class="step active"><div>1</div><p>Shipping</p></div>
            <div class="step"><div>2</div><p>Payment</p></div>
            <div class="step"><div>3</div><p>Review</p></div>
        </div>

        <div class="cart-layout">
            <div>
                <form method="POST" action="checkout.php<?= $buyNowId ? '?buy_now=' . $buyNowId . '&qty=' . $buyNowQty : '' ?>" id="checkoutForm">
                    <input type="hidden" name="place_order" value="1">
                    <div class="admin-card" style="margin-bottom:24px">
                        <div class="admin-card-header"><h3>📦 Shipping Information</h3></div>
                        <div style="padding:24px; display:grid; grid-template-columns:1fr 1fr; gap:16px">
                            <div class="form-group" style="grid-column:1/-1">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($_POST['full_name'] ?? $user['name']) ?>">
                            </div>
                            <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
                            <div class="form-group"><label>Phone *</label><input type="tel" name="phone" class="form-control" required value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone']) ?>"></div>
                            <div class="form-group" style="grid-column:1/-1"><label>Address *</label><input type="text" name="address" class="form-control" required value="<?= htmlspecialchars($_POST['address'] ?? $user['address']) ?>"></div>
                            <div class="form-group"><label>City *</label><input type="text" name="city" class="form-control" required value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"></div>
                            <div class="form-group"><label>ZIP Code</label><input type="text" name="zip" class="form-control" value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>"></div>
                        </div>
                    </div>

                    <div class="admin-card">
                        <div class="admin-card-header"><h3>💳 Payment Method</h3></div>
                        <div style="padding:24px; display:flex; flex-direction:column; gap:12px">
                            <label class="pay-option" id="payEsewa">
                                <input type="radio" name="payment_method" value="esewa" onchange="highlightPayment()" style="margin-right: 0;">
                                <img src="<?= SITE_BASE_URL ?>/assets/payments/esewa.svg" alt="eSewa" class="payment-logo" onerror="this.src='https://placehold.co/56x40?text=eSewa'">
                                <div style="flex:1"><strong>eSewa</strong><div class="text-xs">Pay securely via eSewa wallet</div></div>
                                <span class="badge-recommended">Recommended</span>
                            </label>
                            <label class="pay-option selected" id="payCod">
                                <input type="radio" name="payment_method" value="cod" checked onchange="highlightPayment()">
                                <img src="<?= SITE_BASE_URL ?>/assets/payments/cod.svg" alt="Cash on Delivery" class="payment-logo--square" onerror="this.src='https://placehold.co/56x40?text=COD'">
                                <div style="flex:1"><strong>Cash on Delivery</strong><div class="text-xs">Pay when you receive</div></div>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" style="margin-top:24px; padding:16px; font-size:1rem">Place Order — <?= formatNpr($total) ?></button>
                </form>
            </div>

            <div class="order-summary">
                <h3>Order Summary</h3>
                <?php foreach ($cartItems as $item): ?>
                    <div class="summary-row"><span><?= htmlspecialchars($item['name']) ?> × <?= (int)$item['quantity'] ?></span><span><?= formatNpr($item['price'] * $item['quantity']) ?></span></div>
                <?php endforeach; ?>
                <div class="summary-row"><span>Subtotal</span><span><?= formatNpr($subtotal) ?></span></div>
                <div class="summary-row"><span>Delivery</span><span><?= formatNpr($deliveryFee) ?></span></div>
                <div class="summary-row total"><span>Total</span><span><?= formatNpr($total) ?></span></div>
                <p class="text-center text-xs" style="margin-top:16px">🔒 Secure checkout</p>
            </div>
        </div>
    </div>
</section>

<script>
    function highlightPayment() {
        const esewaChecked = document.querySelector('input[value="esewa"]').checked;
        const codChecked = document.querySelector('input[value="cod"]').checked;
        const payEsewa = document.getElementById('payEsewa');
        const payCod = document.getElementById('payCod');
        if (payEsewa) payEsewa.classList.toggle('selected', esewaChecked);
        if (payCod) payCod.classList.toggle('selected', codChecked);
    }
    highlightPayment();
</script>
</body>
</html>