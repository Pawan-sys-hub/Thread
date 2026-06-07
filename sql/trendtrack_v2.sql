-- ================================================================
-- TrendTrack V2 — Fashion E-Commerce Database
-- Run: mysql -u pawan -p'Pawan@9866!' < sql/trendtrack_v2.sql
-- ================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:45';

CREATE DATABASE IF NOT EXISTS trendtrack_v2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE trendtrack_v2;

-- -----------------------------------------------
-- Users
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    avatar VARCHAR(255) DEFAULT NULL,
    role ENUM('customer','admin') DEFAULT 'customer',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -----------------------------------------------
-- Categories (Fashion only: Men, Women, Accessories)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT '👗',
    description TEXT,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------
-- Products
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    original_price DECIMAL(10,2) DEFAULT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    stock INT DEFAULT 100,
    trend_score INT DEFAULT 0,
    is_trending TINYINT(1) DEFAULT 0,
    is_featured TINYINT(1) DEFAULT 0,
    is_hot TINYINT(1) DEFAULT 0,
    badge VARCHAR(50) DEFAULT NULL COMMENT 'e.g. Trending, New Arrival, Best Seller',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Cart
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cart_item (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Wishlist
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_wishlist_item (user_id, product_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Orders
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    shipping_address TEXT NOT NULL,
    phone VARCHAR(20),
    status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_method VARCHAR(50) DEFAULT 'cod',
    payment_status ENUM('unpaid','paid','failed') DEFAULT 'unpaid',
    payment_ref VARCHAR(200) DEFAULT NULL COMMENT 'eSewa transaction ref',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Order Items
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- -----------------------------------------------
-- Admin Activity Logs
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS admin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ================================================================
-- SEED DATA
-- ================================================================

-- Admin user (password: Admin@123)
INSERT INTO users (name, email, password, phone, role) VALUES
('Admin', 'admin@trendtrack.com', '$2y$10$TwR4KvZcR0jgMKm.nOhxDOSGbI9Hlt1u.GqBa0kBtfaVqGAi7EJMO', '9800000000', 'admin')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Categories
INSERT INTO categories (name, slug, icon, description, display_order) VALUES
('Men''s Fashion', 'men', '👔', 'T-shirts, hoodies, jeans, jackets & sneakers for men', 1),
('Women''s Fashion', 'women', '👗', 'Dresses, tops, skirts, heels & handbags for women', 2),
('Accessories', 'accessories', '⌚', 'Watches, sunglasses, caps, belts & chains', 3)
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- ================================================================
-- PRODUCTS
-- MEN (category_id = 1)
-- ================================================================
INSERT INTO products (category_id, name, slug, description, price, original_price, image_url, stock, trend_score, is_trending, is_featured, is_hot, badge) VALUES

(1, 'Classic Fit Graphic Tee', 'men-classic-graphic-tee',
 'Premium cotton graphic tee with bold print. Perfect for casual everyday style.',
 1299, 1999, 'https://images.unsplash.com/photo-1527719327859-c6ce80353573?w=600', 80, 95, 1, 1, 1, 'Best Seller'),

(1, 'Oversized Streetwear Hoodie', 'men-oversized-hoodie',
 'Ultra-soft oversized hoodie with kangaroo pocket. Trending streetwear essential.',
 3499, 4999, 'https://images.unsplash.com/photo-1556821840-3a63f15732ce?w=600', 60, 92, 1, 1, 1, 'Trending'),

(1, 'Slim Fit Denim Jeans', 'men-slim-fit-jeans',
 'Classic slim-fit denim jeans in medium wash. A wardrobe staple.',
 4299, 5999, 'https://images.unsplash.com/photo-1542272604-787c3835535d?w=600', 70, 88, 1, 0, 1, 'Best Seller'),

(1, 'Premium Leather Jacket', 'men-leather-jacket',
 'Genuine leather biker jacket with multiple pockets. Iconic and timeless.',
 12999, 18999, 'https://images.unsplash.com/photo-1551028719-00167b16eac5?w=600', 25, 90, 1, 1, 0, 'New Arrival'),

(1, 'Classic White Sneakers', 'men-white-sneakers',
 'Minimalist leather sneakers for everyday wear. Clean and versatile.',
 7999, 10999, 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=600', 50, 97, 1, 1, 1, 'Trending'),

(1, 'Cargo Utility Pants', 'men-cargo-pants',
 'Functional cargo pants with multiple utility pockets. Trending utilitarian style.',
 3999, 5499, 'https://images.unsplash.com/photo-1624378439575-d8705ad7ae80?w=600', 45, 85, 1, 0, 0, 'Trending'),

(1, 'Linen Summer Shirt', 'men-linen-shirt',
 'Breathable linen shirt perfect for summer outings. Light and stylish.',
 2499, 3499, 'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?w=600', 65, 78, 0, 1, 0, 'New Arrival'),

-- ================================================================
-- WOMEN (category_id = 2)
-- ================================================================
(2, 'Floral Wrap Dress', 'women-floral-wrap-dress',
 'Elegant floral wrap dress with adjustable waist tie. Perfect for summer and evenings.',
 3799, 5499, 'https://images.unsplash.com/photo-1572804013309-59a88b7e92f1?w=600', 55, 96, 1, 1, 1, 'Best Seller'),

(2, 'Designer Leather Handbag', 'women-designer-handbag',
 'Premium leather handbag with gold hardware. Spacious and luxurious.',
 8999, 12999, 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=600', 30, 91, 1, 1, 1, 'Trending'),

(2, 'Stiletto Block Heels', 'women-block-heels',
 'Elegant block heels for formal and semi-formal occasions. Comfortable all-day wear.',
 4999, 6999, 'https://images.unsplash.com/photo-1555529669-e69e7aa0ba9a?w=600', 40, 87, 1, 0, 1, 'Best Seller'),

(2, 'Crop Top & Co-ord Set', 'women-coord-set',
 'Trendy crop top and high-waist skirt co-ord set. Mix and match for multiple looks.',
 2999, 4499, 'https://images.unsplash.com/photo-1594938291221-94f18cbb5d56?w=600', 60, 94, 1, 1, 1, 'Trending'),

(2, 'A-Line Mini Skirt', 'women-mini-skirt',
 'Classic A-line mini skirt in pleated satin. Pairs perfectly with crop tops.',
 1999, 2999, 'https://images.unsplash.com/photo-1583496661160-fb5218afa9a3?w=600', 70, 82, 0, 1, 0, 'New Arrival'),

(2, 'Bohemian Maxi Dress', 'women-maxi-dress',
 'Flowing boho maxi dress with subtle floral embroidery. Effortlessly chic.',
 4499, 6499, 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?w=600', 35, 89, 1, 1, 0, 'Trending'),

(2, 'Off-Shoulder Knit Top', 'women-knit-top',
 'Soft off-shoulder knit top in neutral tones. Perfect for layering.',
 2299, 3299, 'https://images.unsplash.com/photo-1564257631407-4deb1f99d992?w=600', 55, 80, 0, 0, 0, 'New Arrival'),

-- ================================================================
-- ACCESSORIES (category_id = 3)
-- ================================================================
(3, 'Luxury Chronograph Watch', 'acc-chronograph-watch',
 'Stainless steel chronograph watch with leather strap. Elegance meets precision.',
 14999, 21999, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600', 20, 98, 1, 1, 1, 'Best Seller'),

(3, 'Polarized Aviator Sunglasses', 'acc-aviator-sunglasses',
 'Classic aviator frames with polarized UV400 lenses. Timeless summer accessory.',
 2999, 4499, 'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=600', 80, 93, 1, 1, 1, 'Trending'),

(3, 'Snapback Sports Cap', 'acc-snapback-cap',
 'Adjustable snapback cap with embroidered logo. Streetwear essential.',
 1499, 1999, 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?w=600', 100, 86, 1, 0, 1, 'Trending'),

(3, 'Genuine Leather Belt', 'acc-leather-belt',
 'Full-grain leather belt with polished metal buckle. Classic formal-to-casual.',
 1999, 2999, 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600', 90, 79, 0, 1, 0, 'Best Seller'),

(3, 'Gold Chain Necklace', 'acc-gold-chain',
 '18K gold plated chain necklace. Bold statement piece for any outfit.',
 3499, 5499, 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=600', 60, 91, 1, 1, 1, 'New Arrival'),

(3, 'Canvas Backpack', 'acc-canvas-backpack',
 'Durable canvas backpack with multiple compartments and USB charging port.',
 3999, 5499, 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600', 55, 84, 0, 1, 0, 'Trending'),

(3, 'Minimalist Bangle Set', 'acc-bangle-set',
 'Set of 5 minimalist bangles in silver and gold finish. Stack and shine.',
 1299, 1999, 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=600', 120, 77, 0, 0, 0, 'New Arrival');
