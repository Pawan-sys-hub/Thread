<?php
require_once '../db.php';
header('Content-Type: application/json');

$slug = $_GET['slug'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!$slug && !$id) {
    jsonResponse(['success' => false, 'error' => 'Product slug or id required.'], 400);
}

$where = $slug ? "p.slug = ?" : "p.id = ?";
$type  = $slug ? 's' : 'i';
$val   = $slug ?: $id;

$sql  = "SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p JOIN categories c ON p.category_id = c.id WHERE $where";
$stmt = $conn->prepare($sql);
$stmt->bind_param($type, $val);
$stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    jsonResponse(['success' => false, 'error' => 'Product not found.'], 404);
}

// Related products
$relStmt = $conn->prepare("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.category_id = ? AND p.id != ? ORDER BY p.trend_score DESC LIMIT 4");
$relStmt->bind_param("ii", $row['category_id'], $row['id']);
$relStmt->execute();
$relResult = $relStmt->get_result();
$related   = [];
while ($r = $relResult->fetch_assoc()) $related[] = $r;
$relStmt->close();

jsonResponse(['success' => true, 'product' => $row, 'related' => $related]);
$conn->close();
