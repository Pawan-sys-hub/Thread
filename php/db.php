<?php
// Core DB & session + shared utilities for all PHP API files
define('DB_HOST', 'localhost');
define('DB_USER', 'pawan');
define('DB_PASS', 'Pawan@9866!');
define('DB_NAME', 'trendtrack_v2');

// Start session only once
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS headers (useful for all API files)
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$conn->set_charset('utf8mb4');

// ---- Helpers ----
function jsonResponse($data, $code = 200) {
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function authRequired() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized. Please login.'], 401);
    }
}

function adminRequired() {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Admin access required.'], 403);
    }
}

function logAdminAction($conn, $action, $targetType = null, $targetId = null, $details = null) {
    $adminId = $_SESSION['user_id'];
    $stmt    = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issis", $adminId, $action, $targetType, $targetId, $details);
    $stmt->execute();
    $stmt->close();
}
