<?php
require_once '../db.php';
header('Content-Type: application/json');

$result     = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

jsonResponse(['success' => true, 'categories' => $categories]);
$conn->close();
