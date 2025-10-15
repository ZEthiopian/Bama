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

// Handle order status update (for chefs and admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $transaction_id = filter_var($_POST['transaction_id'], FILTER_VALIDATE_INT);
    $new_status = $_POST['new_status'] ?? '';
    
    // Check if user has permission to update status
    $allowed_status_updates = [];
    if (in_array($staff_role, ['super_admin', 'admin', 'chef'])) {
        $allowed_status_updates = ['confirmed', 'completed', 'cancelled'];
    }
    
    if ($transaction_id && in_array($new_status, $allowed_status_updates)) {
        try {
            $update_query = "UPDATE transactions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param("si", $new_status, $transaction_id);
            
            if ($update_stmt->execute()) {
                $success_message = "Order status updated to " . ucfirst($new_status) . " successfully!";
            } else {
                $error_message = "Error updating order status: " . $update_stmt->error;
            }
            $update_stmt->close();
        } catch (Exception $e) {
            $error_message = "Error updating order status: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid status update request or insufficient permissions.";
    }
}

// Handle order deletion (only for pending and failed orders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token validation failed");
    }
    
    $transaction_id = filter_var($_POST['transaction_id'], FILTER_VALIDATE_INT);
    
    if ($transaction_id) {
        try {
            // Start transaction
            $db->begin_transaction();
            
            // Check if order is pending or failed
            $check_query = "SELECT status FROM transactions WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $transaction_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $order_status = $check_result->fetch_assoc()['status'] ?? '';
            $check_stmt->close();
            
            if ($order_status === 'pending' || $order_status === 'failed') {
                // Delete order items first
                $delete_items_query = "DELETE FROM order_items WHERE transaction_id = ?";
                $delete_items_stmt = $db->prepare($delete_items_query);
                $delete_items_stmt->bind_param("i", $transaction_id);
                $delete_items_stmt->execute();
                $delete_items_stmt->close();
                
                // Delete transaction
                $delete_transaction_query = "DELETE FROM transactions WHERE id = ?";
                $delete_transaction_stmt = $db->prepare($delete_transaction_query);
                $delete_transaction_stmt->bind_param("i", $transaction_id);
                
                if ($delete_transaction_stmt->execute()) {
                    $db->commit();
                    $success_message = "Order deleted successfully.";
                } else {
                    $db->rollback();
                    $error_message = "Error deleting order: " . $delete_transaction_stmt->error;
                }
                $delete_transaction_stmt->close();
            } else {
                $error_message = "Only pending and failed orders can be deleted.";
            }
        } catch (Exception $e) {
            $db->rollback();
            $error_message = "Error deleting order: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid order ID.";
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($date_filter)) {
    $where_conditions[] = "DATE(t.created_at) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

if (!empty($search_query)) {
    $where_conditions[] = "(t.order_id LIKE ? OR t.custom_id LIKE ? OR t.msisdn LIKE ? OR rt.table_number LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $types .= 'ssss';
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch orders with filtering
$orders = [];
try {
    $query = "SELECT t.*, 
                     rt.table_number,
                     COUNT(oi.id) as item_count,
                     SUM(oi.total) as items_total
              FROM transactions t 
              LEFT JOIN order_items oi ON t.id = oi.transaction_id 
              LEFT JOIN restaurant_tables rt ON t.table_id = rt.id 
              $where_clause 
              GROUP BY t.id 
              ORDER BY t.created_at DESC 
              LIMIT 100";
    
    $stmt = $db->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = [
                'id' => $row['id'],
                'order_id' => $row['order_id'],
                'transaction_id' => $row['id'],
                'table_id' => $row['table_id'],
                'table_number' => $row['table_number'] ?? $row['custom_id'],
                'custom_id' => htmlspecialchars($row['custom_id'] ?? ''),
                'amount' => number_format((float)$row['amount'], 2),
                'total_amount' => number_format((float)$row['total_amount'], 2),
                'status' => $row['status'],
                'payment_method' => $row['payment_via'] ?? 'Unknown',
                'msisdn' => htmlspecialchars($row['msisdn'] ?? ''),
                'created_at' => date('M j, Y g:i A', strtotime($row['created_at'])),
                'updated_at' => !empty($row['updated_at']) ? date('M j, Y g:i A', strtotime($row['updated_at'])) : '',
                'item_count' => $row['item_count'] ?? 0,
                'items_total' => number_format((float)($row['items_total'] ?? 0), 2)
            ];
        }
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $error_message = "Error fetching orders: " . $e->getMessage();
}

// Get order items for each order
foreach ($orders as &$order) {
    try {
        $items_query = "SELECT oi.*, i.name as item_name 
                       FROM order_items oi 
                       LEFT JOIN items i ON oi.item_id = i.id 
                       WHERE oi.transaction_id = ?";
        $items_stmt = $db->prepare($items_query);
        $items_stmt->bind_param("i", $order['id']);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        $order['items'] = [];
        while ($item_row = $items_result->fetch_assoc()) {
            $order['items'][] = [
                'id' => $item_row['id'],
                'item_name' => htmlspecialchars($item_row['item_name'] ?? $item_row['item_name']),
                'quantity' => $item_row['quantity'],
                'price' => number_format((float)$item_row['price'], 2),
                'total' => number_format((float)$item_row['total'], 2),
                'table_number' => $item_row['table_number']
            ];
        }
        $items_stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching order items: " . $e->getMessage());
    }
}

// Get order statistics for filter tabs
$order_stats = [];
try {
    $statuses = ['all', 'pending', 'confirmed', 'completed', 'failed', 'cancelled'];
    
    foreach ($statuses as $status) {
        if ($status === 'all') {
            $query = "SELECT COUNT(*) as count FROM transactions";
            $result = $db->query($query);
        } else {
            $query = "SELECT COUNT(*) as count FROM transactions WHERE status = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        }
        
        $order_stats[$status] = $result->fetch_assoc()['count'] ?? 0;
    }
    
} catch (Exception $e) {
    error_log("Error fetching order statistics: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Orders - <?= $company_name ?></title>
    
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
            --light-bg: #f8f9fa;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%);
        }
        
        .order-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }
        
        .order-card:hover {
            transform: translateY(-5px);
        }
        
        .order-card.pending { border-left-color: var(--warning-color); }
        .order-card.confirmed { border-left-color: var(--info-color); }
        .order-card.completed { border-left-color: var(--success-color); }
        .order-card.failed { border-left-color: var(--accent-color); }
        .order-card.cancelled { border-left-color: #6c757d; }
        
        .order-header {
            border-bottom: 2px solid #f8f9fa;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        
        .order-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        
        .status-pending { background-color: var(--warning-color); color: white; }
        .status-confirmed { background-color: var(--info-color); color: white; }
        .status-completed { background-color: var(--success-color); color: white; }
        .status-failed { background-color: var(--accent-color); color: white; }
        .status-cancelled { background-color: #6c757d; color: white; }
        
        .order-items {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .filter-tabs .nav-link {
            border: none;
            padding: 10px 20px;
            margin-right: 5px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .filter-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .filter-tabs .badge {
            margin-left: 5px;
        }
        
        .action-btn {
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: scale(1.05);
        }
        
        .deletable-status {
            border: 2px dashed var(--accent-color);
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
        
        .status-update-form {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-utensils"></i> <?= $company_name ?>
                <small class="ms-2 opacity-75">Orders</small>
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
                <h2><i class="fas fa-shopping-cart"></i> Order Management</h2>
                <p class="text-muted">View and manage customer orders and their status</p>
                
                <!-- Role-specific instructions -->
                <?php if (in_array($staff_role, ['super_admin', 'admin', 'chef'])): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Kitchen Staff:</strong> You can update order status. |
                        <strong>Deletable Orders:</strong> <span class="badge bg-warning">pending</span> and <span class="badge bg-danger">failed</span>
                    </div>
                <?php elseif ($staff_role === 'waiter'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Waiter View:</strong> You can view orders but cannot modify status.
                    </div>
                <?php elseif ($staff_role === 'cashier'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Cashier View:</strong> Focus on completed orders for payment processing.
                    </div>
                <?php endif; ?>
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

        <!-- Filter Tabs -->
        <div class="card mb-4">
            <div class="card-body">
                <ul class="nav nav-tabs filter-tabs" id="orderTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $status_filter === 'all' ? 'active' : '' ?>" 
                           href="?status=all">
                            <i class="fas fa-list"></i> All Orders
                            <span class="badge bg-secondary"><?= $order_stats['all'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $status_filter === 'pending' ? 'active' : '' ?>" 
                           href="?status=pending">
                            <i class="fas fa-clock"></i> Pending
                            <span class="badge bg-warning"><?= $order_stats['pending'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $status_filter === 'confirmed' ? 'active' : '' ?>" 
                           href="?status=confirmed">
                            <i class="fas fa-check-circle"></i> Confirmed
                            <span class="badge bg-info"><?= $order_stats['confirmed'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $status_filter === 'completed' ? 'active' : '' ?>" 
                           href="?status=completed">
                            <i class="fas fa-check-double"></i> Completed
                            <span class="badge bg-success"><?= $order_stats['completed'] ?></span>
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $status_filter === 'failed' ? 'active' : '' ?>" 
                           href="?status=failed">
                            <i class="fas fa-times-circle"></i> Failed
                            <span class="badge bg-danger"><?= $order_stats['failed'] ?></span>
                        </a>
                    </li>
                </ul>

                <!-- Search and Filter Form -->
                <form method="GET" class="row g-3 mt-3">
                    <input type="hidden" name="status" value="<?= $status_filter ?>">
                    
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search" placeholder="Search by Order ID, Table, or Phone..." 
                               value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                    
                    <div class="col-md-4">
                        <input type="date" class="form-control" name="date" value="<?= $date_filter ?>">
                    </div>
                    
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="orders.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders List -->
        <div class="row">
            <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                    <div class="col-lg-6 mb-4">
                        <div class="order-card <?= $order['status'] ?> <?= ($order['status'] === 'pending' || $order['status'] === 'failed') ? 'deletable-status' : '' ?>">
                            <!-- Order Header -->
                            <div class="order-header">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="mb-1">Order #<?= $order['order_id'] ?></h5>
                                        <small class="text-muted">Transaction #<?= $order['id'] ?></small>
                                    </div>
                                    <span class="order-status status-<?= $order['status'] ?>">
                                        <?= ucfirst($order['status']) ?>
                                        <?php if ($order['status'] === 'pending' || $order['status'] === 'failed'): ?>
                                            <br><small>(Can be deleted)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">
                                            <i class="fas fa-table"></i> Table: <?= $order['table_number'] ?>
                                        </small>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> <?= $order['msisdn'] ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">
                                            <i class="fas fa-clock"></i> <?= $order['created_at'] ?>
                                        </small>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">
                                            <i class="fas fa-credit-card"></i> <?= $order['payment_method'] ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <?php if (!empty($order['updated_at'])): ?>
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <small class="text-muted">
                                                <i class="fas fa-sync"></i> Last Updated: <?= $order['updated_at'] ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Order Items -->
                            <div class="order-items mb-3">
                                <h6 class="mb-2">Order Items (<?= $order['item_count'] ?>):</h6>
                                <?php if (!empty($order['items'])): ?>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <div>
                                                <span class="fw-medium"><?= $item['quantity'] ?>x</span>
                                                <?= $item['item_name'] ?>
                                            </div>
                                            <div>
                                                ETB <?= $item['total'] ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No items found</p>
                                <?php endif; ?>
                            </div>

                            <!-- Order Total -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Total Amount:</h5>
                                <h5 class="mb-0 text-primary">ETB <?= $order['total_amount'] ?></h5>
                            </div>

                            <!-- Order Actions -->
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-info action-btn flex-grow-1" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#orderDetailsModal"
                                        data-order-details="<?= htmlspecialchars(json_encode($order)) ?>">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </button>
                                
                                <?php if ($order['status'] === 'pending' || $order['status'] === 'failed'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="transaction_id" value="<?= $order['id'] ?>">
                                        <button type="submit" name="delete_order" 
                                                class="btn btn-danger action-btn"
                                                onclick="return confirm('Are you sure you want to delete this order? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- Status Update Form (for chefs and admins) -->
                            <?php if (in_array($staff_role, ['super_admin', 'admin', 'chef']) && in_array($order['status'], ['pending', 'confirmed'])): ?>
                                <div class="status-update-form">
                                    <h6 class="mb-2">Update Order Status:</h6>
                                    <form method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="transaction_id" value="<?= $order['id'] ?>">
                                        
                                        <select name="new_status" class="form-select flex-grow-1" required>
                                            <option value="">Select new status...</option>
                                            <?php if ($order['status'] === 'pending'): ?>
                                                <option value="confirmed">Confirm Order</option>
                                                <option value="cancelled">Cancel Order</option>
                                            <?php elseif ($order['status'] === 'confirmed'): ?>
                                                <option value="completed">Mark as Completed</option>
                                                <option value="cancelled">Cancel Order</option>
                                            <?php endif; ?>
                                        </select>
                                        
                                        <button type="submit" name="update_status" class="btn btn-success action-btn">
                                            <i class="fas fa-save"></i> Update
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                    <h4>No orders found</h4>
                    <p class="text-muted">
                        <?= $status_filter !== 'all' ? "No {$status_filter} orders found." : "No orders have been placed yet." ?>
                    </p>
                    <?php if ($status_filter !== 'all'): ?>
                        <a href="?status=all" class="btn btn-primary">
                            <i class="fas fa-list"></i> View All Orders
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination (if needed) -->
        <?php if (!empty($orders) && count($orders) >= 100): ?>
            <nav aria-label="Order pagination">
                <ul class="pagination justify-content-center">
                    <li class="page-item disabled">
                        <a class="page-link" href="#" tabindex="-1">Previous</a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                        <a class="page-link" href="#">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Order details modal
        const orderDetailsModal = document.getElementById('orderDetailsModal');
        orderDetailsModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const orderDetails = JSON.parse(button.getAttribute('data-order-details'));
            const modalContent = document.getElementById('orderDetailsContent');
            
            let itemsHtml = '';
            if (orderDetails.items && orderDetails.items.length > 0) {
                itemsHtml = orderDetails.items.map(item => `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <strong>${item.quantity}x</strong> ${item.item_name}
                        </div>
                        <div>
                            <div>ETB ${item.price} each</div>
                            <div class="fw-bold">ETB ${item.total} total</div>
                        </div>
                    </div>
                `).join('');
            } else {
                itemsHtml = '<p class="text-muted">No items found</p>';
            }
            
            modalContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Order Information</h6>
                        <p><strong>Order #:</strong> ${orderDetails.order_id}</p>
                        <p><strong>Transaction #:</strong> ${orderDetails.id}</p>
                        <p><strong>Status:</strong> <span class="badge bg-${getStatusColor(orderDetails.status)}">${orderDetails.status}</span></p>
                        <p><strong>Date:</strong> ${orderDetails.created_at}</p>
                        <p><strong>Payment Method:</strong> ${orderDetails.payment_method}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Customer Information</h6>
                        <p><strong>Table:</strong> ${orderDetails.table_number}</p>
                        <p><strong>Phone:</strong> ${orderDetails.msisdn}</p>
                        ${orderDetails.updated_at ? `<p><strong>Last Updated:</strong> ${orderDetails.updated_at}</p>` : ''}
                    </div>
                </div>
                
                <hr>
                
                <h6>Order Items (${orderDetails.item_count})</h6>
                <div class="order-items mb-3" style="max-height: 300px; overflow-y: auto;">
                    ${itemsHtml}
                </div>
                
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Total Amount:</h5>
                    <h4 class="text-primary">ETB ${orderDetails.total_amount}</h4>
                </div>
                
                ${(orderDetails.status === 'pending' || orderDetails.status === 'failed') ? `
                    <hr>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> This order can be deleted from the main orders list.
                    </div>
                ` : ''}
            `;
        });

        function getStatusColor(status) {
            const colors = {
                'pending': 'warning',
                'confirmed': 'info',
                'completed': 'success',
                'failed': 'danger',
                'cancelled': 'secondary'
            };
            return colors[status] || 'secondary';
        }
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}
