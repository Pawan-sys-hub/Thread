<?php
/**
 * Server-side checkout — creates order and redirects to eSewa (AURANOIR pattern)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/esewa_config.php';

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

function formatNpr($amount)
{
    return 'रू ' . number_format((float)$amount, 2);
}

function redirectTo($path)
{
    header('Location: ' . SITE_BASE_URL . $path);
    exit;
}

// Load user defaults
$user = ['name' => '', 'phone' => '', 'address' => ''];
$uStmt = $conn->prepare('SELECT name, phone, address FROM users WHERE id=? LIMIT 1');
$uStmt->bind_param('i', $userId);
$uStmt->execute();
if ($row = $uStmt->get_result()->fetch_assoc()) {
    $user = $row;
}
$uStmt->close();

// Build cart items
$cartItems = [];
if ($buyNowId) {
    $pStmt = $conn->prepare('SELECT id, name, price, stock, image_url FROM products WHERE id=? LIMIT 1');
    $pStmt->bind_param('i', $buyNowId);
    $pStmt->execute();
    $product = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();

    if (!$product) {
        redirectTo('/frontend/trends.html');
    }
    if ($product['stock'] < $buyNowQty) {
        $error = 'Insufficient stock for this product.';
    } else {
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
    $cStmt = $conn->prepare(
        'SELECT c.product_id, c.quantity, p.name, p.price, p.stock, p.image_url
         FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?'
    );
    $cStmt->bind_param('i', $userId);
    $cStmt->execute();
    $cartItems = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cStmt->close();

    if (empty($cartItems) && empty($_GET['success'])) {
        redirectTo('/frontend/cart.html');
    }
}

$subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
$total    = $subtotal + ($cartItems ? $deliveryFee : 0);

// ---- Process order ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order']) && empty($error)) {
    $name    = trim($_POST['full_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $payment = $_POST['payment_method'] ?? 'cod';

    if (!$name || !$phone || !$address) {
        $error = 'Please fill in name, phone, and shipping address.';
    } elseif (!preg_match('/^[0-9]{7,15}$/', preg_replace('/\s/', '', $phone))) {
        $error = 'Invalid phone number.';
    } elseif (!in_array($payment, ['cod', 'esewa'], true)) {
        $error = 'Invalid payment method selected.';
    } else {
        foreach ($cartItems as $item) {
            if ($item['stock'] < $item['quantity']) {
                $error = "'{$item['name']}' has insufficient stock.";
                break;
            }
        }
    }

    if (!$error) {
        $paymentStatus = 'unpaid';
        $oStmt = $conn->prepare(
            'INSERT INTO orders (user_id, total_amount, shipping_address, phone, payment_method, payment_status, notes)
             VALUES (?,?,?,?,?,?,?)'
        );
        $oStmt->bind_param('idsssss', $userId, $total, $address, $phone, $payment, $paymentStatus, $notes);

        if (!$oStmt->execute()) {
            $error = 'Could not place order. Please try again.';
        } else {
            $orderId = $oStmt->insert_id;
            $oStmt->close();

            foreach ($cartItems as $item) {
                $iStmt = $conn->prepare(
                    'INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)'
                );
                $iStmt->bind_param('iiid', $orderId, $item['product_id'], $item['quantity'], $item['price']);
                $iStmt->execute();
                $iStmt->close();

                $sStmt = $conn->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?');
                $sStmt->bind_param('ii', $item['quantity'], $item['product_id']);
                $sStmt->execute();
                $sStmt->close();
            }

            if (!$buyNowId) {
                $delStmt = $conn->prepare('DELETE FROM cart WHERE user_id = ?');
                $delStmt->bind_param('i', $userId);
                $delStmt->execute();
                $delStmt->close();
            }

            // eSewa — output auto-submit form (same request, like AURANOIR)
            if ($payment === 'esewa') {
                $totalStr         = number_format((float)$total, 2, '.', '');
                $transaction_uuid = esewaGenerateOrderRef($orderId);
                $signature        = esewaGenerateSignature($totalStr, $transaction_uuid, ESEWA_MERCHANT_CODE);

                $refStmt = $conn->prepare('UPDATE orders SET payment_ref=? WHERE id=?');
                $refStmt->bind_param('si', $transaction_uuid, $orderId);
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
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f1f3f6; }
    .box { text-align: center; }
    .spinner { width: 44px; height: 44px; border: 4px solid #e5e7eb; border-top-color: #111; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 20px; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="box">
    <div class="spinner"></div>
    <p style="color:#6b7280;">Redirecting to eSewa…</p>
  </div>
  <form id="esewa-form" action="<?= htmlspecialchars(ESEWA_PAY_URL) ?>" method="POST">
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
  <script>document.getElementById('esewa-form').submit();</script>
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

$paymentFailed = isset($_GET['error']) && $_GET['error'] === 'payment_failed';
$pageTitle     = 'Checkout — TrendTrack';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= SITE_BASE_URL ?>/css/style.css">
</head>
<body>
  <section class="section" style="padding-top:48px">
    <div class="container">
      <h1 style="margin-bottom:8px">Checkout</h1>
      <p style="margin-bottom:40px">Complete your order</p>

      <?php if ($paymentFailed): ?>
        <div style="margin-bottom:20px;padding:14px 18px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:0.9rem">
          eSewa payment was not completed. Please try again or choose Cash on Delivery.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div style="margin-bottom:20px;padding:14px 18px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-size:0.9rem">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="cart-layout">
        <div>
          <form method="POST" action="checkout.php<?= $buyNowId ? '?buy_now=' . $buyNowId . '&qty=' . $buyNowQty : '' ?>" id="checkoutForm">
            <input type="hidden" name="place_order" value="1">

            <div class="admin-card" style="margin-bottom:24px">
              <div class="admin-card-header">
                <h3 class="admin-card-title">📦 Delivery Details</h3>
              </div>
              <div style="padding:24px">
                <div class="form-group">
                  <label class="form-label">Full Name *</label>
                  <input type="text" name="full_name" class="form-control" required
                    value="<?= htmlspecialchars($_POST['full_name'] ?? $user['name']) ?>" placeholder="Your full name">
                </div>
                <div class="form-group">
                  <label class="form-label">Phone Number *</label>
                  <input type="tel" name="phone" class="form-control" required
                    value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone']) ?>" placeholder="98XXXXXXXX">
                </div>
                <div class="form-group">
                  <label class="form-label">Shipping Address *</label>
                  <textarea name="address" class="form-control" rows="3" required placeholder="Full delivery address"><?= htmlspecialchars($_POST['address'] ?? $user['address']) ?></textarea>
                </div>
                <div class="form-group">
                  <label class="form-label">Order Notes (optional)</label>
                  <textarea name="notes" class="form-control" rows="2" placeholder="Special instructions…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <div class="admin-card">
              <div class="admin-card-header">
                <h3 class="admin-card-title">💳 Payment Method</h3>
              </div>
              <div style="padding:24px;display:flex;flex-direction:column;gap:12px">
                <label class="pay-option" id="payEsewa">
                  <input type="radio" name="payment_method" value="esewa" style="accent-color:#60BB46">
                  <img src="<?= SITE_BASE_URL ?>/assets/payments/esewa.svg" alt="eSewa" class="payment-logo">
                  <div style="flex:1">
                    <div style="font-weight:700">eSewa</div>
                    <div style="font-size:0.78rem;color:var(--gray-400)">Pay securely via eSewa wallet</div>
                  </div>
                  <span style="background:#60BB46;color:white;padding:4px 10px;font-size:0.72rem;border-radius:4px;font-weight:600">Recommended</span>
                </label>
                <label class="pay-option disabled" id="payKhalti" title="Coming soon">
                  <input type="radio" name="payment_method" value="khalti" disabled style="accent-color:#5C2D91">
                  <img src="<?= SITE_BASE_URL ?>/assets/payments/khalti.svg" alt="Khalti" class="payment-logo">
                  <div style="flex:1">
                    <div style="font-weight:700">Khalti</div>
                    <div style="font-size:0.78rem;color:var(--gray-400)">Fast digital payments</div>
                  </div>
                  <span style="background:var(--gray-200);color:var(--gray-600);padding:4px 10px;font-size:0.72rem;border-radius:4px;font-weight:600">Soon</span>
                </label>
                <label class="pay-option selected" id="payCod">
                  <input type="radio" name="payment_method" value="cod" checked style="accent-color:var(--black)">
                  <img src="<?= SITE_BASE_URL ?>/assets/payments/cod.svg" alt="Cash on Delivery" class="payment-logo payment-logo--square">
                  <div style="flex:1">
                    <div style="font-weight:700">Cash on Delivery</div>
                    <div style="font-size:0.78rem;color:var(--gray-400)">Pay when you receive</div>
                  </div>
                </label>
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:24px;padding:16px;font-size:1rem">
              Place Order — <?= formatNpr($total) ?>
            </button>
          </form>
        </div>

        <div class="order-summary">
          <h3 style="margin-bottom:20px">Order Summary</h3>
          <?php foreach ($cartItems as $item): ?>
            <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:8px">
              <span style="color:var(--gray-700)"><?= htmlspecialchars($item['name']) ?> × <?= (int)$item['quantity'] ?></span>
              <span style="font-weight:600"><?= formatNpr($item['price'] * $item['quantity']) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="summary-row"><span>Subtotal</span><span><?= formatNpr($subtotal) ?></span></div>
          <div class="summary-row"><span>Delivery</span><span><?= formatNpr($deliveryFee) ?></span></div>
          <div class="summary-row total"><span>Total</span><span><?= formatNpr($total) ?></span></div>
          <p style="text-align:center;font-size:0.72rem;color:var(--gray-400);margin-top:16px">🔒 Secure checkout</p>
        </div>
      </div>
    </div>
  </section>

  <script src="<?= SITE_BASE_URL ?>/js/main.js"></script>
  <script>
    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
      radio.addEventListener('change', highlightPayment);
    });
    function highlightPayment() {
      const esewa = document.querySelector('input[value="esewa"]').checked;
      const cod   = document.querySelector('input[value="cod"]').checked;
      document.getElementById('payEsewa').classList.toggle('selected', esewa);
      document.getElementById('payCod').classList.toggle('selected', cod);
    }
    highlightPayment();
  </script>
</body>
</html>
