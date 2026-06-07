<?php
require_once '../../db.php';
header('Content-Type: application/json');
adminRequired();

// Stats
$stats = [];
$stats['total_users']    = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='customer'")->fetch_assoc()['c'];
$stats['total_products'] = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'];
$stats['total_orders']   = $conn->query("SELECT COUNT(*) AS c FROM orders")->fetch_assoc()['c'];
$stats['total_revenue']  = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS r FROM orders WHERE status != 'cancelled'")->fetch_assoc()['r'];
$stats['pending_orders'] = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='pending'")->fetch_assoc()['c'];
$stats['low_stock']      = $conn->query("SELECT COUNT(*) AS c FROM products WHERE stock < 10")->fetch_assoc()['c'];

// Orders by status
$statusResult = $conn->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status");
$stats['orders_by_status'] = [];
while ($row = $statusResult->fetch_assoc()) $stats['orders_by_status'][$row['status']] = (int)$row['cnt'];

// Recent 10 orders
$recentResult = $conn->query("SELECT o.id, o.total_amount, o.status, o.payment_method, o.created_at, u.name AS customer_name, u.email FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 10");
$recentOrders = [];
while ($row = $recentResult->fetch_assoc()) $recentOrders[] = $row;

// Top 5 products by order count
$topResult = $conn->query("SELECT p.name, p.image_url, COUNT(oi.id) AS sold FROM order_items oi JOIN products p ON oi.product_id = p.id GROUP BY oi.product_id ORDER BY sold DESC LIMIT 5");
$topProducts = [];
while ($row = $topResult->fetch_assoc()) $topProducts[] = $row;

jsonResponse(['success' => true, 'stats' => $stats, 'recent_orders' => $recentOrders, 'top_products' => $topProducts]);
$conn->close();
