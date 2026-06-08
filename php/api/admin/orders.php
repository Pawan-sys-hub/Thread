<?php
require_once '../../db.php';
header('Content-Type: application/json');
adminRequired();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT o.*, u.name AS customer_name, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) jsonResponse(['success' => false, 'error' => 'Order not found.'], 404);
    $iStmt = $conn->prepare("SELECT oi.*, p.name, p.image_url FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
    $iStmt->bind_param("i", $id);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();
    jsonResponse(['success' => true, 'order' => $order, 'items' => $items]);
}

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    $where  = ["1=1"];
    $params = [];
    $types  = '';

    if ($status) { $where[] = "o.status=?"; $params[] = $status; $types .= 's'; }
    if ($search) {
        $where[]  = "(u.name LIKE ? OR u.email LIKE ? OR o.id LIKE ?)";
        $like     = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT o.*, u.name AS customer_name, u.email AS customer_email FROM orders o JOIN users u ON o.user_id = u.id WHERE $whereSQL ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= 'ii';
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $countStmt = $conn->prepare("SELECT COUNT(*) AS t FROM orders o JOIN users u ON o.user_id = u.id WHERE $whereSQL");
    $cT = substr($types, 0, -2);
    $cP = array_slice($params, 0, -2);
    if ($cT && $cP) $countStmt->bind_param($cT, ...$cP);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['t'];
    $countStmt->close();

    jsonResponse(['success' => true, 'orders' => $orders, 'total' => (int)$total]);
}

if ($method === 'PUT') {
    $input   = json_decode(file_get_contents('php://input'), true);
    $id      = (int)($input['id'] ?? 0);
    $status  = $input['status'] ?? '';
    $pStatus = $input['payment_status'] ?? '';
    $allowed = ['pending','processing','shipped','delivered','cancelled'];

    if (!$id) jsonResponse(['success' => false, 'error' => 'Order ID required.'], 400);
    if ($status && !in_array($status, $allowed)) jsonResponse(['success' => false, 'error' => 'Invalid status.'], 400);

    $fields = [];
    $params = [];
    $types  = '';
    if ($status) { $fields[] = "status=?"; $params[] = $status; $types .= 's'; }
    if ($pStatus) { $fields[] = "payment_status=?"; $params[] = $pStatus; $types .= 's'; }
    if (empty($fields)) jsonResponse(['success' => false, 'error' => 'Nothing to update.'], 400);

    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE orders SET " . implode(',', $fields) . " WHERE id=?");
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        logAdminAction($conn, 'update_order', 'order', $id, "Status→$status");
        jsonResponse(['success' => true, 'message' => 'Order updated!']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
    }
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
