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

// Redirect to login if not logged in as staff (using your staff session)
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Check if user has admin privileges
if (!in_array($_SESSION['staff_role'] ?? '', ['super_admin', 'admin'])) {
    header("Location: dashboard.php");
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

// Get role-based navigation
function getNavigationByRole($role) {
    $nav_items = [
        'dashboard' => ['icon' => 'fas fa-home', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
        'items' => ['icon' => 'fas fa-cubes', 'label' => 'Items', 'url' => 'display.php'],
        'categories' => ['icon' => 'fas fa-tags', 'label' => 'Categories', 'url' => 'categories.php'],
        'staff' => ['icon' => 'fas fa-users', 'label' => 'Staff', 'url' => 'staff.php'],
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

// Initialize messages
$success_message = "";
$error_message = "";

// Check if comp_users table exists and has correct structure
$table_check = $db->query("SHOW TABLES LIKE 'comp_users'");
if ($table_check->num_rows == 0) {
    // Create comp_users table if it doesn't exist (updated structure)
    $create_table_sql = "
        CREATE TABLE comp_users (
            id INT(11) PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'admin', 'waiter', 'chef', 'cashier') NOT NULL DEFAULT 'waiter',
            display_name VARCHAR(100),
            is_active TINYINT(1) DEFAULT 1,
            created_by INT(11),
            api_token VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ";
    
    if (!$db->query($create_table_sql)) {
        $error_message = "Error creating comp_users table: " . $db->error;
    } else {
        // Create default admin user if no users exist
        $check_users = $db->query("SELECT COUNT(*) as count FROM comp_users");
        if ($check_users->fetch_assoc()['count'] == 0) {
            $default_password = password_hash('admin123', PASSWORD_DEFAULT);
            $insert_admin = "INSERT INTO comp_users (username, password, role, display_name, created_by) 
                            VALUES ('admin', '$default_password', 'super_admin', 'System Administrator', 1)";
            $db->query($insert_admin);
        }
        $success_message = "Staff users table created successfully!";
    }
}

// Handle staff user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $role = $_POST['role'] ?? '';
    $display_name = trim($_POST['display_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($role) || empty($display_name) || empty($username) || empty($password)) {
        $error_message = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error_message = "Username can only contain letters, numbers, and underscores.";
    } else {
        try {
            // Check if username already exists
            $check_stmt = $db->prepare("SELECT id FROM comp_users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Username already exists. Please choose a different username.";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert staff user into comp_users table
                $insert_stmt = $db->prepare("
                    INSERT INTO comp_users (username, password, role, display_name, created_by) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insert_stmt->bind_param("ssssi", $username, $hashed_password, $role, $display_name, $staff_id);
                
                if ($insert_stmt->execute()) {
                    $success_message = "Staff user created successfully! Username: <strong>{$username}</strong>";
                    // Clear form
                    $_POST = [];
                } else {
                    $error_message = "Error creating staff user: " . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
            
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Handle user status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $user_id_toggle = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
    $new_status = filter_var($_POST['new_status'], FILTER_VALIDATE_INT);
    
    if ($user_id_toggle && $user_id_toggle != $staff_id) { // Prevent deactivating self
        $update_stmt = $db->prepare("UPDATE comp_users SET is_active = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $new_status, $user_id_toggle);
        
        if ($update_stmt->execute()) {
            $status_text = $new_status ? 'activated' : 'deactivated';
            $success_message = "Staff user {$status_text} successfully!";
        } else {
            $error_message = "Error updating staff user status: " . $update_stmt->error;
        }
        $update_stmt->close();
    } elseif ($user_id_toggle == $staff_id) {
        $error_message = "You cannot deactivate your own account.";
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $user_id_reset = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
    $new_password = $_POST['new_password'] ?? '';
    
    if ($user_id_reset && !empty($new_password)) {
        if (strlen($new_password) < 6) {
            $error_message = "Password must be at least 6 characters long.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $db->prepare("UPDATE comp_users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user_id_reset);
            
            if ($update_stmt->execute()) {
                $success_message = "Password reset successfully!";
            } else {
                $error_message = "Error resetting password: " . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
}

// Handle user deletion (only for super_admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $user_id_delete = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
    
    if ($user_id_delete && $user_id_delete != $staff_id && $staff_role === 'super_admin') {
        $delete_stmt = $db->prepare("DELETE FROM comp_users WHERE id = ?");
        $delete_stmt->bind_param("i", $user_id_delete);
        
        if ($delete_stmt->execute()) {
            $success_message = "Staff user deleted successfully!";
        } else {
            $error_message = "Error deleting staff user: " . $delete_stmt->error;
        }
        $delete_stmt->close();
    } elseif ($user_id_delete == $staff_id) {
        $error_message = "You cannot delete your own account.";
    } elseif ($staff_role !== 'super_admin') {
        $error_message = "Only super administrators can delete user accounts.";
    }
}

// Fetch all staff users (EXCLUDE super_admin users from staff list)
$staff_users = [];
try {
    $users_query = "
        SELECT u.*, 
               CASE WHEN u.id = ? THEN 1 ELSE 0 END as is_current_user
        FROM comp_users u 
        WHERE u.role != 'super_admin'  -- EXCLUDE super_admin users
        ORDER BY u.role, u.username
    ";
    $users_stmt = $db->prepare($users_query);
    $users_stmt->bind_param("i", $staff_id);
    $users_stmt->execute();
    $users_result = $users_stmt->get_result();
    
    if ($users_result) {
        while ($row = $users_result->fetch_assoc()) {
            $staff_users[] = [
                'id' => $row['id'],
                'username' => htmlspecialchars($row['username']),
                'role' => htmlspecialchars($row['role']),
                'display_name' => htmlspecialchars($row['display_name']),
                'is_active' => (bool)$row['is_active'],
                'is_current_user' => (bool)$row['is_current_user'],
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by']
            ];
        }
    }
    $users_stmt->close();
} catch (Exception $e) {
    $error_message = "Error fetching staff users: " . $e->getMessage();
}

// Get user counts by role (EXCLUDE super_admin from counts)
$user_counts = [
    'admin' => 0,
    'waiter' => 0,
    'chef' => 0,
    'cashier' => 0,
    'total' => count($staff_users),
    'active' => 0
];

foreach ($staff_users as $user) {
    if (isset($user_counts[$user['role']])) {
        $user_counts[$user['role']]++;
    }
    if ($user['is_active']) {
        $user_counts['active']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Staff Management - <?= $company_name ?></title>
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
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid;
        }
        
        .stats-total { border-left-color: #6c757d; }
        .stats-active { border-left-color: #28a745; }
        .stats-admin { border-left-color: #fd7e14; }
        .stats-waiter { border-left-color: #17a2b8; }
        .stats-chef { border-left-color: #28a745; }
        .stats-cashier { border-left-color: #ffc107; }
        
        .role-badge {
            font-size: 12px;
            padding: 4px 8px;
        }
        
        .admin-badge { background: #fd7e14; color: white; }
        .waiter-badge { background: #17a2b8; color: white; }
        .chef-badge { background: #28a745; color: white; }
        .cashier-badge { background: #ffc107; color: black; }
        
        .user-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .current-user {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-left: 4px solid #2196f3;
        }
        
        .role-info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .admin-note {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
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
                        <span class="badge bg-light text-dark ms-1">
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
                <h2><i class="fas fa-users-cog"></i> Staff Management</h2>
                <p class="text-muted">Manage restaurant staff accounts and permissions</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Admin Note -->
        <?php if ($staff_role === 'super_admin'): ?>
            <div class="admin-note">
                <div class="d-flex align-items-center">
                    <i class="fas fa-shield-alt text-warning me-3 fa-lg"></i>
                    <div>
                        <strong>Super Administrator Access</strong> 
                        <span class="text-muted">| You have full system access. Super admin accounts are not shown in staff lists.</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-total">
                    <h3 class="text-dark"><?= $user_counts['total'] ?></h3>
                    <p class="mb-0 text-muted">Total Staff</p>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-active">
                    <h3 class="text-success"><?= $user_counts['active'] ?></h3>
                    <p class="mb-0 text-muted">Active</p>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-admin">
                    <h3 class="text-warning"><?= $user_counts['admin'] ?></h3>
                    <p class="mb-0 text-muted">Admins</p>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-waiter">
                    <h3 class="text-info"><?= $user_counts['waiter'] ?></h3>
                    <p class="mb-0 text-muted">Waiters</p>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-chef">
                    <h3 class="text-success"><?= $user_counts['chef'] ?></h3>
                    <p class="mb-0 text-muted">Chefs</p>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="stats-card stats-cashier">
                    <h3 class="text-warning"><?= $user_counts['cashier'] ?></h3>
                    <p class="mb-0 text-muted">Cashiers</p>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Create User Form -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-plus"></i> Create New Staff Member
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Role *</label>
                                <select class="form-select" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin">Administrator</option>
                                    <option value="waiter">Waiter</option>
                                    <option value="chef">Chef</option>
                                    <option value="cashier">Cashier</option>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle"></i> 
                                    Super admin accounts can only be created by existing super admins
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" 
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                       placeholder="Enter username (letters, numbers, underscores)" 
                                       pattern="[a-zA-Z0-9_]+" required>
                                <div class="form-text">
                                    Username must contain only letters, numbers, and underscores
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Display Name *</label>
                                <input type="text" class="form-control" name="display_name" 
                                       value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                                       placeholder="Enter full name (e.g., John Smith)" required>
                                <div class="form-text">
                                    This will be shown throughout the system
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" class="form-control" name="password" 
                                       placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" class="form-control" name="confirm_password" 
                                       placeholder="Re-enter password" required>
                            </div>
                            
                            <button type="submit" name="create_user" class="btn btn-success w-100">
                                <i class="fas fa-user-plus"></i> Create Staff Account
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Role Information -->
                <div class="role-info-card">
                    <h6><i class="fas fa-info-circle me-2"></i> Staff Role Permissions</h6>
                    <div class="mb-2">
                        <span class="badge admin-badge me-2">Administrator</span>
                        <small class="text-muted">Full restaurant management access</small>
                    </div>
                    <div class="mb-2">
                        <span class="badge waiter-badge me-2">Waiter</span>
                        <small class="text-muted">Take orders, manage tables, view menu</small>
                    </div>
                    <div class="mb-2">
                        <span class="badge chef-badge me-2">Chef</span>
                        <small class="text-muted">Kitchen orders, view menu items</small>
                    </div>
                    <div class="mb-2">
                        <span class="badge cashier-badge me-2">Cashier</span>
                        <small class="text-muted">Process payments, view transactions and reports</small>
                    </div>
                    <?php if ($staff_role === 'super_admin'): ?>
                        <hr>
                        <div class="mb-2">
                            <span class="badge bg-danger me-2">Super Admin</span>
                            <small class="text-muted">Full system access (not shown in staff lists)</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Staff Users List -->
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Restaurant Staff (<?= count($staff_users) ?>)
                        </h5>
                        <span class="badge bg-primary"><?= $user_counts['active'] ?> Active</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($staff_users)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($staff_users as $user): ?>
                                            <tr class="<?= $user['is_current_user'] ? 'current-user' : '' ?>">
                                                <td>
                                                    <div>
                                                        <strong><?= $user['username'] ?></strong>
                                                        <?php if ($user['is_current_user']): ?>
                                                            <span class="badge bg-info ms-1">You</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted"><?= $user['display_name'] ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge role-badge <?= $user['role'] ?>-badge">
                                                        <?= ucfirst($user['role']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $user['is_active'] ? 'success' : 'danger' ?>">
                                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?= date('M j, Y', strtotime($user['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <!-- Toggle Status -->
                                                        <?php if (!$user['is_current_user'] && ($staff_role === 'super_admin' || ($staff_role === 'admin' && $user['role'] !== 'super_admin'))): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $user['is_active'] ? '0' : '1' ?>">
                                                                <button type="submit" name="toggle_status" 
                                                                        class="btn btn-<?= $user['is_active'] ? 'warning' : 'success' ?> btn-sm"
                                                                        title="<?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                                                        onclick="return confirm('Are you sure you want to <?= $user['is_active'] ? 'deactivate' : 'activate' ?> <?= $user['display_name'] ?>?')">
                                                                    <i class="fas fa-<?= $user['is_active'] ? 'pause' : 'play' ?>"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Reset Password -->
                                                        <?php if ($staff_role === 'super_admin' || ($staff_role === 'admin' && $user['role'] !== 'super_admin')): ?>
                                                            <button type="button" class="btn btn-info btn-sm" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#resetPasswordModal"
                                                                    data-user-id="<?= $user['id'] ?>"
                                                                    data-username="<?= $user['username'] ?>"
                                                                    title="Reset Password">
                                                                <i class="fas fa-key"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Delete User (Super Admin only) -->
                                                        <?php if ($staff_role === 'super_admin' && !$user['is_current_user']): ?>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                                <button type="submit" name="delete_user" 
                                                                        class="btn btn-danger btn-sm"
                                                                        title="Delete User"
                                                                        onclick="return confirm('WARNING: This will permanently delete <?= $user['display_name'] ?>. This action cannot be undone!')">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h5>No Staff Members Yet</h5>
                                <p class="text-muted">Create your first staff account using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="user_id" id="resetUserId">
                    <input type="hidden" name="reset_password" value="1">
                    
                    <div class="modal-body">
                        <p>Reset password for: <strong id="resetUsername"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label">New Password *</label>
                            <input type="password" class="form-control" name="new_password" 
                                   placeholder="Enter new password" required minlength="6">
                            <div class="form-text">Minimum 6 characters</div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Reset Password Modal
        const resetPasswordModal = document.getElementById('resetPasswordModal');
        if (resetPasswordModal) {
            resetPasswordModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('resetUserId').value = button.getAttribute('data-user-id');
                document.getElementById('resetUsername').textContent = button.getAttribute('data-username');
            });
        }

        // Auto-generate username based on role and display name
        document.querySelector('select[name="role"]').addEventListener('change', function() {
            const role = this.value;
            const displayNameInput = document.querySelector('input[name="display_name"]');
            const usernameInput = document.querySelector('input[name="username"]');
            
            if (role && displayNameInput.value && !usernameInput.value) {
                // Generate username from first name + role
                const firstName = displayNameInput.value.split(' ')[0].toLowerCase();
                const cleanFirstName = firstName.replace(/[^a-z0-9]/g, '');
                if (cleanFirstName) {
                    usernameInput.value = cleanFirstName + '_' + role;
                }
            }
        });

        // Clear username when display name changes if it matches the pattern
        document.querySelector('input[name="display_name"]').addEventListener('input', function() {
            const usernameInput = document.querySelector('input[name="username"]');
            const roleSelect = document.querySelector('select[name="role"]');
            
            if (usernameInput.value.includes('_') && roleSelect.value) {
                const currentRole = usernameInput.value.split('_').pop();
                if (currentRole === roleSelect.value) {
                    const firstName = this.value.split(' ')[0].toLowerCase();
                    const cleanFirstName = firstName.replace(/[^a-z0-9]/g, '');
                    if (cleanFirstName) {
                        usernameInput.value = cleanFirstName + '_' + roleSelect.value;
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php
$db->close();
?>
