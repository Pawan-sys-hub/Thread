<?php
require_once '../db.php';
header('Content-Type: application/json');
authRequired();

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->prepare("SELECT id, name, email, phone, address, avatar, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    jsonResponse(['success' => true, 'user' => $user]);
}

if ($method === 'PUT') {
    $input   = json_decode(file_get_contents('php://input'), true);
    $name    = trim($input['name'] ?? '');
    $phone   = trim($input['phone'] ?? '');
    $address = trim($input['address'] ?? '');
    $avatar  = trim($input['avatar'] ?? '');

    if (!$name) jsonResponse(['success' => false, 'error' => 'Name is required.'], 400);

    // Handle password change
    if (!empty($input['new_password'])) {
        $current = $input['current_password'] ?? '';
        $chk = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $chk->bind_param("i", $userId);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!password_verify($current, $row['password'])) {
            jsonResponse(['success' => false, 'error' => 'Current password is incorrect.'], 400);
        }
        $newHash = password_hash($input['new_password'], PASSWORD_DEFAULT);
        $stmt    = $conn->prepare("UPDATE users SET name=?, phone=?, address=?, avatar=?, password=? WHERE id=?");
        $stmt->bind_param("sssssi", $name, $phone, $address, $avatar, $newHash, $userId);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, phone=?, address=?, avatar=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $phone, $address, $avatar, $userId);
    }

    if ($stmt->execute()) {
        $_SESSION['user_name'] = $name;
        jsonResponse(['success' => true, 'message' => 'Profile updated!']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
    }
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
