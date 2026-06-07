<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input    = json_decode(file_get_contents('php://input'), true);
$name     = trim($input['name'] ?? '');
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$phone    = trim($input['phone'] ?? '');

if (!$name || !$email || !$password) {
    jsonResponse(['success' => false, 'error' => 'Name, email, and password are required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Invalid email address.'], 400);
}

if (strlen($password) < 6) {
    jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters.'], 400);
}

// Phone validation (optional but must be digits if provided)
if ($phone && !preg_match('/^[0-9]{7,15}$/', $phone)) {
    jsonResponse(['success' => false, 'error' => 'Invalid phone number. Use digits only (7-15 digits).'], 400);
}

// Check duplicate email
$check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->bind_param("s", $email);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    jsonResponse(['success' => false, 'error' => 'An account with this email already exists.'], 409);
}
$check->close();

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')");
$stmt->bind_param("ssss", $name, $email, $hash, $phone);

if (!$stmt->execute()) {
    jsonResponse(['success' => false, 'error' => 'Registration failed: ' . $stmt->error], 500);
}

$newId = $stmt->insert_id;
$stmt->close();

$_SESSION['user_id']    = $newId;
$_SESSION['user_name']  = $name;
$_SESSION['user_email'] = $email;
$_SESSION['role']       = 'customer';

jsonResponse([
    'success' => true,
    'message' => 'Account created! Welcome to TrendTrack.',
    'redirect' => '/TrendTrackV2/frontend/index.html',
    'user'    => ['id' => $newId, 'name' => $name, 'email' => $email, 'role' => 'customer']
]);
$conn->close();
