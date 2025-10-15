<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

// Database connection
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }
    $db->set_charset("utf8");
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Redirect to login if not logged in as staff
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Initialize CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Safely assign session variables from staff session
$staff_id = $_SESSION['staff_id'] ?? '';
$staff_username = htmlspecialchars($_SESSION['staff_username'] ?? '', ENT_QUOTES, 'UTF-8');
$staff_name = htmlspecialchars($_SESSION['staff_name'] ?? 'Staff User', ENT_QUOTES, 'UTF-8');
$staff_role = htmlspecialchars($_SESSION['staff_role'] ?? 'staff', ENT_QUOTES, 'UTF-8');
$company_name = SITE_NAME;

// Get role-based navigation
// Get role-based navigation
function getNavigationByRole($role) {
    $nav_items = [
        'dashboard' => ['icon' => 'fas fa-home', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
        'items' => ['icon' => 'fas fa-cubes', 'label' => 'Items', 'url' => 'display.php'],
        'categories' => ['icon' => 'fas fa-tags', 'label' => 'Categories', 'url' => 'categories.php'],
        'staff' => ['icon' => 'fas fa-users', 'label' => 'Staff', 'url' => 'users.php'],
        'orders' => ['icon' => 'fas fa-shopping-cart', 'label' => 'Orders', 'url' => 'orders.php'],
        'reports' => ['icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'url' => 'reports.php'],
    ];
    
    // Super admin and admin have full access (excluding Tables)
    if (in_array($role, ['super_admin', 'admin'])) {
        return $nav_items;
    }
    
    // Waiter specific navigation (excluding Tables)
    if ($role === 'waiter') {
        return [
            'dashboard' => $nav_items['dashboard'],
            'items' => $nav_items['items'],
            'categories' => $nav_items['categories'],
            'orders' => $nav_items['orders']
        ];
    }
    
    // Chef specific navigation
    if ($role === 'chef') {
        return [
            'dashboard' => $nav_items['dashboard'],
            'kitchen' => ['icon' => 'fas fa-utensils', 'label' => 'Kitchen', 'url' => 'kitchen_order.php']
        ];
    }
    
    // Cashier specific navigation
    if ($role === 'cashier') {
        return [
            'dashboard' => $nav_items['dashboard'],
            'transactions' => ['icon' => 'fas fa-receipt', 'label' => 'Transactions', 'url' => 'transactions.php'],
            'reports' => $nav_items['reports']
        ];
    }
    
    return $nav_items;
}
$navigation = getNavigationByRole($staff_role);

// Simple slug function
function createSlug($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text) ?: 'item';
}

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Initialize messages
$success_message = "";
$error_message = "";

// Ensure directories exist
function ensureDirectoriesExist($db) {
    $base_dirs = [
        'images/',
        'images/menu/',
        'images/menu/global/',
    ];
    
    // Create category subdirectories
    $cat_query = "SELECT slug FROM categories WHERE is_active = 1";
    $cat_result = $db->query($cat_query);
    if ($cat_result) {
        while ($cat_row = $cat_result->fetch_assoc()) {
            $base_dirs[] = 'images/menu/global/' . $cat_row['slug'] . '/';
        }
    }
    
    foreach ($base_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    // Create placeholder if it doesn't exist
    $placeholder_path = "images/placeholder.jpg";
    if (!file_exists($placeholder_path)) {
        // Create a simple SVG placeholder
        $svg_placeholder = '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg"><rect width="200" height="200" fill="#f8f9fa"/><text x="100" y="100" font-family="Arial, sans-serif" font-size="14" fill="#6c757d" text-anchor="middle" dy="0.35em">No Image</text></svg>';
        file_put_contents($placeholder_path, $svg_placeholder);
    }
}

// Call directory creation
ensureDirectoriesExist($db);

// FIXED: Proper image path function for images/menu/global/ structure
function getItemImage($item_id, $db) {
    // First, get the item's category slug and name
    $stmt = $db->prepare("
        SELECT i.id, i.name, c.slug as category_slug 
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id 
        WHERE i.id = ?
    ");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item_result = $stmt->get_result();
    
    if (!$item_result || !($item_data = $item_result->fetch_assoc())) {
        $stmt->close();
        return getPlaceholderImage();
    }
    
    $category_slug = $item_data['category_slug'] ?? 'general';
    $item_name = $item_data['name'] ?? 'item';
    $item_name_slug = createSlug($item_name);
    $stmt->close();
    
    // Priority 1: Check item_images table
    $stmt = $db->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY is_primary DESC LIMIT 1");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $image_path = $row['image_path'] ?? '';
        $stmt->close();
        
        if (!empty($image_path) && file_exists($image_path) && is_readable($image_path)) {
            return [
                'path' => $image_path,
                'type' => 'global',
                'source' => 'database'
            ];
        }
    }
    $stmt->close();
    
    // Priority 2: Look in images/menu/global/{category_slug}/ directory
    $base_dir = "images/menu/global/{$category_slug}/";
    
    if (is_dir($base_dir)) {
        $files = scandir($base_dir);
        $image_files = array_filter($files, function($file) {
            return preg_match('/\.(jpg|jpeg|png|webp|gif|jfif)$/i', $file);
        });
        
        // Try exact filename match
        foreach ($image_files as $image_file) {
            $filename_without_ext = pathinfo($image_file, PATHINFO_FILENAME);
            if ($filename_without_ext === $item_name_slug) {
                $found_path = $base_dir . $image_file;
                return [
                    'path' => $found_path,
                    'type' => 'global',
                    'source' => 'filesystem'
                ];
            }
        }
        
        // Try partial match
        foreach ($image_files as $image_file) {
            $filename_without_ext = pathinfo($image_file, PATHINFO_FILENAME);
            $normalized_filename = strtolower(str_replace(['-', '_'], '', $filename_without_ext));
            $normalized_item_name = strtolower(str_replace(['-', '_'], '', $item_name_slug));
            
            if (strpos($normalized_filename, $normalized_item_name) !== false || 
                strpos($normalized_item_name, $normalized_filename) !== false) {
                $found_path = $base_dir . $image_file;
                return [
                    'path' => $found_path,
                    'type' => 'global',
                    'source' => 'filesystem'
                ];
            }
        }
        
        // Return first image in category as fallback
        if (!empty($image_files)) {
            $first_image = reset($image_files);
            $found_path = $base_dir . $first_image;
            return [
                'path' => $found_path,
                'type' => 'global',
                'source' => 'category_fallback'
            ];
        }
    }
    
    // Final fallback
    return getPlaceholderImage();
}

function getPlaceholderImage() {
    return [
        'path' => "images/placeholder.jpg",
        'type' => 'placeholder',
        'source' => 'fallback'
    ];
}

// Handle item updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $item_id = filter_var($_POST['item_id'], FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = filter_var($_POST['category_id'], FILTER_VALIDATE_INT);
    $price = filter_var($_POST['price'], FILTER_VALIDATE_FLOAT);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $tags = trim($_POST['tags'] ?? '');
    
    if ($item_id && $name && $category_id && $price !== false && $price >= 0) {
        // Handle image upload - FIXED PATH
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Get category slug
            $cat_query = "SELECT slug FROM categories WHERE id = ?";
            $cat_stmt = $db->prepare($cat_query);
            $cat_stmt->bind_param("i", $category_id);
            $cat_stmt->execute();
            $cat_result = $cat_stmt->get_result();
            
            if ($cat_row = $cat_result->fetch_assoc()) {
                $category_slug = $cat_row['slug'];
                
                // Use correct directory structure
                $upload_dir = "images/menu/global/{$category_slug}/";
                
                // Create directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $file_name = createSlug($name) . '.' . $file_extension;
                $target_path = $upload_dir . $file_name;
                
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $max_size = 5 * 1024 * 1024;
                
                if (in_array($file_extension, $allowed_types) && 
                    $_FILES['image']['size'] <= $max_size) {
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        // Update item_images table
                        $image_path_for_db = $target_path;
                        
                        // Remove any existing image for this item
                        $delete_stmt = $db->prepare("DELETE FROM item_images WHERE item_id = ?");
                        $delete_stmt->bind_param("i", $item_id);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                        
                        // Insert new image record
                        $insert_stmt = $db->prepare("INSERT INTO item_images (item_id, company_code, image_path, is_primary) VALUES (?, NULL, ?, 1)");
                        $insert_stmt->bind_param("is", $item_id, $image_path_for_db);
                        $insert_stmt->execute();
                        $insert_stmt->close();
                        
                        $success_message = "Item updated successfully with new image.";
                    } else {
                        $error_message = "Failed to upload image file.";
                    }
                } else {
                    $error_message = "Invalid image file. Please check file type and size (max 5MB).";
                }
            }
            $cat_stmt->close();
        }
        
        // Update basic item info
        $query = "UPDATE items SET name = ?, description = ?, category_id = ?, price = ?, is_active = ?, tags = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ssidisi", $name, $description, $category_id, $price, $is_active, $tags, $item_id);
            if ($stmt->execute()) {
                if (empty($success_message)) {
                    $success_message = "Item updated successfully.";
                }
            } else {
                $error_message = "Error updating item: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $error_message = "Invalid input data. Please check all required fields.";
    }
}

// Handle item deletion (only for admin roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    // Only allow deletion for admin roles
    if (!in_array($staff_role, ['super_admin', 'admin'])) {
        $error_message = "You don't have permission to delete items.";
    } else {
        $delete_id = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
        if ($delete_id) {
            // Delete from item_images first
            $delete_images_stmt = $db->prepare("DELETE FROM item_images WHERE item_id = ?");
            $delete_images_stmt->bind_param("i", $delete_id);
            $delete_images_stmt->execute();
            $delete_images_stmt->close();
            
            // Then delete the item
            $query = "DELETE FROM items WHERE id = ?";
            $stmt = $db->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                if ($stmt->execute()) {
                    $success_message = "Item deleted successfully.";
                } else {
                    $error_message = "Error deleting item: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Fetch items with categories
$items = [];
$categories = [];

try {
    // Get categories using your table structure
    $category_query = "SELECT id, name, slug FROM categories WHERE is_active = 1 ORDER BY display_order, name";
    $category_result = $db->query($category_query);
    if ($category_result) {
        while ($row = $category_result->fetch_assoc()) {
            $categories[$row['id']] = [
                'name' => htmlspecialchars($row['name']),
                'slug' => htmlspecialchars($row['slug'])
            ];
        }
    }
    
    // Get all items with their categories
    $items_query = "
        SELECT 
            i.*, 
            c.name as category_name, 
            c.slug as category_slug
        FROM items i 
        LEFT JOIN categories c ON i.category_id = c.id 
        ORDER BY c.display_order, i.name
    ";
    
    $items_result = $db->query($items_query);
    
    if ($items_result) {
        while ($row = $items_result->fetch_assoc()) {
            $image_info = getItemImage($row['id'], $db);
            
            $items[] = [
                'id' => $row['id'],
                'name' => htmlspecialchars($row['name']),
                'description' => htmlspecialchars($row['description'] ?? ''),
                'category_id' => $row['category_id'],
                'category_name' => htmlspecialchars($row['category_name']),
                'category_slug' => htmlspecialchars($row['category_slug']),
                'price' => number_format((float)$row['price'], 2),
                'is_active' => (bool)$row['is_active'],
                'tags' => htmlspecialchars($row['tags'] ?? ''),
                'image_path' => $image_info['path'],
                'image_type' => $image_info['type'],
                'image_source' => $image_info['source']
            ];
        }
    }
    
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Debug image paths (comment out in production)
function debugImageStatus($items) {
    echo "<!-- Image Debug Info -->\n";
    echo "<!-- Total items: " . count($items) . " -->\n";
    
    foreach ($items as $item) {
        $exists = file_exists($item['image_path']) ? 'EXISTS' : 'MISSING';
        $readable = is_readable($item['image_path']) ? 'READABLE' : 'NOT READABLE';
        echo "<!-- Item {$item['id']}: {$item['name']} -->\n";
        echo "<!--   Path: {$item['image_path']} -->\n";
        echo "<!--   Status: $exists, $readable -->\n";
        echo "<!--   Source: {$item['image_source']} -->\n";
    }
}

// Uncomment the line below to see debug information
// debugImageStatus($items);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Item Management - <?= $company_name ?></title>
    <!-- Add to all pages in head section -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        }
        
        .item-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .item-image {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }
        
        .image-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            z-index: 10;
        }
        
        .global-badge { background: #2ecc71; }
        .placeholder-badge { background: #95a5a6; }
        
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        
        .role-badge {
            font-size: 0.7em;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .badge-super-admin { background: #dc3545; }
        .badge-admin { background: #fd7e14; }
        .badge-waiter { background: #20c997; }
        .badge-chef { background: #6f42c1; }
        .badge-cashier { background: #0dcaf0; }
        
        /* FIXED BUTTON STYLES */
        .card-content {
            display: flex;
            flex-direction: column;
            height: 100%;
            padding: 1rem;
        }
        
        .card-body-content {
            flex-grow: 1;
        }
        
        .button-container {
            display: flex;
            gap: 10px;
            margin-top: auto;
            width: 100%;
        }
        
        .btn-flex {
            flex: 1;
            min-height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        
        .delete-form {
            flex: 1;
            display: flex;
        }
        
        /* Ensure consistent card height */
        .card-body-content {
            min-height: 120px;
        }
        
        /* Hide add item button for non-admin roles */
        .add-item-btn {
            <?php if (!in_array($staff_role, ['super_admin', 'admin'])): ?>
                display: none;
            <?php endif; ?>
        }
    </style>
</head>
<body>
        <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-utensils"></i> <?= $company_name ?>
                <small class="ms-2 opacity-75">Menu Items</small>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($navigation as $nav_item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === $nav_item['url'] ? 'active' : '' ?>" 
                               href="<?= $nav_item['url'] ?>">
                                <i class="<?= $nav_item['icon'] ?> me-1"></i> <?= $nav_item['label'] ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text text-light me-3">
                        <i class="fas fa-user me-1"></i> <?= $staff_name ?>
                        <span class="badge role-badge badge-<?= str_replace('_', '-', $staff_role) ?>">
                            <?= ucfirst(str_replace('_', ' ', $staff_role)) ?>
                        </span>
                    </span>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button type="submit" name="logout" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
    <!-- Main Content -->
    <div class="container py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h2>Menu Items</h2>
                <p class="text-muted">Browse and manage menu items</p>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if (in_array($staff_role, ['super_admin', 'admin'])): ?>
                    <a href="add_item.php" class="btn btn-success add-item-btn">
                        <i class="fas fa-plus"></i> Add New Item
                    </a>
                <?php endif; ?>
                <a href="populate_images.php" class="btn btn-info">
                    <i class="fas fa-images"></i> Sync Images
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Items Grid -->
        <div class="row">
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $item): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="item-card h-100">
                            <div class="position-relative">
                                <span class="image-badge <?= $item['image_type'] ?>-badge">
                                    <i class="fas fa-<?= $item['image_type'] === 'global' ? 'globe' : 'image' ?>"></i>
                                    <?= ucfirst($item['image_type']) ?>
                                </span>
                                
                                <span class="status-badge badge bg-<?= $item['is_active'] ? 'success' : 'danger' ?>">
                                    <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                                
                                <img src="<?= $item['image_path'] ?>" 
                                     class="item-image" 
                                     alt="<?= $item['name'] ?>"
                                     onerror="this.onerror=null; this.src='images/placeholder.jpg'; console.log('Image failed to load: <?= $item['image_path'] ?>')">
                            </div>
                            
                            <div class="card-content">
                                <div class="card-body-content">
                                    <h5 class="mb-1"><?= $item['name'] ?></h5>
                                    <p class="text-muted small mb-2">
                                        <i class="fas fa-tag"></i> <?= $item['category_name'] ?>
                                    </p>
                                    <p class="h5 text-primary mb-2">ETB <?= $item['price'] ?></p>
                                    <p class="small text-muted mb-2">
                                        <?= !empty($item['description']) ? $item['description'] : 'No description available' ?>
                                    </p>
                                    
                                    <?php if (!empty($item['tags'])): ?>
                                        <div class="mb-2">
                                            <?php $tags = explode(',', $item['tags']); ?>
                                            <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                                <span class="badge bg-secondary me-1"><?= trim($tag) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($tags) > 3): ?>
                                                <span class="badge bg-light text-dark">+<?= count($tags) - 3 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- CORRECTED EDIT AND DELETE BUTTONS -->
                                <div class="button-container">
                                    <!-- Edit Button - Available for all roles that can view items -->
                                    <button class="btn btn-primary btn-sm btn-flex" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editModal"
                                            data-item-id="<?= $item['id'] ?>"
                                            data-item-name="<?= htmlspecialchars($item['name']) ?>"
                                            data-item-description="<?= htmlspecialchars($item['description']) ?>"
                                            data-item-category="<?= $item['category_id'] ?>"
                                            data-item-price="<?= $item['price'] ?>"
                                            data-item-active="<?= $item['is_active'] ? '1' : '0' ?>"
                                            data-item-tags="<?= htmlspecialchars($item['tags']) ?>"
                                            data-item-image="<?= $item['image_path'] ?>">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </button>
                                    
                                    <!-- Delete Button - Only for admin roles -->
                                    <?php if (in_array($staff_role, ['super_admin', 'admin'])): ?>
                                        <form method="POST" class="delete-form" 
                                              onsubmit="return confirm('Are you sure you want to delete \'<?= $item['name'] ?>\'? This action cannot be undone.')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="delete_id" value="<?= $item['id'] ?>">
                                            <button type="submit" name="delete_item" class="btn btn-danger btn-sm btn-flex w-100">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Empty space for non-admin users to maintain layout -->
                                        <div style="flex: 1;"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h4>No items found</h4>
                    <p class="text-muted">Get started by adding your first menu item</p>
                    <?php if (in_array($staff_role, ['super_admin', 'admin'])): ?>
                        <a href="add_item.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add First Item
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_item" value="1">
                    <input type="hidden" name="item_id" id="editItemId">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Item Name *</label>
                                    <input type="text" class="form-control" name="name" id="editName" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Category *</label>
                                    <select class="form-select" name="category_id" id="editCategory" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $id => $category): ?>
                                            <option value="<?= $id ?>"><?= $category['name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Price (ETB) *</label>
                                    <input type="number" class="form-control" name="price" id="editPrice" 
                                           step="0.01" min="0" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Tags</label>
                                    <input type="text" class="form-control" name="tags" id="editTags" 
                                           placeholder="popular, spicy, vegan (comma separated)">
                                </div>
                                
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" name="is_active" 
                                           id="editActive" value="1">
                                    <label class="form-check-label" for="editActive">Active Item</label>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" id="editDescription" 
                                              rows="4" placeholder="Item description..."></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Item Image</label>
                                    <input type="file" class="form-control" name="image" id="editImage" accept="image/*">
                                    <div class="form-text">
                                        Max 5MB. Supported: JPG, PNG, GIF, WEBP
                                        <br>Will save to: <code>images/menu/global/{category}/</code>
                                    </div>
                                </div>
                                
                                <div class="text-center border rounded p-3">
                                    <img id="currentImagePreview" src="" 
                                         class="img-thumbnail mb-2" 
                                         style="max-height: 150px; display: none;">
                                    <div id="noImage" class="text-muted">
                                        <i class="fas fa-image fa-2x mb-2 d-block"></i>
                                        No image set
                                    </div>
                                    <small id="imageInfo" class="text-muted d-block mt-2"></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit modal handling
        const editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                
                // Populate form fields
                document.getElementById('editItemId').value = button.getAttribute('data-item-id');
                document.getElementById('editName').value = button.getAttribute('data-item-name');
                document.getElementById('editDescription').value = button.getAttribute('data-item-description');
                document.getElementById('editCategory').value = button.getAttribute('data-item-category');
                document.getElementById('editPrice').value = button.getAttribute('data-item-price');
                document.getElementById('editTags').value = button.getAttribute('data-item-tags');
                document.getElementById('editActive').checked = button.getAttribute('data-item-active') === '1';
                
                // Handle image preview
                const imagePath = button.getAttribute('data-item-image');
                const imgPreview = document.getElementById('currentImagePreview');
                const noImage = document.getElementById('noImage');
                const imageInfo = document.getElementById('imageInfo');
                
                if (imagePath && imagePath !== 'images/placeholder.jpg') {
                    imgPreview.src = imagePath;
                    imgPreview.style.display = 'block';
                    noImage.style.display = 'none';
                    imageInfo.textContent = 'Current image: ' + imagePath.split('/').pop();
                } else {
                    imgPreview.style.display = 'none';
                    noImage.style.display = 'block';
                    imageInfo.textContent = 'No image currently set';
                }
            });
        }

        // Image preview for new uploads
        const imageInput = document.getElementById('editImage');
        if (imageInput) {
            imageInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const imgPreview = document.getElementById('currentImagePreview');
                        const noImage = document.getElementById('noImage');
                        const imageInfo = document.getElementById('imageInfo');
                        
                        imgPreview.src = e.target.result;
                        imgPreview.style.display = 'block';
                        noImage.style.display = 'none';
                        imageInfo.textContent = 'New image preview: ' + file.name;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    </script>
</body>
</html>
<?php
$db->close();
?>
