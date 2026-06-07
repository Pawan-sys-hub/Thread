<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

$response = [
    'success' => isset($_SESSION['user_id']),
    'logged_in' => isset($_SESSION['user_id']),
    'user' => isset($_SESSION['user_id']) ? [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['role'] ?? 'customer',
    ] : null,
    'role' => $_SESSION['role'] ?? null
];

echo json_encode($response);
exit;