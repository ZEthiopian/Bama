<?php
declare(strict_types=1);
session_start();
require_once 'config/config.php';

/**
 * Mobile device detection
 */
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match("/(android|webos|iphone|ipad|ipod|blackberry|windows phone)/i", $userAgent);
}

/**
 * Get the appropriate landing page based on device and login status
 */
function getLandingPage() {
    // Check if user is logged in
    if (isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true) {
        return isMobileDevice() ? 'mobile_dashboard.php' : 'dashboard.php';
    } else {
        return 'login.php';
    }
}

// Redirect to appropriate page
$landingPage = getLandingPage();
header("Location: $landingPage");
exit();
?>
