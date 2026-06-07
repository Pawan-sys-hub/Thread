<?php
require_once '../db.php';
header('Content-Type: application/json');

$category = $_GET['category'] ?? '';
$trending = $_GET['trending'] ?? '';
$featured = $_GET['featured'] ?? '';
$search   = $_GET['search'] ?? '';
$limit    = min((int)($_GET['limit'] ?? 12), 50);
$offset   = (int)($_GET['offset'] ?? 0);

$where  = ["1=1"];
$params = [];
$types  = '';

if ($category) {
    $where[]  = "c.slug = ?";
    $params[] = $category;
    $types   .= 's';
}
if ($trending === '1') {
    $where[] = "p.is_trending = 1";
}
if ($featured === '1') {
    $where[] = "p.is_featured = 1";
}
if ($search) {
    $where[]  = "(p.name LIKE ? OR p.description LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$whereSQL = implode(' AND ', $where);
$sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE $whereSQL
        ORDER BY p.trend_score DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types   .= 'ii';

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

// Count
$countSQL  = "SELECT COUNT(*) AS total FROM products p JOIN categories c ON p.category_id = c.id WHERE $whereSQL";
$countStmt = $conn->prepare($countSQL);
$cTypes    = substr($types, 0, -2);
$cParams   = array_slice($params, 0, -2);
if ($cTypes && $cParams) $countStmt->bind_param($cTypes, ...$cParams);
$countStmt->execute();
$total = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

jsonResponse(['success' => true, 'products' => $products, 'total' => (int)$total, 'limit' => $limit, 'offset' => $offset]);
$conn->close();
