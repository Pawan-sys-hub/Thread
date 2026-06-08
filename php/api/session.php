<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

echo json_encode([
    'success'   => isset($_SESSION['user_id']),
    'logged_in' => isset($_SESSION['user_id']),
    'user'      => isset($_SESSION['user_id']) ? [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['role'] ?? 'customer',
    ] : null,
    'role' => $_SESSION['role'] ?? null,
]);
