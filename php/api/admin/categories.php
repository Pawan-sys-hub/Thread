<?php
require_once '../../db.php';
header('Content-Type: application/json');
adminRequired();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    $cats   = $result->fetch_all(MYSQLI_ASSOC);
    jsonResponse(['success' => true, 'categories' => $cats]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name  = trim($input['name'] ?? '');
    $icon  = trim($input['icon'] ?? '🔥');
    $desc  = trim($input['description'] ?? '');
    if (!$name) jsonResponse(['success' => false, 'error' => 'Category name is required.'], 400);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $stmt = $conn->prepare("INSERT INTO categories (name, slug, icon, description) VALUES (?,?,?,?)");
    $stmt->bind_param("ssss", $name, $slug, $icon, $desc);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        logAdminAction($conn, 'create_category', 'category', $newId, "Created: $name");
        jsonResponse(['success' => true, 'message' => 'Category created!', 'id' => $newId]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Failed to create (slug may exist).'], 500);
    }
    $stmt->close();
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = (int)($input['id'] ?? 0);
    $name  = trim($input['name'] ?? '');
    $icon  = trim($input['icon'] ?? '🔥');
    $desc  = trim($input['description'] ?? '');
    if (!$id || !$name) jsonResponse(['success' => false, 'error' => 'ID and name required.'], 400);
    $stmt = $conn->prepare("UPDATE categories SET name=?, icon=?, description=? WHERE id=?");
    $stmt->bind_param("sssi", $name, $icon, $desc, $id);
    if ($stmt->execute()) {
        logAdminAction($conn, 'update_category', 'category', $id, "Updated: $name");
        jsonResponse(['success' => true, 'message' => 'Category updated!']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Update failed.'], 500);
    }
    $stmt->close();
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'error' => 'ID required.'], 400);
    $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logAdminAction($conn, 'delete_category', 'category', $id, "Deleted category ID: $id");
        jsonResponse(['success' => true, 'message' => 'Category deleted.']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Delete failed.'], 500);
    }
    $stmt->close();
}

jsonResponse(['success' => false, 'error' => 'Method not allowed.'], 405);
$conn->close();
