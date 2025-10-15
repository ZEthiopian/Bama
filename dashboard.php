<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

// Redirect to login page if not logged in as staff
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

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

// Initialize CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Safely assign session variables from staff session
$staff_id = $_SESSION['staff_id'] ?? '';
$staff_username = htmlspecialchars($_SESSION['staff_username'] ?? '', ENT_QUOTES, 'UTF-8');
$staff_name = htmlspecialchars($_SESSION['staff_name'] ?? 'Staff User', ENT_QUOTES, 'UTF-8');
$staff_role = htmlspecialchars($_SESSION['staff_role'] ?? 'staff', ENT_QUOTES, 'UTF-8');
$company_name = SITE_NAME; // Use site name from config

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

// Helper function to safely format numbers
function safe_number_format($value, $decimals = 0) {
    if ($value === null || $value === '') {
        return '0';
    }
    
    // Convert to float if it's a string
    $float_value = (float)$value;
    return number_format($float_value, $decimals);
}

// Helper function to safely get integer values
function safe_int_value($value) {
    if ($value === null || $value === '') {
        return 0;
    }
    return (int)$value;
}

// Fetch dashboard statistics
$stats = [
    'total_items' => 0,
    'active_items' => 0,
    'total_categories' => 0,
    'active_categories' => 0,
    'total_orders' => 0,
    'pending_orders' => 0,
    'today_orders' => 0,
    'today_revenue' => 0,
    'total_transactions' => 0,
    'total_revenue' => 0,
    'recent_orders' => [],
    'low_stock_items' => 0
];

try {
    // Total items - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM items";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_items'] = safe_int_value($row['count'] ?? 0);
    }

    // Active items - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM items WHERE is_active = 1";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['active_items'] = safe_int_value($row['count'] ?? 0);
    }

    // Total categories - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM categories";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_categories'] = safe_int_value($row['count'] ?? 0);
    }

    // Active categories - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM categories WHERE is_active = 1";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['active_categories'] = safe_int_value($row['count'] ?? 0);
    }

    // Total transactions (using transactions table) - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM transactions WHERE status IN ('confirmed', 'completed')";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_transactions'] = safe_int_value($row['count'] ?? 0);
    }

    // Total revenue (sum of completed transactions) - FIXED: Ensure we get float
    $query = "SELECT COALESCE(SUM(total_amount), 0) as total FROM transactions WHERE status IN ('confirmed', 'completed')";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_revenue'] = (float)($row['total'] ?? 0);
    }

    // Pending transactions - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM transactions WHERE status = 'pending'";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['pending_orders'] = safe_int_value($row['count'] ?? 0);
    }

    // Today's transactions - FIXED: Ensure proper data types
    $query = "SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue 
              FROM transactions 
              WHERE DATE(created_at) = CURDATE() AND status IN ('confirmed', 'completed')";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['today_orders'] = safe_int_value($row['count'] ?? 0);
        $stats['today_revenue'] = (float)($row['revenue'] ?? 0);
    }

    // Low stock items (if you have inventory management) - FIXED: Ensure we get integer
    $query = "SELECT COUNT(*) as count FROM items WHERE stock_quantity > 0 AND stock_quantity <= stock_alert_level";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['low_stock_items'] = safe_int_value($row['count'] ?? 0);
    }

    // Recent transactions (last 10) - FIXED: Ensure proper data types
    $query = "SELECT t.*, rt.table_number 
              FROM transactions t 
              LEFT JOIN restaurant_tables rt ON t.table_id = rt.id 
              ORDER BY t.created_at DESC 
              LIMIT 10";
    $result = $db->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats['recent_orders'][] = [
                'id' => safe_int_value($row['id']),
                'order_id' => $row['order_id'] ?? 'N/A',
                'amount' => safe_number_format((float)($row['amount'] ?? 0), 2),
                'total_amount' => safe_number_format((float)($row['total_amount'] ?? 0), 2),
                'status' => $row['status'] ?? 'unknown',
                'table_number' => $row['table_number'] ?? 'Takeaway',
                'created_at' => date('M j, Y g:i A', strtotime($row['created_at'] ?? 'now')),
                'payment_via' => $row['payment_via'] ?? 'Cash',
                'customer_phone' => $row['msisdn'] ?? 'N/A'
            ];
        }
    }

} catch (Exception $e) {
    error_log("Error fetching dashboard stats: " . $e->getMessage());
}

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

// Get quick actions based on role
function getQuickActions($role) {
    $actions = [];
    
    if (in_array($role, ['super_admin', 'admin', 'waiter'])) {
        $actions[] = [
            'url' => 'display.php',
            'icon' => 'fas fa-cubes',
            'label' => 'Manage Menu Items',
            'color' => 'primary'
        ];
    }
    
    if (in_array($role, ['super_admin', 'admin'])) {
        $actions[] = [
            'url' => 'categories.php',
            'icon' => 'fas fa-tags',
            'label' => 'Manage Categories',
            'color' => 'success'
        ];
        $actions[] = [
            'url' => 'staff.php',
            'icon' => 'fas fa-users',
            'label' => 'Manage Staff',
            'color' => 'info'
        ];
    }
    
    if (in_array($role, ['super_admin', 'admin', 'waiter'])) {
        $actions[] = [
            'url' => 'tables.php',
            'icon' => 'fas fa-table',
            'label' => 'Manage Tables',
            'color' => 'secondary'
        ];
    }
    
    if (in_array($role, ['super_admin', 'admin', 'cashier'])) {
        $actions[] = [
            'url' => 'transactions.php',
            'icon' => 'fas fa-receipt',
            'label' => 'View Transactions',
            'color' => 'warning'
        ];
    }
    
    if ($role === 'chef') {
        $actions[] = [
            'url' => 'kitchen_order.php',
            'icon' => 'fas fa-utensils',
            'label' => 'Kitchen Orders',
            'color' => 'danger'
        ];
    }
    
    if ($role === 'waiter') {
        $actions[] = [
            'url' => 'waiter_menu.php',
            'icon' => 'fas fa-plus-circle',
            'label' => 'Take New Order',
            'color' => 'success'
        ];
    }
    
    if (in_array($role, ['super_admin', 'admin', 'cashier'])) {
        $actions[] = [
            'url' => 'reports.php',
            'icon' => 'fas fa-chart-bar',
            'label' => 'Generate Reports',
            'color' => 'purple'
        ];
    }
    
    return $actions;
}

$quick_actions = getQuickActions($staff_role);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard - <?= $company_name ?></title>
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
            --warning-color: #f39c12;
            --info-color: #17a2b8;
            --purple-color: #6f42c1;
            --light-bg: #f8f9fa;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
            border-left: 4px solid var(--secondary-color);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .stat-subtext {
            font-size: 0.8rem;
            color: #28a745;
            font-weight: 500;
        }
        
        .revenue-card {
            border-left-color: #28a745 !important;
        }
        
        .revenue-card .stat-number {
            color: #28a745;
        }
        
        .recent-orders {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            height: 100%;
        }
        
        .order-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-pending { background-color: var(--warning-color); color: white; }
        .status-confirmed { background-color: var(--success-color); color: white; }
        .status-completed { background-color: var(--success-color); color: white; }
        .status-failed { background-color: var(--accent-color); color: white; }
        .status-cancelled { background-color: #6c757d; color: white; }
        
        .quick-actions {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            height: 100%;
        }
        
        .action-btn {
            display: block;
            padding: 15px;
            margin-bottom: 10px;
            border: none;
            border-radius: 8px;
            text-align: left;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            width: 100%;
        }
        
        .action-btn:hover {
            transform: translateX(5px);
            color: white;
            text-decoration: none;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-primary { background: linear-gradient(135deg, #3498db, #2980b9); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #229954); }
        .btn-info { background: linear-gradient(135deg, #17a2b8, #138496); }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .btn-purple { background: linear-gradient(135deg, #6f42c1, #5a2d91); }
        .btn-secondary { background: linear-gradient(135deg, #6c757d, #545b62); }
        .btn-danger { background: linear-gradient(135deg, #dc3545, #c82333); }
        
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
        
        .system-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .alert-low-stock {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 15px;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .stat-icon {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
        <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-utensils"></i> <?= $company_name ?>
                <small class="ms-2 opacity-75">Dashboard</small>
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
        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1"><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h2>
                        <p class="text-muted mb-0">Welcome back, <?= $staff_name ?>! Here's your restaurant overview.</p>
                    </div>
                    <div class="text-end">
                        <small class="text-muted"><?= date('l, F j, Y') ?></small>
                        <br>
                        <small class="text-muted"><?= date('g:i A') ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <?php if ($stats['low_stock_items'] > 0 && in_array($staff_role, ['super_admin', 'admin', 'chef'])): ?>
            <div class="alert-low-stock">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle text-warning me-3 fa-lg"></i>
                    <div>
                        <strong>Low Stock Alert!</strong> 
                        You have <?= safe_number_format($stats['low_stock_items']) ?> item(s) running low on stock.
                        <a href="display.php?filter=low_stock" class="alert-link ms-2">View Details</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row">
            <!-- First Row -->
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-primary">
                        <i class="fas fa-cube"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['total_items']) ?></div>
                    <div class="stat-label">Total Menu Items</div>
                    <?php if ($stats['active_items'] > 0): ?>
                        <div class="stat-subtext"><?= safe_number_format($stats['active_items']) ?> active</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-info">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['total_categories']) ?></div>
                    <div class="stat-label">Categories</div>
                    <?php if ($stats['active_categories'] > 0): ?>
                        <div class="stat-subtext"><?= safe_number_format($stats['active_categories']) ?> active</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center revenue-card">
                    <div class="stat-icon text-success">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number">ETB <?= safe_number_format($stats['total_revenue'], 2) ?></div>
                    <div class="stat-label">Total Revenue</div>
                    <?php if ($stats['today_revenue'] > 0): ?>
                        <div class="stat-subtext">ETB <?= safe_number_format($stats['today_revenue'], 2) ?> today</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-warning">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['total_transactions']) ?></div>
                    <div class="stat-label">Total Orders</div>
                    <?php if ($stats['today_orders'] > 0): ?>
                        <div class="stat-subtext"><?= safe_number_format($stats['today_orders']) ?> today</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Second Row Stats -->
        <div class="row">
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-danger">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['pending_orders']) ?></div>
                    <div class="stat-label">Pending Orders</div>
                    <div class="stat-subtext">Need attention</div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-success">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['today_orders']) ?></div>
                    <div class="stat-label">Today's Orders</div>
                    <div class="stat-subtext">ETB <?= safe_number_format($stats['today_revenue'], 2) ?> revenue</div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-purple">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['active_categories']) ?></div>
                    <div class="stat-label">Active Categories</div>
                    <div class="stat-subtext">
                        <?= $stats['total_categories'] > 0 ? 
                            round(($stats['active_categories'] / $stats['total_categories']) * 100) : 0 ?>% of total
                    </div>
                </div>
            </div>
            
            <?php if (in_array($staff_role, ['super_admin', 'admin', 'chef'])): ?>
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-number"><?= safe_number_format($stats['low_stock_items']) ?></div>
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-subtext">Need restocking</div>
                </div>
            </div>
            <?php else: ?>
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card text-center">
                    <div class="stat-icon text-info">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-number"><?= ucfirst($staff_role) ?></div>
                    <div class="stat-label">Your Role</div>
                    <div class="stat-subtext">Welcome to dashboard</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row mt-4">
            <!-- Recent Orders -->
            <div class="col-lg-8">
                <div class="recent-orders">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">
                            <i class="fas fa-history"></i> Recent Transactions
                            <?php if ($stats['pending_orders'] > 0): ?>
                                <span class="badge bg-danger ms-2"><?= safe_number_format($stats['pending_orders']) ?> pending</span>
                            <?php endif; ?>
                        </h4>
                        <a href="transactions.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    
                    <?php if (!empty($stats['recent_orders'])): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Table/Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['recent_orders'] as $order): ?>
                                        <tr>
                                            <td>
                                                <strong>#<?= htmlspecialchars($order['order_id']) ?></strong>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($order['table_number']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($order['customer_phone']) ?></small>
                                            </td>
                                            <td>
                                                <strong>ETB <?= $order['total_amount'] ?></strong>
                                            </td>
                                            <td>
                                                <span class="order-status status-<?= $order['status'] ?>">
                                                    <?= ucfirst($order['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($order['payment_via']) ?></small>
                                            </td>
                                            <td>
                                                <small><?= $order['created_at'] ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No recent transactions found</p>
                            <?php if (in_array($staff_role, ['waiter', 'admin', 'super_admin'])): ?>
                                <a href="waiter_menu.php" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Create First Order
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions & System Info -->
            <div class="col-lg-4">
                <div class="quick-actions">
                    <h4 class="mb-4"><i class="fas fa-bolt"></i> Quick Actions</h4>
                    
                    <?php foreach ($quick_actions as $action): ?>
                        <a href="<?= $action['url'] ?>" class="action-btn btn-<?= $action['color'] ?>">
                            <i class="<?= $action['icon'] ?> me-2"></i>
                            <?= $action['label'] ?>
                        </a>
                    <?php endforeach; ?>
                    
                    <div class="system-info">
                        <h6><i class="fas fa-info-circle me-2"></i> System Information</h6>
                        <div class="row small text-muted">
                            <div class="col-6">
                                <strong>Role:</strong><br>
                                <strong>User:</strong><br>
                                <strong>Server Time:</strong>
                            </div>
                            <div class="col-6 text-end">
                                <?= ucfirst(str_replace('_', ' ', $staff_role)) ?><br>
                                <?= $staff_name ?><br>
                                <?= date('H:i:s') ?>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="small">
                            <strong>PHP Version:</strong> <?= phpversion() ?><br>
                            <strong>Database:</strong> Connected<br>
                            <strong>Last Login:</strong> <?= date('M j, g:i A') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh dashboard every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);
        
        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Add click animation to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}
?>
