<?php
require_once '../db.php';
header('Content-Type: application/json');
authRequired();

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->prepare("SELECT c.*, p.name, p.price, p.image_url, p.stock, p.slug FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items  = [];
    $total  = 0;
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
        $total  += $row['price'] * $row['quantity'];
    }
    $stmt->close();
    jsonResponse(['success' => true, 'items' => $items, 'total' => round($total, 2), 'count' => count($items)]);
}

if ($method === 'POST') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $qty       = max(1, (int)($input['quantity'] ?? 1));
    if (!$productId) jsonResponse(['success' => false, 'error' => 'Invalid product.'], 400);

    // Stock check
    $pStmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
    $pStmt->bind_param("i", $productId);
    $pStmt->execute();
    $prod = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    if (!$prod || $prod['stock'] < $qty) jsonResponse(['success' => false, 'error' => 'Not enough stock.'], 400);

    $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
    $stmt->bind_param("iiii", $userId, $productId, $qty, $qty);
    if ($stmt->execute()) jsonResponse(['success' => true, 'message' => 'Added to cart!']);
    else jsonResponse(['success' => false, 'error' => 'Failed to add.'], 500);
    $stmt->close();
}

if ($method === 'PUT') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $qty       = max(1, (int)($input['quantity'] ?? 1));
    $stmt      = $conn->prepare("UPDATE cart SET quantity=? WHERE user_id=? AND product_id=?");
    $stmt->bind_param("iii", $qty, $userId, $productId);
    if ($stmt->execute()) jsonResponse(['success' => true, 'message' => 'Cart updated.']);
    else jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
    $stmt->close();
}

if ($method === 'DELETE') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId) {
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id=? AND product_id=?");
        $stmt->bind_param("ii", $userId, $productId);
    } else {
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id=?");
        $stmt->bind_param("i", $userId);
    }
    if ($stmt->execute()) jsonResponse(['success' => true, 'message' => 'Removed.']);
    else jsonResponse(['success' => false, 'error' => 'Failed.'], 500);
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
