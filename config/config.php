<?php
declare(strict_types=1);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dermesvj_bama');
define('DB_USER', 'dermesvj_root'); 
define('DB_PASS', 'Dragona-99'); 

// Site Configuration
define('SITE_NAME', 'Bama Restaurant');
define('SITE_URL', 'https://bama.dermesengido.com');
define('CURRENCY', 'ETB');

// File Upload Configuration
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// API & Security Configuration
define('API_KEY', 'bama_restaurant_api_key_2024_secret');
define('JWT_SECRET', 'bama_jwt_secret_key_2024_secure_token');
define('TOKEN_EXPIRY', 86400); // 24 hours in seconds

// Cross-Origin Configuration for APK/API Access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests for CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Enable if using HTTPS
ini_set('session.use_strict_mode', 1);

// Error reporting (disable in production by setting to 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Timezone
date_default_timezone_set('Africa/Addis_Ababa');

// Application Constants
define('APP_VERSION', '2.0.0');
define('APP_BUILD', '2024');
define('SUPPORTED_ROLES', ['super_admin', 'admin', 'waiter', 'chef', 'cashier']);

// API Rate Limiting (requests per minute)
define('API_RATE_LIMIT', 60);
define('API_RATE_WINDOW', 60); // seconds

// Mobile App Configuration
define('MOBILE_APP_USER_AGENT', 'BamaRestaurantApp');
define('MOBILE_APP_VERSION', '1.0.0');

// Logging Configuration
define('LOG_ENABLED', true);
define('LOG_FILE', __DIR__ . '/../logs/app.log');

// Create logs directory if it doesn't exist
if (!is_dir(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0755, true);
}

/**
 * Simple logging function
 */
function log_message($message, $level = 'INFO') {
    if (LOG_ENABLED) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Validate API Key
 */
function validate_api_key($api_key) {
    return $api_key === API_KEY;
}

/**
 * Get client IP address
 */
function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

/**
 * Check if request is from mobile app
 */
function is_mobile_app_request() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return strpos($user_agent, MOBILE_APP_USER_AGENT) !== false ||
           isset($_SERVER['HTTP_X_API_KEY']) ||
           isset($_SERVER['HTTP_AUTHORIZATION']);
}

/**
 * Send JSON response
 */
function send_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Generate random API token
 */
function generate_api_token() {
    return bin2hex(random_bytes(32));
}

// Auto-load required classes
spl_autoload_register(function ($class_name) {
    $class_file = __DIR__ . '/../classes/' . $class_name . '.php';
    if (file_exists($class_file)) {
        require_once $class_file;
    }
});

// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Regenerate session ID periodically
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

log_message("Config loaded successfully - " . SITE_NAME);
?>
