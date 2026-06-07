<?php
// ---- CORS & Headers ----
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---- DB ----
require_once '../db.php';   // FIXED: was wrong path "../config/db.php"

// ---- Method Check ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
}

// ---- Input ----
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!$email || !$password) {
    jsonResponse(['success' => false, 'error' => 'Email and password are required.'], 400);
}

// ---- Query ----
$stmt = $conn->prepare("SELECT id, name, email, password, role, is_active FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    jsonResponse(['success' => false, 'error' => 'Invalid email or password.'], 401);
}

$user = $result->fetch_assoc();
$stmt->close();

// ---- Active check ----
if (!$user['is_active']) {
    jsonResponse(['success' => false, 'error' => 'Your account has been deactivated.'], 403);
}

// ---- Password Verify ----
if (!password_verify($password, $user['password'])) {
    jsonResponse(['success' => false, 'error' => 'Invalid email or password.'], 401);
}

// ---- Session ----
$_SESSION['user_id']    = $user['id'];
$_SESSION['user_name']  = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['role']       = $user['role'];

$redirectUrl = ($user['role'] === 'admin')
    ? '/TrendTrackV2/frontend/admin/index.html'
    : '/TrendTrackV2/frontend/index.html';

jsonResponse([
    'success'  => true,
    'message'  => 'Login successful!',
    'role'     => $user['role'],
    'redirect' => $redirectUrl,
    'user'     => [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ],
]);