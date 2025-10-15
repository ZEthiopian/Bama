<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

// Database connection with error handling
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// Enhanced session validation and role-based access control
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Check if user has permission to access reports
$allowed_roles = ['super_admin', 'admin', 'cashier'];
if (!in_array($_SESSION['staff_role'] ?? '', $allowed_roles)) {
    header("Location: dashboard.php");
    exit();
}

// Initialize CSRF token with enhanced security
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
        'tables' => ['icon' => 'fas fa-table', 'label' => 'Tables', 'url' => 'tables.php'],
        'staff' => ['icon' => 'fas fa-users', 'label' => 'Staff', 'url' => 'staff.php'],
        'orders' => ['icon' => 'fas fa-shopping-cart', 'label' => 'Orders', 'url' => 'orders.php'],
        'reports' => ['icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'url' => 'reports.php'],
    ];
    
    // Super admin and admin have full access
    if (in_array($role, ['super_admin', 'admin'])) {
        return $nav_items;
    }
    
    // Cashier specific navigation
    if ($role === 'cashier') {
        return [
            'dashboard' => $nav_items['dashboard'],
            'transactions' => ['icon' => 'fas fa-receipt', 'label' => 'Transactions', 'url' => 'transactions.php'],
            'reports' => $nav_items['reports']
        ];
    }
    
    return [];
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

// Enhanced sales data function with better error handling and performance
function getSalesData($db, $start_date, $end_date, $report_type = 'sales') {
    $sales_data = [
        'items' => [],
        'grand_total_quantity' => 0,
        'grand_total_amount' => 0,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_items' => 0,
        'total_orders' => 0
    ];
    
    try {
        // Base query for sales data
        $query = "
            SELECT 
                oi.id,
                oi.item_id,
                i.name as item_name,
                c.name as category_name,
                oi.quantity,
                oi.price as unit_price,
                oi.total as subtotal,
                DATE(t.created_at) as order_date,
                t.created_at,
                t.order_id,
                t.payment_via,
                rt.table_number,
                t.msisdn
            FROM order_items oi
            JOIN transactions t ON oi.transaction_id = t.id
            JOIN items i ON oi.item_id = i.id
            LEFT JOIN categories c ON i.category_id = c.id
            LEFT JOIN restaurant_tables rt ON t.table_id = rt.id
            WHERE t.status IN ('completed', 'confirmed')
            AND DATE(t.created_at) BETWEEN ? AND ?
        ";
        
        // Add ordering based on report type
        switch($report_type) {
            case 'items':
                $query .= " ORDER BY i.name, oi.quantity DESC";
                break;
            case 'categories':
                $query .= " ORDER BY c.name, i.name";
                break;
            default:
                $query .= " ORDER BY t.created_at DESC, i.name";
        }
        
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Failed to prepare query: " . $db->error);
        }
        
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items_summary = [];
        $order_count = [];
        
        while ($row = $result->fetch_assoc()) {
            $item_key = $row['item_name'] . '_' . $row['unit_price'];
            $order_id = $row['order_id'];
            
            // Track unique orders
            if (!isset($order_count[$order_id])) {
                $order_count[$order_id] = true;
            }
            
            if (!isset($items_summary[$item_key])) {
                $items_summary[$item_key] = [
                    'item_name' => $row['item_name'],
                    'category_name' => $row['category_name'] ?? 'Uncategorized',
                    'unit_price' => (float)$row['unit_price'],
                    'total_quantity' => 0,
                    'total_subtotal' => 0,
                    'first_appearance' => $row['order_date'],
                    'last_appearance' => $row['order_date'],
                    'orders' => []
                ];
            }
            
            $items_summary[$item_key]['total_quantity'] += (int)$row['quantity'];
            $items_summary[$item_key]['total_subtotal'] += (float)$row['subtotal'];
            $items_summary[$item_key]['last_appearance'] = max(
                $items_summary[$item_key]['last_appearance'], 
                $row['order_date']
            );
            
            $items_summary[$item_key]['orders'][] = [
                'order_id' => $row['order_id'],
                'quantity' => (int)$row['quantity'],
                'unit_price' => (float)$row['unit_price'],
                'subtotal' => (float)$row['subtotal'],
                'order_date' => $row['created_at'],
                'payment_method' => $row['payment_via'],
                'table_number' => $row['table_number'],
                'customer_phone' => $row['msisdn']
            ];
        }
        
        $stmt->close();
        
        // Calculate totals
        $grand_total_quantity = 0;
        $grand_total_amount = 0;
        
        foreach ($items_summary as $item) {
            $grand_total_quantity += $item['total_quantity'];
            $grand_total_amount += $item['total_subtotal'];
        }
        
        $sales_data = [
            'items' => $items_summary,
            'grand_total_quantity' => $grand_total_quantity,
            'grand_total_amount' => $grand_total_amount,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_items' => count($items_summary),
            'total_orders' => count($order_count)
        ];
        
    } catch (Exception $e) {
        error_log("Error fetching sales data: " . $e->getMessage());
        throw $e;
    }
    
    return $sales_data;
}

// New function for payment method analysis
function getPaymentAnalysis($db, $start_date, $end_date) {
    $payment_data = [];
    
    try {
        $query = "
            SELECT 
                payment_via,
                COUNT(*) as transaction_count,
                SUM(total_amount) as total_amount
            FROM transactions 
            WHERE status IN ('completed', 'confirmed')
            AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY payment_via
            ORDER BY total_amount DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $payment_data[] = [
                'method' => $row['payment_via'] ?? 'Unknown',
                'transaction_count' => (int)$row['transaction_count'],
                'total_amount' => (float)$row['total_amount']
            ];
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error fetching payment analysis: " . $e->getMessage());
    }
    
    return $payment_data;
}

// Enhanced PDF generation with multiple report types
function generateSalesReport($db, $start_date, $end_date, $report_type, $company_name, $staff_name) {
    // Get sales data based on report type
    $sales_data = getSalesData($db, $start_date, $end_date, $report_type);
    $payment_data = getPaymentAnalysis($db, $start_date, $end_date);
    
    // Generate HTML content for PDF
    $html = generatePDFHTML($sales_data, $payment_data, $company_name, $start_date, $end_date, $report_type, $staff_name);
    
    // Output the HTML content
    echo $html;
    exit();
}

// Enhanced PDF HTML generation
function generatePDFHTML($sales_data, $payment_data, $company_name, $start_date, $end_date, $report_type, $staff_name) {
    $report_title = getReportTitle($report_type);
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . $report_title . ' - ' . htmlspecialchars($company_name) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                color: #333;
                line-height: 1.4;
                font-size: 12px;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #333;
                padding-bottom: 20px;
            }
            .company-name {
                font-size: 24px;
                font-weight: bold;
                margin-bottom: 10px;
                color: #2c3e50;
            }
            .report-title {
                font-size: 20px;
                font-weight: bold;
                margin: 20px 0;
                color: #2c3e50;
            }
            .report-period {
                font-size: 14px;
                margin-bottom: 10px;
                color: #666;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-size: 11px;
            }
            .table th {
                background-color: #f8f9fa;
                border: 1px solid #ddd;
                padding: 10px;
                text-align: left;
                font-weight: bold;
                color: #2c3e50;
            }
            .table td {
                border: 1px solid #ddd;
                padding: 10px;
            }
            .table tr:nth-child(even) {
                background-color: #f8f9fa;
            }
            .total-row {
                background-color: #e9ecef !important;
                font-weight: bold;
                color: #2c3e50;
            }
            .summary {
                margin-top: 30px;
                padding: 20px;
                background-color: #f8f9fa;
                border-radius: 5px;
                border-left: 4px solid #3498db;
            }
            .summary-item {
                margin-bottom: 8px;
                font-weight: 500;
            }
            .payment-summary {
                margin-top: 20px;
                padding: 15px;
                background-color: #fff3cd;
                border-radius: 5px;
                border-left: 4px solid #ffc107;
            }
            .text-right {
                text-align: right;
            }
            .text-center {
                text-align: center;
            }
            .section-title {
                font-size: 16px;
                font-weight: bold;
                margin: 25px 0 15px 0;
                color: #2c3e50;
                border-bottom: 1px solid #ddd;
                padding-bottom: 8px;
            }
            @media print {
                body { 
                    margin: 15px;
                    font-size: 10px;
                }
                .no-print { 
                    display: none; 
                }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="company-name">' . htmlspecialchars($company_name) . '</div>
            <div class="report-title">' . $report_title . '</div>
            <div class="report-period">Period: ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date)) . '</div>
            <div class="report-period">Generated: ' . date('M j, Y g:i A') . ' by ' . htmlspecialchars($staff_name) . '</div>
        </div>

        <!-- Sales Summary -->
        <div class="section-title">Sales Summary</div>
        <table class="table">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th>Category</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>';
            
            foreach ($sales_data['items'] as $item_key => $item) {
                $item_name = $item['item_name'];
                if (hasMultiplePrices($sales_data['items'], $item['item_name'])) {
                    $item_name .= ' *';
                }
                
                $html .= '
                <tr>
                    <td>' . htmlspecialchars($item_name) . '</td>
                    <td>' . htmlspecialchars($item['category_name']) . '</td>
                    <td class="text-right">ETB ' . number_format($item['unit_price'], 2) . '</td>
                    <td class="text-right">' . $item['total_quantity'] . '</td>
                    <td class="text-right">ETB ' . number_format($item['total_subtotal'], 2) . '</td>
                </tr>';
            }
            
            $html .= '
                <tr class="total-row">
                    <td colspan="3"><strong>Grand Total</strong></td>
                    <td class="text-right"><strong>' . $sales_data['grand_total_quantity'] . '</strong></td>
                    <td class="text-right"><strong>ETB ' . number_format($sales_data['grand_total_amount'], 2) . '</strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Payment Summary -->
        <div class="section-title">Payment Method Analysis</div>';
        
        if (!empty($payment_data)) {
            $html .= '<table class="table">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th class="text-right">Transactions</th>
                        <th class="text-right">Total Amount</th>
                        <th class="text-right">Percentage</th>
                    </tr>
                </thead>
                <tbody>';
                
                $total_all_payments = array_sum(array_column($payment_data, 'total_amount'));
                
                foreach ($payment_data as $payment) {
                    $percentage = $total_all_payments > 0 ? ($payment['total_amount'] / $total_all_payments) * 100 : 0;
                    
                    $html .= '
                    <tr>
                        <td>' . htmlspecialchars($payment['method']) . '</td>
                        <td class="text-right">' . $payment['transaction_count'] . '</td>
                        <td class="text-right">ETB ' . number_format($payment['total_amount'], 2) . '</td>
                        <td class="text-right">' . number_format($percentage, 1) . '%</td>
                    </tr>';
                }
                
                $html .= '</tbody>
            </table>';
        } else {
            $html .= '<p>No payment data available for the selected period.</p>';
        }

        $html .= '
        <!-- Summary Section -->
        <div class="summary">
            <div class="section-title">Report Summary</div>
            <div class="summary-item"><strong>Total Orders:</strong> ' . $sales_data['total_orders'] . '</div>
            <div class="summary-item"><strong>Total Items Sold:</strong> ' . $sales_data['grand_total_quantity'] . '</div>
            <div class="summary-item"><strong>Total Sales Amount:</strong> ETB ' . number_format($sales_data['grand_total_amount'], 2) . '</div>
            <div class="summary-item"><strong>Number of Unique Items:</strong> ' . $sales_data['total_items'] . '</div>
            <div class="summary-item"><strong>Average Order Value:</strong> ETB ' . number_format($sales_data['total_orders'] > 0 ? $sales_data['grand_total_amount'] / $sales_data['total_orders'] : 0, 2) . '</div>
        </div>

        <div class="no-print" style="margin-top: 30px; padding: 20px; background-color: #f8f9fa; border-radius: 5px; text-align: center;">
            <p><strong>Instructions:</strong> Use your browser\'s print function (Ctrl+P) and select "Save as PDF" as destination.</p>
            <button onclick="window.print()" style="padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 5px;">Print / Save as PDF</button>
            <button onclick="window.close()" style="padding: 10px 20px; background-color: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 5px;">Close Window</button>
        </div>

        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 1000);
            };
        </script>
    </body>
    </html>';
    
    return $html;
}

// Helper functions
function getReportTitle($report_type) {
    $titles = [
        'sales' => 'SALES SUMMARY REPORT',
        'detailed' => 'DETAILED SALES REPORT',
        'items' => 'ITEM PERFORMANCE REPORT',
        'categories' => 'CATEGORY SALES REPORT'
    ];
    return $titles[$report_type] ?? 'SALES REPORT';
}

function hasMultiplePrices($items, $item_name) {
    $prices = [];
    foreach ($items as $item_key => $item) {
        if ($item['item_name'] === $item_name) {
            $prices[] = $item['unit_price'];
        }
    }
    return count(array_unique($prices)) > 1;
}

// Handle PDF generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Security token validation failed.";
    } else {
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $report_type = $_POST['report_type'] ?? 'sales';
        
        // Validate dates
        if (empty($start_date) || empty($end_date)) {
            $error_message = "Please select both start and end dates.";
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error_message = "Start date cannot be after end date.";
        } elseif (strtotime($start_date) > time() || strtotime($end_date) > time()) {
            $error_message = "Date cannot be in the future.";
        } else {
            // Generate PDF report
            try {
                generateSalesReport($db, $start_date, $end_date, $report_type, $company_name, $staff_name);
            } catch (Exception $e) {
                $error_message = "Error generating report: " . $e->getMessage();
            }
        }
    }
}

// Get default date ranges
$default_start_date = date('Y-m-d', strtotime('-30 days'));
$default_end_date = date('Y-m-d');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');
$year_start = date('Y-01-01');

// Fetch enhanced report statistics with proper type casting
$report_stats = [
    'total_sales' => 0.0,
    'total_orders' => 0,
    'total_items_sold' => 0,
    'average_order' => 0.0,
    'today_sales' => 0.0,
    'yesterday_sales' => 0.0
];

try {
    // Total sales amount (last 30 days)
    $query = "
        SELECT COALESCE(SUM(total_amount), 0) as total_sales 
        FROM transactions 
        WHERE status IN ('completed', 'confirmed')
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $report_stats['total_sales'] = (float)($row['total_sales'] ?? 0);
    }

    // Total orders (last 30 days)
    $query = "
        SELECT COUNT(*) as total_orders 
        FROM transactions 
        WHERE status IN ('completed', 'confirmed')
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $report_stats['total_orders'] = (int)($row['total_orders'] ?? 0);
    }

    // Total items sold (last 30 days)
    $query = "
        SELECT COALESCE(SUM(oi.quantity), 0) as total_items 
        FROM order_items oi 
        JOIN transactions t ON oi.transaction_id = t.id 
        WHERE t.status IN ('completed', 'confirmed')
        AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $report_stats['total_items_sold'] = (int)($row['total_items'] ?? 0);
    }

    // Today's sales
    $query = "
        SELECT COALESCE(SUM(total_amount), 0) as today_sales 
        FROM transactions 
        WHERE status IN ('completed', 'confirmed')
        AND DATE(created_at) = CURDATE()
    ";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $report_stats['today_sales'] = (float)($row['today_sales'] ?? 0);
    }

    // Yesterday's sales
    $query = "
        SELECT COALESCE(SUM(total_amount), 0) as yesterday_sales 
        FROM transactions 
        WHERE status IN ('completed', 'confirmed')
        AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ";
    $result = $db->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $report_stats['yesterday_sales'] = (float)($row['yesterday_sales'] ?? 0);
    }

    // Average order value
    if ($report_stats['total_orders'] > 0) {
        $report_stats['average_order'] = $report_stats['total_sales'] / $report_stats['total_orders'];
    }

} catch (Exception $e) {
    error_log("Error fetching report statistics: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reports & Analytics - <?= $company_name ?></title>
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
        
        .stat-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card.sales { border-left-color: var(--success-color); }
        .stat-card.orders { border-left-color: var(--info-color); }
        .stat-card.items { border-left-color: var(--warning-color); }
        .stat-card.average { border-left-color: var(--secondary-color); }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
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
        
        .report-generator {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 20px;
        }
        
        .quick-periods {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .period-btn {
            width: 100%;
            margin-bottom: 10px;
            text-align: left;
        }
        
        .feature-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .role-badge {
            font-size: 0.7em;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .badge-super-admin { background: #dc3545; }
        .badge-admin { background: #fd7e14; }
        .badge-cashier { background: #0dcaf0; }
        
        .btn-generate {
            background: linear-gradient(135deg, var(--success-color) 0%, #229954 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.4);
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-bar"></i> <?= $company_name ?>
                <small class="ms-2 opacity-75">Analytics</small>
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
            <div class="col-12">
                <h2><i class="fas fa-analytics"></i> Reports & Analytics</h2>
                <p class="text-muted">Comprehensive sales reporting and business intelligence</p>
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

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card sales">
                    <div class="stat-icon text-success">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-number">ETB <?= number_format($report_stats['total_sales'], 2) ?></div>
                    <div class="stat-label">30-Day Revenue</div>
                    <div class="stat-subtext">
                        Today: ETB <?= number_format($report_stats['today_sales'], 2) ?>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card orders">
                    <div class="stat-icon text-info">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-number"><?= number_format($report_stats['total_orders']) ?></div>
                    <div class="stat-label">Total Orders (30 days)</div>
                    <div class="stat-subtext">
                        <?= $report_stats['total_orders'] > 0 ? number_format($report_stats['total_orders'] / 30, 1) : '0' ?> orders/day
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card items">
                    <div class="stat-icon text-warning">
                        <i class="fas fa-cube"></i>
                    </div>
                    <div class="stat-number"><?= number_format($report_stats['total_items_sold']) ?></div>
                    <div class="stat-label">Items Sold (30 days)</div>
                    <div class="stat-subtext">
                        <?= $report_stats['total_orders'] > 0 ? number_format($report_stats['total_items_sold'] / $report_stats['total_orders'], 1) : '0' ?> items/order
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="stat-card average">
                    <div class="stat-icon text-primary">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number">ETB <?= number_format($report_stats['average_order'], 2) ?></div>
                    <div class="stat-label">Average Order Value</div>
                    <div class="stat-subtext">
                        Total efficiency metric
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Report Generator -->
            <div class="col-lg-8">
                <div class="report-generator">
                    <h4 class="mb-4">
                        <i class="fas fa-file-pdf text-danger"></i> Generate Custom Report
                    </h4>
                    
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div class="col-md-6">
                            <label for="start_date" class="form-label">Start Date *</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?= $default_start_date ?>" max="<?= $today ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="end_date" class="form-label">End Date *</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?= $default_end_date ?>" max="<?= $today ?>" required>
                        </div>
                        
                        <div class="col-12">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type" required>
                                <option value="sales" selected>Sales Summary Report</option>
                                <option value="detailed">Detailed Sales Report</option>
                                <option value="items">Item Performance Report</option>
                                <option value="categories">Category Sales Report</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-text text-muted">
                                <i class="fas fa-lightbulb"></i> 
                                Items with price changes during the selected period will be listed separately with asterisk (*)
                            </div>
                        </div>
                        
                        <div class="col-12 text-center mt-4">
                            <button type="submit" name="generate_report" class="btn btn-generate btn-lg">
                                <i class="fas fa-download"></i> Generate PDF Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Periods -->
                <div class="quick-periods">
                    <h5 class="mb-3"><i class="fas fa-bolt"></i> Quick Period Selection</h5>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-primary period-btn" data-start="<?= $today ?>" data-end="<?= $today ?>">
                                <i class="fas fa-calendar-day"></i> Today
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-secondary period-btn" data-start="<?= $yesterday ?>" data-end="<?= $yesterday ?>">
                                <i class="fas fa-calendar-minus"></i> Yesterday
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-info period-btn" data-start="<?= $week_start ?>" data-end="<?= $today ?>">
                                <i class="fas fa-calendar-week"></i> This Week
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-warning period-btn" data-start="<?= $month_start ?>" data-end="<?= $today ?>">
                                <i class="fas fa-calendar-alt"></i> This Month
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-success period-btn" data-start="<?= $year_start ?>" data-end="<?= $today ?>">
                                <i class="fas fa-calendar"></i> This Year
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-outline-dark period-btn" data-start="<?= $default_start_date ?>" data-end="<?= $default_end_date ?>">
                                <i class="fas fa-chart-line"></i> Last 30 Days
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Report Features -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-feather"></i> Report Features
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-receipt feature-icon text-primary"></i>
                            <h6>Professional Formatting</h6>
                            <p class="small text-muted">Clean, professional receipt-style reports</p>
                        </div>
                        <div class="text-center mb-3">
                            <i class="fas fa-calculator feature-icon text-success"></i>
                            <h6>Detailed Analytics</h6>
                            <p class="small text-muted">Complete breakdown with calculations</p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-chart-pie feature-icon text-warning"></i>
                            <h6>Payment Analysis</h6>
                            <p class="small text-muted">Payment method breakdown and percentages</p>
                        </div>
                    </div>
                </div>

                <!-- System Info -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle"></i> System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Generated by:</strong><br>
                            <?= $staff_name ?><br>
                            <span class="badge bg-<?= $staff_role === 'super_admin' ? 'danger' : ($staff_role === 'admin' ? 'warning' : 'info') ?>">
                                <?= ucfirst($staff_role) ?>
                            </span>
                        </div>
                        <hr>
                        <div class="mb-2">
                            <strong>Current Time:</strong><br>
                            <?= date('M j, Y g:i A') ?>
                        </div>
                        <div class="mb-2">
                            <strong>Data Range:</strong><br>
                            Up to <?= $today ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Quick period selection
        document.querySelectorAll('.period-btn').forEach(button => {
            button.addEventListener('click', function() {
                const startDate = this.getAttribute('data-start');
                const endDate = this.getAttribute('data-end');
                
                document.getElementById('start_date').value = startDate;
                document.getElementById('end_date').value = endDate;
                
                // Show confirmation
                const periodText = this.textContent.trim();
                alert(`Period set to: ${periodText}\n\nClick "Generate PDF Report" to continue.`);
            });
        });

        // Date validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (startDate > endDate) {
                e.preventDefault();
                alert('Error: Start date cannot be after end date.');
                return;
            }
            
            if (startDate > today || endDate > today) {
                e.preventDefault();
                alert('Error: Dates cannot be in the future.');
                return;
            }
            
            // Check if date range is reasonable
            const diffTime = Math.abs(endDate - startDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays > 365) {
                if (!confirm('You selected a date range larger than 1 year. This might take longer to generate. Continue?')) {
                    e.preventDefault();
                }
            }
        });

        // Set max dates to today
        document.getElementById('end_date').max = new Date().toISOString().split('T')[0];
        document.getElementById('start_date').max = new Date().toISOString().split('T')[0];

        // Auto-update end date when start date changes
        document.getElementById('start_date').addEventListener('change', function() {
            const endDate = document.getElementById('end_date');
            if (this.value > endDate.value) {
                endDate.value = this.value;
            }
            endDate.min = this.value;
        });
    </script>
</body>
</html>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}
