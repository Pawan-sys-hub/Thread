<?php
require_once '../db.php';
header('Content-Type: application/json');
authRequired();

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// ---- GET orders ----
if ($method === 'GET') {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId) {
        $stmt = $conn->prepare("SELECT o.*, u.name AS customer_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.user_id = ?");
        $stmt->bind_param("ii", $orderId, $userId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) jsonResponse(['success' => false, 'error' => 'Order not found.'], 404);

        $iStmt = $conn->prepare("SELECT oi.*, p.name, p.image_url, p.slug FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $iStmt->bind_param("i", $orderId);
        $iStmt->execute();
        $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $iStmt->close();
        jsonResponse(['success' => true, 'order' => $order, 'items' => $items]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonResponse(['success' => true, 'orders' => $orders]);
    }
}

// ---- POST — place order (from cart OR buy now) ----
if ($method === 'POST') {
    $input   = json_decode(file_get_contents('php://input'), true);
    $address = trim($input['shipping_address'] ?? '');
    $phone   = trim($input['phone'] ?? '');
    $payment = $input['payment_method'] ?? 'cod';
    $notes   = trim($input['notes'] ?? '');
    $buyNow  = $input['buy_now'] ?? false;   // direct buy: {product_id, quantity}

    if (!$address || !$phone) {
        jsonResponse(['success' => false, 'error' => 'Shipping address and phone are required.'], 400);
    }
    if (!preg_match('/^[0-9]{7,15}$/', preg_replace('/\s/', '', $phone))) {
        jsonResponse(['success' => false, 'error' => 'Invalid phone number.'], 400);
    }
    if (!in_array($payment, ['cod', 'esewa'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid payment method.'], 400);
    }

    // Build cart items list
    if ($buyNow) {
        // Direct buy-now: single product
        $productId = (int)($input['product_id'] ?? 0);
        $qty       = max(1, (int)($input['quantity'] ?? 1));
        if (!$productId) jsonResponse(['success' => false, 'error' => 'Product ID required for Buy Now.'], 400);

        $pStmt = $conn->prepare("SELECT id, price, stock, name FROM products WHERE id = ? LIMIT 1");
        $pStmt->bind_param("i", $productId);
        $pStmt->execute();
        $product = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();

        if (!$product) jsonResponse(['success' => false, 'error' => 'Product not found.'], 404);
        if ($product['stock'] < $qty) jsonResponse(['success' => false, 'error' => 'Insufficient stock.'], 400);

        $cartItems = [[
            'product_id' => $product['id'],
            'quantity'   => $qty,
            'price'      => $product['price'],
            'name'       => $product['name'],
        ]];
    } else {
        // Normal cart checkout
        $cartStmt = $conn->prepare("SELECT c.product_id, c.quantity, p.price, p.stock, p.name FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
        $cartStmt->bind_param("i", $userId);
        $cartStmt->execute();
        $cartItems = $cartStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cartStmt->close();

        if (empty($cartItems)) jsonResponse(['success' => false, 'error' => 'Your cart is empty.'], 400);

        // Check stock
        foreach ($cartItems as $item) {
            if ($item['stock'] < $item['quantity']) {
                jsonResponse(['success' => false, 'error' => "'{$item['name']}' has insufficient stock."], 400);
            }
        }
    }

    $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
    $paymentStatus = ($payment === 'esewa') ? 'unpaid' : 'unpaid'; // always start unpaid; eSewa verifies separately

    // Create order
    $oStmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, shipping_address, phone, payment_method, payment_status, notes) VALUES (?,?,?,?,?,?,?)");
    $oStmt->bind_param("idssss s", $userId, $total, $address, $phone, $payment, $paymentStatus, $notes);

    // Fix bind — use separate call
    $oStmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, shipping_address, phone, payment_method, payment_status, notes) VALUES (?,?,?,?,?,?,?)");
    $oStmt->bind_param("idsssss", $userId, $total, $address, $phone, $payment, $paymentStatus, $notes);

    if (!$oStmt->execute()) {
        jsonResponse(['success' => false, 'error' => 'Failed to create order: ' . $oStmt->error], 500);
    }
    $orderId = $oStmt->insert_id;
    $oStmt->close();

    // Insert order items & reduce stock
    foreach ($cartItems as $item) {
        $iStmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?,?,?,?)");
        $iStmt->bind_param("iiid", $orderId, $item['product_id'], $item['quantity'], $item['price']);
        $iStmt->execute();
        $iStmt->close();

        $sStmt = $conn->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
        $sStmt->bind_param("ii", $item['quantity'], $item['product_id']);
        $sStmt->execute();
        $sStmt->close();
    }

    // Clear cart only if not buy-now
    if (!$buyNow) {
        $delStmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $delStmt->bind_param("i", $userId);
        $delStmt->execute();
        $delStmt->close();
    }

    jsonResponse([
        'success'        => true,
        'message'        => 'Order placed successfully!',
        'order_id'       => $orderId,
        'total'          => $total,
        'payment_method' => $payment,
    ]);
}

// ---- PUT — cancel pending order ----
if ($method === 'PUT') {
    $input   = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $stmt    = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND user_id=? AND status='pending'");
    $stmt->bind_param("ii", $orderId, $userId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        jsonResponse(['success' => true, 'message' => 'Order cancelled.']);
    }
    jsonResponse(['success' => false, 'error' => 'Cannot cancel this order.'], 400);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
