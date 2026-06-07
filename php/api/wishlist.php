<?php
require_once '../db.php';
header('Content-Type: application/json');
authRequired();

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->prepare("SELECT w.*, p.name, p.price, p.original_price, p.image_url, p.slug, p.is_trending, c.name AS category_name FROM wishlist w JOIN products p ON w.product_id = p.id JOIN categories c ON p.category_id = c.id WHERE w.user_id = ? ORDER BY w.created_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items  = [];
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $stmt->close();
    jsonResponse(['success' => true, 'items' => $items]);
}

if ($method === 'POST') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    if (!$productId) jsonResponse(['success' => false, 'error' => 'Invalid product.'], 400);
    $stmt = $conn->prepare("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?,?)");
    $stmt->bind_param("ii", $userId, $productId);
    if ($stmt->execute()) jsonResponse(['success' => true, 'message' => 'Added to wishlist!']);
    else jsonResponse(['success' => false, 'error' => 'Failed.'], 500);
    $stmt->close();
}

if ($method === 'DELETE') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $stmt      = $conn->prepare("DELETE FROM wishlist WHERE user_id=? AND product_id=?");
    $stmt->bind_param("ii", $userId, $productId);
    if ($stmt->execute()) jsonResponse(['success' => true, 'message' => 'Removed from wishlist.']);
    else jsonResponse(['success' => false, 'error' => 'Failed.'], 500);
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
