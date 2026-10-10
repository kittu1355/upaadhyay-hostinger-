<?php
/**
 * Hostinger entry point:  <site>/public_html/api/index.php
 * Laravel itself lives OUTSIDE the web root, in  <site>/laravel  (so .env, app/, vendor/ are never downloadable).
 */
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = null;
foreach ([dirname(__DIR__, 1) . '/laravel', dirname(__DIR__, 2) . '/laravel', dirname(__DIR__, 3) . '/laravel', dirname(__DIR__, 4) . '/laravel'] as $c) {
    if (is_file($c . '/vendor/autoload.php')) { $base = $c; break; }
}
if (! $base) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Backend not installed (laravel/vendor/autoload.php not found).']);
    exit;
}

if (file_exists($m = $base . '/storage/framework/maintenance.php')) { require $m; }

// We are served from the /api/ sub-folder. Without this, Symfony strips "/api" from the URL and every route 404s.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $base . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $base . '/bootstrap/app.php';
$app->usePublicPath(__DIR__);   // public_path('uploads') => public_html/api/uploads

$app->handleRequest(Request::capture());
