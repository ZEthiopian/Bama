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

// Redirect to login page if not logged in as staff
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Check if user has permission to manage categories (only admin roles)
if (!in_array($_SESSION['staff_role'], ['super_admin', 'admin'])) {
    header("Location: display.php");
    exit();
}

// Initialize CSRF token if not exists
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

// Handle category actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    // Add new category
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $slug = createSlug($name);
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($name)) {
            // Check for duplicate category name
            try {
                $check_query = "SELECT id FROM categories WHERE name = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bind_param("s", $name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "A category with the name '$name' already exists.";
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    $query = "INSERT INTO categories (name, description, slug, display_order, is_active) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param("sssii", $name, $description, $slug, $display_order, $is_active);
                        if ($stmt->execute()) {
                            $success_message = "Category added successfully.";
                        } else {
                            $error_message = "Error adding category: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                $error_message = "Error adding category: " . $e->getMessage();
            }
        } else {
            $error_message = "Category name is required.";
        }
    }
    
    // Update category
    if (isset($_POST['update_category'])) {
        $category_id = filter_var($_POST['category_id'], FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $slug = createSlug($name);
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($category_id && !empty($name)) {
            // Check for duplicate category name (excluding current category)
            try {
                $check_query = "SELECT id FROM categories WHERE name = ? AND id != ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bind_param("si", $name, $category_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "A category with the name '$name' already exists.";
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    $query = "UPDATE categories SET name = ?, description = ?, slug = ?, display_order = ?, is_active = ? WHERE id = ?";
                    $stmt = $db->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param("sssiii", $name, $description, $slug, $display_order, $is_active, $category_id);
                        if ($stmt->execute()) {
                            $success_message = "Category updated successfully.";
                        } else {
                            $error_message = "Error updating category: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                $error_message = "Error updating category: " . $e->getMessage();
            }
        } else {
            $error_message = "Invalid category data.";
        }
    }
    
    // Delete category
    if (isset($_POST['delete_category'])) {
        $delete_id = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
        
        if ($delete_id) {
            // Check if category has items
            $check_query = "SELECT COUNT(*) as item_count FROM items WHERE category_id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $delete_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $item_count = $result->fetch_assoc()['item_count'] ?? 0;
            $check_stmt->close();
            
            if ($item_count > 0) {
                $error_message = "Cannot delete category. There are {$item_count} items associated with this category.";
            } else {
                $query = "DELETE FROM categories WHERE id = ?";
                $stmt = $db->prepare($query);
                if ($stmt) {
                    $stmt->bind_param("i", $delete_id);
                    if ($stmt->execute()) {
                        $success_message = "Category deleted successfully.";
                    } else {
                        $error_message = "Error deleting category: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Function to create slug
function createSlug($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    
    if (empty($text)) {
        return 'category';
    }
    
    return $text;
}

// Fetch all categories
$categories = [];
try {
    // Fetch categories with item counts
    $query = "SELECT c.*, 
                     COUNT(i.id) as item_count 
              FROM categories c 
              LEFT JOIN items i ON c.id = i.category_id 
              GROUP BY c.id 
              ORDER BY c.display_order ASC, c.name ASC";
    
    $result = $db->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => $row['id'],
                'name' => htmlspecialchars($row['name'] ?? ''),
                'description' => htmlspecialchars($row['description'] ?? ''),
                'slug' => htmlspecialchars($row['slug'] ?? ''),
                'display_order' => $row['display_order'],
                'is_active' => (bool)($row['is_active'] ?? false),
                'item_count' => $row['item_count'] ?? 0
            ];
        }
        $result->free();
    }
    
} catch (Exception $e) {
    $error_message = "Error fetching categories: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Categories - <?= $company_name ?></title>
    <!-- Add to all pages in head section -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --light-bg: #f8f9fa;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
        }
        
        .category-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
            height: 100%;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
        }
        
        .item-count {
            font-size: 2rem;
            font-weight: bold;
            color: var(--secondary-color);
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-active { background-color: var(--success-color); color: white; }
        .status-inactive { background-color: #6c757d; color: white; }
        
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .category-description {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .role-badge {
            font-size: 0.7em;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .badge-super-admin { background: #dc3545; }
        .badge-admin { background: #fd7e14; }
        
        .duplicate-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-utensils"></i> <?= $company_name ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($navigation as $nav_item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $nav_item['label'] === 'Categories' ? 'active' : '' ?>" href="<?= $nav_item['url'] ?>">
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
        <!-- Welcome Section -->
        <div class="row align-items-center mb-4">
            <div class="col-md-6">
                <h2>Category Management</h2>
                <p class="text-muted">Manage your menu categories and organization</p>
            </div>
            <div class="col-md-6 text-md-end">
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus"></i> Add New Category
                </button>
            </div>
        </div>

        <!-- Messages Display -->
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

        <!-- Check for duplicates and show warning -->
        <?php
        $category_names_display = array_count_values(array_column($categories, 'name'));
        $duplicates = array_filter($category_names_display, function($count) {
            return $count > 1;
        });
        
        if (!empty($duplicates)): ?>
            <div class="duplicate-warning">
                <h5><i class="fas fa-exclamation-triangle text-warning"></i> Duplicate Categories Found</h5>
                <p class="mb-2">The following category names appear multiple times:</p>
                <ul class="mb-2">
                    <?php foreach ($duplicates as $name => $count): ?>
                        <li><strong><?= htmlspecialchars($name) ?></strong> (<?= $count ?> times)</li>
                    <?php endforeach; ?>
                </ul>
                <small class="text-muted">Please delete or rename duplicate categories to avoid confusion.</small>
            </div>
        <?php endif; ?>

        <!-- Categories Grid -->
        <div class="row">
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $category): ?>
                    <div class="col-md-4 mb-4">
                        <div class="category-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="mb-1"><?= $category['name'] ?></h5>
                                    <p class="text-muted small mb-0">Slug: <?= $category['slug'] ?></p>
                                </div>
                                <span class="status-badge status-<?= $category['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($category['description'])): ?>
                                <div class="category-description">
                                    <?= $category['description'] ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <span class="item-count"><?= $category['item_count'] ?></span>
                                    <span class="text-muted">Items</span>
                                </div>
                                <div>
                                    <span class="text-muted">Order: <?= $category['display_order'] ?></span>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary btn-sm" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editCategoryModal"
                                        data-category-id="<?= $category['id'] ?>"
                                        data-category-name="<?= htmlspecialchars($category['name']) ?>"
                                        data-category-description="<?= htmlspecialchars($category['description']) ?>"
                                        data-category-order="<?= $category['display_order'] ?>"
                                        data-category-active="<?= $category['is_active'] ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="delete_id" value="<?= $category['id'] ?>">
                                    <button type="submit" name="delete_category" 
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('Are you sure you want to delete category: <?= addslashes($category['name']) ?>?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                    <h4>No categories found</h4>
                    <p class="text-muted">Get started by adding your first category</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus"></i> Add First Category
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_category" value="1">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="categoryName" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="categoryName" name="name" required>
                            <div class="form-text">Category names must be unique</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="categoryDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="categoryDescription" name="description" rows="3" placeholder="Optional description for this category"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="displayOrder" class="form-label">Display Order</label>
                            <input type="number" class="form-control" id="displayOrder" name="display_order" value="0">
                            <div class="form-text">Lower numbers appear first</div>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="isActive" name="is_active" value="1" checked>
                            <label class="form-check-label" for="isActive">Active Category</label>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_category" value="1">
                    <input type="hidden" name="category_id" id="editCategoryId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editCategoryName" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="editCategoryName" name="name" required>
                            <div class="form-text">Category names must be unique</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editCategoryDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editCategoryDescription" name="description" rows="3" placeholder="Optional description for this category"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editDisplayOrder" class="form-label">Display Order</label>
                            <input type="number" class="form-control" id="editDisplayOrder" name="display_order" value="0">
                            <div class="form-text">Lower numbers appear first</div>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="editIsActive" name="is_active" value="1">
                            <label class="form-check-label" for="editIsActive">Active Category</label>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize edit modal
        const editCategoryModal = document.getElementById('editCategoryModal');
        editCategoryModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const modal = this;
            
            // Populate form fields
            modal.querySelector('#editCategoryId').value = button.getAttribute('data-category-id');
            modal.querySelector('#editCategoryName').value = button.getAttribute('data-category-name');
            modal.querySelector('#editCategoryDescription').value = button.getAttribute('data-category-description');
            modal.querySelector('#editDisplayOrder').value = button.getAttribute('data-category-order');
            modal.querySelector('#editIsActive').checked = button.getAttribute('data-category-active') === '1';
        });

        // Auto-focus on category name field when add modal opens
        document.getElementById('addCategoryModal').addEventListener('shown.bs.modal', function () {
            document.getElementById('categoryName').focus();
        });
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}
