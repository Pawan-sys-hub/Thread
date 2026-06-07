<?php
require_once '../../db.php';
header('Content-Type: application/json');
adminRequired();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search   = $_GET['search'] ?? '';
    $category = (int)($_GET['category_id'] ?? 0);
    $limit    = min((int)($_GET['limit'] ?? 20), 100);
    $offset   = (int)($_GET['offset'] ?? 0);
    $where    = ["1=1"];
    $params   = [];
    $types    = '';

    if ($search) {
        $where[]  = "(p.name LIKE ? OR p.description LIKE ?)";
        $like     = "%$search%";
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }
    if ($category) {
        $where[]  = "p.category_id = ?";
        $params[] = $category;
        $types   .= 'i';
    }

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE $whereSQL ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types   .= 'ii';
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $countStmt = $conn->prepare("SELECT COUNT(*) AS t FROM products p WHERE $whereSQL");
    $cTypes    = substr($types, 0, -2);
    $cParams   = array_slice($params, 0, -2);
    if ($cTypes && $cParams) $countStmt->bind_param($cTypes, ...$cParams);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['t'];
    $countStmt->close();

    jsonResponse(['success' => true, 'products' => $products, 'total' => (int)$total]);
}

if ($method === 'POST') {
    $input      = json_decode(file_get_contents('php://input'), true);
    $name       = trim($input['name'] ?? '');
    $catId      = (int)($input['category_id'] ?? 0);
    $price      = (float)($input['price'] ?? 0);
    $origPrice  = $input['original_price'] ? (float)$input['original_price'] : null;
    $desc       = trim($input['description'] ?? '');
    $imageUrl   = trim($input['image_url'] ?? '');
    $stock      = (int)($input['stock'] ?? 100);
    $trendScore = (int)($input['trend_score'] ?? 0);
    $isTrending = (int)(!empty($input['is_trending']));
    $isFeatured = (int)(!empty($input['is_featured']));

    if (!$name || !$catId || $price <= 0) jsonResponse(['success' => false, 'error' => 'Name, category, and price are required.'], 400);

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . time();
    $stmt = $conn->prepare("INSERT INTO products (category_id, name, slug, description, price, original_price, image_url, stock, trend_score, is_trending, is_featured) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssddsiiii", $catId, $name, $slug, $desc, $price, $origPrice, $imageUrl, $stock, $trendScore, $isTrending, $isFeatured);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        logAdminAction($conn, 'create_product', 'product', $newId, "Created: $name");
        jsonResponse(['success' => true, 'message' => 'Product created!', 'id' => $newId]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Failed to create product.'], 500);
    }
    $stmt->close();
}

if ($method === 'PUT') {
    $input      = json_decode(file_get_contents('php://input'), true);
    $id         = (int)($input['id'] ?? 0);
    $name       = trim($input['name'] ?? '');
    $catId      = (int)($input['category_id'] ?? 0);
    $price      = (float)($input['price'] ?? 0);
    $origPrice  = isset($input['original_price']) && $input['original_price'] !== '' ? (float)$input['original_price'] : null;
    $desc       = trim($input['description'] ?? '');
    $imageUrl   = trim($input['image_url'] ?? '');
    $stock      = (int)($input['stock'] ?? 0);
    $trendScore = (int)($input['trend_score'] ?? 0);
    $isTrending = (int)(!empty($input['is_trending']));
    $isFeatured = (int)(!empty($input['is_featured']));

    if (!$id || !$name || !$catId || $price <= 0) jsonResponse(['success' => false, 'error' => 'Missing required fields.'], 400);

    $stmt = $conn->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, original_price=?, image_url=?, stock=?, trend_score=?, is_trending=?, is_featured=? WHERE id=?");
    $stmt->bind_param("issddsiiiii", $catId, $name, $desc, $price, $origPrice, $imageUrl, $stock, $trendScore, $isTrending, $isFeatured, $id);
    if ($stmt->execute()) {
        logAdminAction($conn, 'update_product', 'product', $id, "Updated: $name");
        jsonResponse(['success' => true, 'message' => 'Product updated!']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
    }
    $stmt->close();
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'error' => 'Product ID required.'], 400);
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logAdminAction($conn, 'delete_product', 'product', $id, "Deleted product ID: $id");
        jsonResponse(['success' => true, 'message' => 'Product deleted.']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Delete failed.'], 500);
    }
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
