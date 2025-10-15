<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

error_log("=== LOGIN PAGE LOADED ===");

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

$error_message = "";
$success_message = "";

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    error_log("Login attempt for: " . $username);
    
    if (!empty($username) && !empty($password)) {
        $query = "SELECT id, username, password, role, display_name, is_active, api_token
                 FROM comp_users 
                 WHERE username = ? AND is_active = 1";
        $stmt = $db->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    if (password_verify($password, $user['password'])) {
                        error_log("✅ Login SUCCESS for: " . $username . " with role: " . $user['role']);
                        
                        // Set session variables
                        $_SESSION['staff_logged_in'] = true;
                        $_SESSION['staff_id'] = $user['id'];
                        $_SESSION['staff_username'] = $user['username'];
                        $_SESSION['staff_role'] = $user['role'];
                        $_SESSION['staff_name'] = $user['display_name'];
                        $_SESSION['staff_api_token'] = $user['api_token'];
                        
                        $stmt->close();
                        $db->close();
                        
                        // Role-based redirection
                        $redirect_url = getRedirectUrlByRole($user['role']);
                        error_log("🔄 Redirecting to: " . $redirect_url);
                        
                        header("Location: " . $redirect_url);
                        exit();
                        
                    } else {
                        $error_message = "❌ Invalid password. Please try '123456'";
                        error_log("❌ Password mismatch for: " . $username);
                    }
                } else {
                    $error_message = "❌ User not found or inactive: " . htmlspecialchars($username);
                }
            } else {
                $error_message = "❌ Database query failed";
            }
            $stmt->close();
        } else {
            $error_message = "❌ Database preparation failed: " . $db->error;
        }
    } else {
        $error_message = "❌ Please enter both username and password";
    }
}

$db->close();

/**
 * Get redirect URL based on user role
 */
function getRedirectUrlByRole($role) {
    switch (strtolower($role)) {
        case 'super_admin':
        case 'admin':
            return 'dashboard.php';
        case 'waiter':
            return 'waiter_menu.php';
        case 'cashier':
            return 'cashier_dashboard.php';
        case 'chef':
            return 'kitchen_order.php';
        default:
            return 'staff_dashboard.php'; // Fallback
    }
}

// Check if user is already logged in and redirect them
if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true) {
    $redirect_url = getRedirectUrlByRole($_SESSION['staff_role']);
    error_log("User already logged in, redirecting to: " . $redirect_url);
    header("Location: " . $redirect_url);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <!-- Add to all pages in head section -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
<link rel="icon" type="image/x-icon" href="icons/favicon.ico">
<link rel="apple-touch-icon" href="icons/icon-152x152.png">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            max-width: 450px;
            margin: 0 auto;
            padding: 2.5rem;
            border: none;
        }
        .role-badge {
            font-size: 0.75em;
            padding: 4px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }
        .badge-super-admin { background: #dc3545; color: white; }
        .badge-admin { background: #fd7e14; color: white; }
        .badge-waiter { background: #20c997; color: white; }
        .badge-chef { background: #6f42c1; color: white; }
        .badge-cashier { background: #0dcaf0; color: white; }
        .btn-login {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            padding: 12px;
            font-weight: 600;
            font-size: 16px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .user-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }
        .password-field {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
        }
        @media (max-width: 768px) {
    .login-card {
        margin: 10px;
        padding: 1.5rem;
        border-radius: 15px;
    }
    
    .btn-login {
        padding: 15px;
        font-size: 18px;
    }
    
    .user-card {
        padding: 10px;
    }
    
    body {
        padding: 10px;
        align-items: flex-start;
    }
}

/* Prevent zoom on input focus */
@media (max-width: 768px) {
    input, select, textarea {
        font-size: 16px !important;
    }
}

/* Better touch targets */
.btn, .nav-link, .form-control {
    min-height: 44px;
}

/* Swipe gestures for mobile */
.touch-actions {
    -webkit-overflow-scrolling: touch;
}
    </style>
    <script>
// Register service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
        .then(function(registration) {
            console.log('Service Worker registered with scope:', registration.scope);
        })
        .catch(function(error) {
            console.log('Service Worker registration failed:', error);
        });
}

// Add to home screen prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showInstallPromotion();
});

function showInstallPromotion() {
    const installBtn = document.createElement('button');
    installBtn.innerHTML = '📱 Install App';
    installBtn.className = 'btn btn-success w-100 mb-3';
    installBtn.onclick = installApp;
    
    const form = document.getElementById('loginForm');
    form.parentNode.insertBefore(installBtn, form);
}

function installApp() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
            }
            deferredPrompt = null;
        });
    }
}
</script>
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="text-center mb-4">
                <h2 class="text-primary fw-bold">
                    <i class="fas fa-utensils me-2"></i><?php echo SITE_NAME; ?>
                </h2>
                <p class="text-muted">Restaurant Management System</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-user me-2 text-primary"></i>Username
                    </label>
                    <input type="text" class="form-control form-control-lg" name="username" value="Besufekad" required placeholder="Enter your username">
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-lock me-2 text-primary"></i>Password
                    </label>
                    <div class="password-field">
                        <input type="password" class="form-control form-control-lg" id="password" name="password" value="123456" required placeholder="Enter your password">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        <i class="fas fa-key me-1"></i>Default password: 123456
                    </div>
                </div>
                
                <button type="submit" name="login" class="btn btn-login text-white w-100 py-3 mb-4">
                    <i class="fas fa-sign-in-alt me-2"></i>Login to System
                </button>
            </form>

            <!-- Available Users -->
            <div class="user-card">
                <h6 class="fw-bold mb-3">
                    <i class="fas fa-users me-2 text-primary"></i>Available Test Users:
                </h6>
                
                <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-white rounded">
                    <div>
                        <strong>Besufekad</strong>
                        <span class="badge badge-super-admin">Super Admin</span>
                    </div>
                    <small class="text-muted">→ Dashboard</small>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-white rounded">
                    <div>
                        <strong>Addis</strong>
                        <span class="badge badge-admin">Admin</span>
                    </div>
                    <small class="text-muted">→ Dashboard</small>
                </div>
                
                <div class="d-flex justify-content-between align-items-center p-2 bg-white rounded">
                    <div>
                        <strong>Waiter User</strong>
                        <span class="badge badge-waiter">Waiter</span>
                    </div>
                    <small class="text-muted">→ Waiter Menu</small>
                </div>

                <div class="d-flex justify-content-between align-items-center p-2 bg-white rounded">
                    <div>
                        <strong>Chef User</strong>
                        <span class="badge badge-chef">Chef</span>
                    </div>
                    <small class="text-muted">→ Kitchen Orders</small>
                </div>

                <div class="d-flex justify-content-between align-items-center p-2 bg-white rounded">
                    <div>
                        <strong>Cashier User</strong>
                        <span class="badge badge-cashier">Cashier</span>
                    </div>
                    <small class="text-muted">→ Cashier Dashboard</small>
                </div>
            </div>

            <div class="text-center mt-3">
                <a href="diagnose_login.php" class="btn btn-outline-warning btn-sm me-2">
                    <i class="fas fa-tools me-1"></i>Diagnostics
                </a>
                <a href="register.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-user-plus me-1"></i>Register Staff
                </a>
            </div>

            <!-- Role-based destinations info -->
            <div class="mt-4 p-3 bg-light rounded">
                <h6 class="fw-bold mb-2">
                    <i class="fas fa-info-circle me-2 text-info"></i>Role Destinations:
                </h6>
                <div class="row small text-center">
                    <div class="col-6 mb-2">
                        <span class="badge badge-super-admin me-1">Super Admin</span>
                        <span class="badge badge-admin me-1">Admin</span>
                        <br>
                        <span class="text-muted">→ Dashboard</span>
                    </div>
                    <div class="col-6 mb-2">
                        <span class="badge badge-waiter me-1">Waiter</span>
                        <br>
                        <span class="text-muted">→ Waiter Menu</span>
                    </div>
                    <div class="col-6">
                        <span class="badge badge-chef me-1">Chef</span>
                        <br>
                        <span class="text-muted">→ Kitchen Orders</span>
                    </div>
                    <div class="col-6">
                        <span class="badge badge-cashier me-1">Cashier</span>
                        <br>
                        <span class="text-muted">→ Cashier Dashboard</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Auto-submit form if user is already logged in (redundant safety)
        document.addEventListener('DOMContentLoaded', function() {
            // Clear any cached form data
            document.getElementById('loginForm').reset();
            
            // Focus on username field
            document.querySelector('input[name="username"]').focus();
        });
    </script>
</body>
</html>
