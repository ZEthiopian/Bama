<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$staff_name = htmlspecialchars($_SESSION['staff_name'] ?? '');
$staff_role = htmlspecialchars($_SESSION['staff_role'] ?? '');
$company_name = SITE_NAME;

// Get quick actions based on role
function getMobileActions($role) {
    $actions = [];
    
    if (in_array($role, ['super_admin', 'admin'])) {
        $actions = [
            ['url' => 'dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'color' => 'primary'],
            ['url' => 'display.php', 'icon' => 'fas fa-cubes', 'label' => 'Menu Items', 'color' => 'success'],
            ['url' => 'categories.php', 'icon' => 'fas fa-tags', 'label' => 'Categories', 'color' => 'info'],
            ['url' => 'orders.php', 'icon' => 'fas fa-shopping-cart', 'label' => 'Orders', 'color' => 'warning'],
            ['url' => 'staff.php', 'icon' => 'fas fa-users', 'label' => 'Staff', 'color' => 'secondary'],
            ['url' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'color' => 'dark']
        ];
    } elseif ($role === 'waiter') {
        $actions = [
            ['url' => 'waiter_menu.php', 'icon' => 'fas fa-plus-circle', 'label' => 'New Order', 'color' => 'success'],
            ['url' => 'orders.php', 'icon' => 'fas fa-list', 'label' => 'View Orders', 'color' => 'info'],
            ['url' => 'display.php', 'icon' => 'fas fa-cube', 'label' => 'Menu', 'color' => 'primary'],
            ['url' => 'dashboard.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'color' => 'secondary']
        ];
    } elseif ($role === 'chef') {
        $actions = [
            ['url' => 'kitchen_order.php', 'icon' => 'fas fa-utensils', 'label' => 'Kitchen Orders', 'color' => 'danger'],
            ['url' => 'orders.php', 'icon' => 'fas fa-list', 'label' => 'All Orders', 'color' => 'info'],
            ['url' => 'display.php', 'icon' => 'fas fa-cube', 'label' => 'Menu Items', 'color' => 'primary']
        ];
    } elseif ($role === 'cashier') {
        $actions = [
            ['url' => 'transactions.php', 'icon' => 'fas fa-receipt', 'label' => 'Transactions', 'color' => 'success'],
            ['url' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports', 'color' => 'info'],
            ['url' => 'orders.php', 'icon' => 'fas fa-shopping-cart', 'label' => 'Orders', 'color' => 'primary'],
            ['url' => 'dashboard.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard', 'color' => 'secondary']
        ];
    }
    
    return $actions;
}

$mobile_actions = getMobileActions($staff_role);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mobile - <?= $company_name ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding-bottom: 80px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .mobile-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
            border: none;
        }
        
        .mobile-card:active {
            transform: scale(0.95);
        }
        
        .quick-action {
            display: block;
            text-decoration: none;
            color: inherit;
        }
        
        .action-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }
        
        .action-primary { color: var(--primary); }
        .action-success { color: var(--success); }
        .action-info { color: var(--secondary); }
        .action-warning { color: var(--warning); }
        .action-danger { color: var(--danger); }
        .action-secondary { color: #6c757d; }
        .action-dark { color: #343a40; }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 2px solid #dee2e6;
            padding: 10px 5px;
            z-index: 1000;
        }
        
        .nav-item {
            text-align: center;
            padding: 5px;
        }
        
        .nav-icon {
            font-size: 1.3rem;
            margin-bottom: 3px;
            display: block;
        }
        
        .nav-link {
            color: #6c757d;
            text-decoration: none;
            font-size: 0.8rem;
        }
        
        .nav-link.active {
            color: var(--primary);
            font-weight: bold;
        }
        
        .user-info {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 10px;
            margin: 10px;
            color: white;
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
        
        .action-label {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        .welcome-section {
            color: white;
            text-align: center;
            padding: 20px 10px;
        }
        
        .current-time {
            font-size: 0.8rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <!-- Welcome Header -->
    <div class="welcome-section">
        <h4 class="mb-1">
            <i class="fas fa-utensils"></i> <?= $company_name ?>
        </h4>
        <p class="mb-2">Welcome, <?= $staff_name ?></p>
        <span class="badge role-badge badge-<?= str_replace('_', '-', $staff_role) ?>">
            <?= ucfirst(str_replace('_', ' ', $staff_role)) ?>
        </span>
        <div class="current-time mt-2">
            <i class="fas fa-clock"></i> <?= date('g:i A') ?>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="container-fluid">
        <div class="row g-2">
            <?php if (!empty($mobile_actions)): ?>
                <?php foreach ($mobile_actions as $action): ?>
                    <div class="col-6">
                        <a href="<?= $action['url'] ?>" class="quick-action">
                            <div class="mobile-card text-center">
                                <i class="<?= $action['icon'] ?> action-icon action-<?= $action['color'] ?>"></i>
                                <p class="action-label"><?= $action['label'] ?></p>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="mobile-card text-center">
                        <i class="fas fa-exclamation-triangle action-icon action-warning"></i>
                        <p class="action-label">No actions available</p>
                        <small class="text-muted">Contact administrator for access</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Additional Info Card -->
        <div class="row g-2 mt-1">
            <div class="col-12">
                <div class="mobile-card">
                    <h6 class="mb-2"><i class="fas fa-info-circle text-info"></i> Quick Info</h6>
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted">Today</small>
                            <div class="fw-bold"><?= date('M j, Y') ?></div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Server Time</small>
                            <div class="fw-bold"><?= date('H:i:s') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <div class="row text-center">
            <div class="col-3 nav-item">
                <a href="mobile_dashboard.php" class="nav-link active">
                    <i class="fas fa-home nav-icon"></i>
                    <div>Home</div>
                </a>
            </div>
            <div class="col-3 nav-item">
                <a href="orders.php" class="nav-link">
                    <i class="fas fa-shopping-cart nav-icon"></i>
                    <div>Orders</div>
                </a>
            </div>
            <div class="col-3 nav-item">
                <a href="display.php" class="nav-link">
                    <i class="fas fa-cube nav-icon"></i>
                    <div>Menu</div>
                </a>
            </div>
            <div class="col-3 nav-item">
                <a href="login.php?logout=true" class="nav-link">
                    <i class="fas fa-sign-out-alt nav-icon"></i>
                    <div>Logout</div>
                </a>
            </div>
        </div>
    </nav>

    <script>
        // Service Worker Registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js')
                .then(function(registration) {
                    console.log('Service Worker registered with scope:', registration.scope);
                })
                .catch(function(error) {
                    console.log('Service Worker registration failed:', error);
                });
        }

        // Add to Home Screen
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showInstallPrompt();
        });

        function showInstallPrompt() {
            if (deferredPrompt) {
                // Show custom install button
                const installBtn = document.createElement('button');
                installBtn.innerHTML = '<i class="fas fa-download"></i> Install App';
                installBtn.className = 'btn btn-success w-100 m-2';
                installBtn.onclick = installApp;
                
                const container = document.querySelector('.container-fluid');
                container.insertBefore(installBtn, container.firstChild);
            }
        }

        function installApp() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User installed the app');
                        // Hide the install button
                        const installBtn = document.querySelector('.btn-success');
                        if (installBtn) installBtn.remove();
                    }
                    deferredPrompt = null;
                });
            }
        }

        // Update time every second
        function updateTime() {
            const timeElement = document.querySelector('.current-time');
            if (timeElement) {
                const now = new Date();
                timeElement.innerHTML = `<i class="fas fa-clock"></i> ${now.toLocaleTimeString()}`;
            }
        }
        
        setInterval(updateTime, 1000);

        // Add touch feedback
        document.addEventListener('touchstart', function() {}, {passive: true});
        
        // Prevent zoom on double tap
        document.addEventListener('touchend', function(e) {
            if (e.touches && e.touches.length > 1) {
                e.preventDefault();
            }
        }, { passive: false });
    </script>
</body>
</html>
