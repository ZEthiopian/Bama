<?php
declare(strict_types=1);
session_start();
require_once '../config/config.php';

// Only allow super_admin in Codespace
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_role'] !== 'super_admin') {
    header("Location: ../login.php");
    exit();
}

$is_codespace = getenv('CODESPACES') === 'true';
$codespace_url = $is_codespace ? "https://{$_ENV['CODESPACE_NAME']}-8080.{$_ENV['GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN']}" : SITE_URL;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_apk'])) {
    $website_url = trim($_POST['website_url'] ?? $codespace_url);
    $app_name = trim($_POST['app_name'] ?? SITE_NAME);
    
    header("Location: https://www.pwabuilder.com?url=" . urlencode($website_url));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Generate APK - <?= SITE_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .service-card {
            transition: transform 0.3s ease;
            cursor: pointer;
        }
        .service-card:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h2 class="text-center mb-4">
                    <i class="fas fa-download"></i> Generate Mobile APK
                </h2>
                
                <?php if ($is_codespace): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Codespace Detected:</strong> Your current URL is <code><?= $codespace_url ?></code>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Website URL</label>
                                <input type="url" class="form-control" name="website_url" 
                                       value="<?= $codespace_url ?>" required>
                                <div class="form-text">This is the URL that will be wrapped in the APK</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">App Name</label>
                                <input type="text" class="form-control" name="app_name" 
                                       value="<?= SITE_NAME ?>" required>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="generate_apk" class="btn btn-primary btn-lg">
                                    <i class="fas fa-rocket"></i> Generate with PWA Builder
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-4 mb-3">
                        <div class="card service-card h-100" onclick="window.open('https://www.pwabuilder.com', '_blank')">
                            <div class="card-body text-center">
                                <i class="fas fa-cube fa-3x text-primary mb-3"></i>
                                <h5>PWA Builder</h5>
                                <p class="text-muted">Microsoft's official PWA to APK converter</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card service-card h-100" onclick="window.open('https://www.turnsapp.com', '_blank')">
                            <div class="card-body text-center">
                                <i class="fas fa-mobile-alt fa-3x text-success mb-3"></i>
                                <h5>TurnsApp</h5>
                                <p class="text-muted">Simple website to app conversion</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="card service-card h-100" onclick="window.open('https://gonative.io', '_blank')">
                            <div class="card-body text-center">
                                <i class="fas fa-code fa-3x text-warning mb-3"></i>
                                <h5>GoNative</h5>
                                <p class="text-muted">Advanced website to native app</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-warning mt-4">
                    <h5><i class="fas fa-lightbulb"></i> APK Generation Instructions</h5>
                    <ol class="mb-0">
                        <li>Click "Generate with PWA Builder" to open the conversion tool</li>
                        <li>Follow the step-by-step process on PWA Builder</li>
                        <li>Download the generated APK file</li>
                        <li>Distribute the APK to your users or publish to app stores</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
