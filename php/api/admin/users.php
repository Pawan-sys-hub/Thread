<?php
require_once '../../db.php';
header('Content-Type: application/json');
adminRequired();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = $_GET['search'] ?? '';
    $role   = $_GET['role'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    $where  = ["1=1"];
    $params = [];
    $types  = '';

    if ($search) {
        $where[]  = "(name LIKE ? OR email LIKE ?)";
        $like     = "%$search%";
        $params[] = $like; $params[] = $like;
        $types   .= 'ss';
    }
    if ($role) { $where[] = "role=?"; $params[] = $role; $types .= 's'; }

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT id, name, email, phone, role, is_active, created_at FROM users WHERE $whereSQL ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= 'ii';
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $cStmt = $conn->prepare("SELECT COUNT(*) AS t FROM users WHERE $whereSQL");
    $cT = substr($types, 0, -2);
    $cP = array_slice($params, 0, -2);
    if ($cT && $cP) $cStmt->bind_param($cT, ...$cP);
    $cStmt->execute();
    $total = $cStmt->get_result()->fetch_assoc()['t'];
    $cStmt->close();

    jsonResponse(['success' => true, 'users' => $users, 'total' => (int)$total]);
}

if ($method === 'PUT') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $id       = (int)($input['id'] ?? 0);
    $isActive = isset($input['is_active']) ? (int)$input['is_active'] : null;

    if (!$id) jsonResponse(['success' => false, 'error' => 'User ID required.'], 400);

    // Prevent admin from deactivating themselves
    if ($id === (int)$_SESSION['user_id']) jsonResponse(['success' => false, 'error' => 'Cannot modify your own account.'], 400);

    if ($isActive !== null) {
        $stmt = $conn->prepare("UPDATE users SET is_active=? WHERE id=? AND role='customer'");
        $stmt->bind_param("ii", $isActive, $id);
        if ($stmt->execute()) {
            $action = $isActive ? 'activate_user' : 'deactivate_user';
            logAdminAction($conn, $action, 'user', $id);
            jsonResponse(['success' => true, 'message' => $isActive ? 'User activated.' : 'User deactivated.']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
        }
        $stmt->close();
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
