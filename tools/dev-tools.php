<?php
declare(strict_types=1);
session_start();
require_once '../config/config.php';

// Only allow in development/Codespace
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit();
}

$is_codespace = getenv('CODESPACES') === 'true';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Development Tools - <?= SITE_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container py-4">
        <h2 class="mb-4">
            <i class="fas fa-tools"></i> Development Tools
            <?php if ($is_codespace): ?>
                <span class="badge bg-success">Codespace</span>
            <?php endif; ?>
        </h2>
        
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-database"></i> Database Management
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="database-reset.php" class="btn btn-outline-primary" 
                               onclick="return confirm('Reset database to default? This will erase all data!')">
                                Reset Database
                            </a>
                            <a href="sample-data.php" class="btn btn-outline-success">
                                Load Sample Data
                            </a>
                            <a href="export-db.php" class="btn btn-outline-info">
                                Export Database
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-mobile-alt"></i> Mobile Deployment
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="generate_apk.php" class="btn btn-outline-success">
                                Generate APK
                            </a>
                            <a href="../manifest.json" class="btn btn-outline-primary" target="_blank">
                                View PWA Manifest
                            </a>
                            <a href="pwa-test.php" class="btn btn-outline-info">
                                Test PWA Features
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-vial"></i> Testing Tools
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="test-users.php" class="btn btn-outline-warning">
                                Test User Accounts
                            </a>
                            <a href="mobile-preview.php" class="btn btn-outline-info">
                                Mobile Preview
                            </a>
                            <a href="performance-test.php" class="btn btn-outline-dark">
                                Performance Test
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-code"></i> Development Info
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Environment:</strong> 
                            <?= $is_codespace ? 'GitHub Codespace' : 'Production' ?>
                        </div>
                        <div class="mb-2">
                            <strong>PHP Version:</strong> <?= PHP_VERSION ?>
                        </div>
                        <div class="mb-2">
                            <strong>Database:</strong> 
                            <?= DB_HOST ?> (<?= DB_NAME ?>)
                        </div>
                        <div class="mb-2">
                            <strong>Site URL:</strong> 
                            <a href="<?= SITE_URL ?>" target="_blank"><?= SITE_URL ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
