#!/bin/bash

echo "🚀 Setting up Restaurant Management System in Codespace..."

# Update and install dependencies
sudo apt-get update
sudo apt-get install -y mariadb-client

# Install PHP extensions
sudo docker-php-ext-install mysqli pdo pdo_mysql

# Create database configuration
echo "📦 Creating database configuration..."
cat > config/config.php << 'EOL'
<?php
declare(strict_types=1);

// Detect if running in Codespace
$is_codespace = getenv('CODESPACES') === 'true';

if ($is_codespace) {
    // Codespace configuration
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'restaurant_db');
    define('SITE_NAME', 'Restaurant System - Codespace');
    
    // Auto-detect Codespace URL
    $codespace_name = getenv('CODESPACE_NAME');
    $forwarding_domain = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN');
    define('SITE_URL', "https://{$codespace_name}-8080.{$forwarding_domain}");
} else {
    // Production configuration (update these for your cPanel)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'your_db_user');
    define('DB_PASS', 'your_db_password');
    define('DB_NAME', 'your_db_name');
    define('SITE_NAME', 'Your Restaurant Name');
    define('SITE_URL', 'https://yourdomain.com');
}

// Common configuration
define('DEBUG_MODE', true);
define('SESSION_TIMEOUT', 3600);

// Error reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Create database connection
function getDBConnection() {
    static $db = null;
    
    if ($db === null) {
        try {
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($db->connect_error) {
                throw new Exception("Database connection failed: " . $db->connect_error);
            }
            
            $db->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            
            if (DEBUG_MODE) {
                die("Database connection error: " . $e->getMessage());
            } else {
                die("System temporarily unavailable. Please try again later.");
            }
        }
    }
    
    return $db;
}
?>
EOL

# Start MySQL service
sudo service mysql start

# Create database and sample data
mysql -u root -e "CREATE DATABASE IF NOT EXISTS restaurant_db;"
mysql -u root -e "CREATE USER IF NOT EXISTS 'restaurant_user'@'localhost' IDENTIFIED BY 'password';"
mysql -u root -e "GRANT ALL PRIVILEGES ON restaurant_db.* TO 'restaurant_user'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;"

# Create database schema
mysql -u root restaurant_db << 'SQL'
CREATE TABLE IF NOT EXISTS comp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'waiter', 'chef', 'cashier') NOT NULL DEFAULT 'waiter',
    display_name VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    api_token VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    slug VARCHAR(100),
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    tags VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS restaurant_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(50) UNIQUE NOT NULL,
    capacity INT,
    status ENUM('available', 'occupied', 'reserved') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(50) UNIQUE NOT NULL,
    table_id INT,
    custom_id VARCHAR(100),
    amount DECIMAL(10,2),
    total_amount DECIMAL(10,2),
    status ENUM('pending', 'confirmed', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    payment_via VARCHAR(50),
    msisdn VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES restaurant_tables(id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT,
    item_id INT,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    table_number VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);

CREATE TABLE IF NOT EXISTS item_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT,
    company_code VARCHAR(50),
    image_path VARCHAR(500),
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id)
);

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO comp_users (username, password, role, display_name) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'System Administrator');

-- Insert test users
INSERT IGNORE INTO comp_users (username, password, role, display_name) VALUES
('Besufekad', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'Besufekad'),
('Addis', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Addis Admin'),
('waiter', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'waiter', 'Test Waiter'),
('chef', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'chef', 'Test Chef'),
('cashier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'Test Cashier');

-- Insert sample categories
INSERT IGNORE INTO categories (name, description, slug, display_order) VALUES
('Appetizers', 'Start your meal right', 'appetizers', 1),
('Main Course', 'Delicious main dishes', 'main-course', 2),
('Desserts', 'Sweet endings', 'desserts', 3),
('Beverages', 'Refreshing drinks', 'beverages', 4);

-- Insert sample items
INSERT IGNORE INTO items (name, description, category_id, price) VALUES
('Caesar Salad', 'Fresh romaine lettuce with Caesar dressing', 1, 8.99),
('Garlic Bread', 'Toasted bread with garlic butter', 1, 4.99),
('Grilled Salmon', 'Fresh salmon with lemon butter sauce', 2, 22.99),
('Chicken Parmesan', 'Breaded chicken with marinara sauce', 2, 18.99),
('Chocolate Cake', 'Rich chocolate layer cake', 3, 6.99),
('Ice Cream', 'Vanilla bean ice cream', 3, 4.99),
('Coffee', 'Freshly brewed coffee', 4, 2.99),
('Orange Juice', 'Fresh squeezed orange juice', 4, 3.99);

-- Insert sample tables
INSERT IGNORE INTO restaurant_tables (table_number, capacity, status) VALUES
('T01', 4, 'available'),
('T02', 2, 'available'),
('T03', 6, 'available'),
('T04', 4, 'available'),
('T05', 8, 'available');
SQL

# Create necessary directories
mkdir -p images/menu/global/{appetizers,main-course,desserts,beverages}
mkdir -p icons
mkdir -p database

# Generate placeholder icons
echo "🎨 Generating placeholder icons..."
for size in 72 96 128 144 152 192 384 512; do
    cat > "icons/icon-${size}x${size}.svg" << EOF
<svg width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg">
  <rect width="${size}" height="${size}" fill="#3498db"/>
  <text x="50%" y="50%" font-family="Arial" font-size="${size}/5" fill="white" text-anchor="middle" dy=".3em">RMS</text>
</svg>
EOF
done

# Create placeholder images
echo "📸 Creating placeholder images..."
for category in appetizers main-course desserts beverages; do
    touch "images/menu/global/${category}/placeholder.jpg"
done

# Set proper permissions
chmod -R 755 images/
chmod 644 config/config.php

echo "✅ Setup complete!"
echo "🌐 Your application will be available at: https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
echo "🔑 Default login: admin / admin123"
echo "🔑 Test users: Besufekad, Addis, waiter, chef, cashier / admin123"
