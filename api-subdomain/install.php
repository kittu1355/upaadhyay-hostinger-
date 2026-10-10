<?php
/**
 * ONE-TIME web installer for hosting plans WITHOUT SSH (Hostinger Single).
 * Upload to public_html/api/install.php, set INSTALL_TOKEN in laravel/.env, then open:
 *   https://YOURDOMAIN/api/install.php?token=YOUR_TOKEN&task=check
 * Tasks (run in this order):  check -> key -> migrate -> seed -> done.   DELETE THIS FILE WHEN FINISHED.
 */
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$base = null;
foreach ([dirname(__DIR__, 1) . '/laravel', dirname(__DIR__, 2) . '/laravel', dirname(__DIR__, 3) . '/laravel', dirname(__DIR__, 4) . '/laravel'] as $c) {
    if (is_dir($c . '/app')) { $base = $c; break; }
}
if (! $base) { http_response_code(500); exit("laravel/ folder not found next to public_html.\n"); }

$envFile = $base . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || ! str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(preg_replace('/\s+#.*$/', '', $v), " \t\"'");
    }
}
$token = $env['INSTALL_TOKEN'] ?? '';
if (strlen($token) < 20) { http_response_code(403); exit("Set INSTALL_TOKEN (20+ random characters) in laravel/.env first.\n"); }
if (! hash_equals($token, (string) ($_GET['token'] ?? ''))) { http_response_code(403); exit("Forbidden.\n"); }

$task = $_GET['task'] ?? 'check';
echo "== task: $task ==\n";

if ($task === 'check') {
    echo 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? "  OK\n" : "  TOO OLD - set PHP 8.2+ in hPanel -> PHP Configuration\n");
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo'] as $e) echo str_pad($e, 12) . (extension_loaded($e) ? "OK\n" : "MISSING (enable in hPanel -> PHP Configuration -> Extensions)\n");
    echo ".env          " . (is_file($envFile) ? "found\n" : "MISSING\n");
    echo "vendor/       " . (is_file($base . '/vendor/autoload.php') ? "found\n" : "MISSING - upload vendor.zip (see DEPLOY.md)\n");
    foreach (['storage', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $d) {
        echo str_pad($d, 32) . (is_writable("$base/$d") ? "writable\n" : "NOT WRITABLE (chmod 755/775)\n");
    }
    @mkdir(__DIR__ . '/uploads', 0755, true);
    echo "api/uploads   " . (is_writable(__DIR__ . '/uploads') ? "writable\n" : "NOT WRITABLE\n");
    echo "APP_KEY       " . (! empty($env['APP_KEY']) ? "set\n" : "empty -> run task=key\n");
    // database connection test (same credentials Laravel will use)
    try {
        $pdo = new PDO('mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';dbname=' . ($env['DB_DATABASE'] ?? ''), $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "DATABASE      connected OK (" . $pdo->query('select version()')->fetchColumn() . ")\n";
    } catch (Throwable $e) {
        echo "DATABASE      FAILED: " . $e->getMessage() . "\n              -> check DB_DATABASE / DB_USERNAME / DB_PASSWORD in laravel/.env (copy the full names from hPanel -> Databases)\n";
    }
    exit;
}

if ($task === 'key') {
    if (! empty($env['APP_KEY'])) exit("APP_KEY already set. Not touching it.\n");
    $key = 'base64:' . base64_encode(random_bytes(32));
    $src = file_get_contents($envFile);
    $src = preg_match('/^APP_KEY=.*$/m', $src) ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $src, 1) : $src . "\nAPP_KEY=$key\n";
    file_put_contents($envFile, $src);
    exit("APP_KEY written to .env\n");
}

// boot Laravel for the remaining tasks
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

try {
    $kernel->bootstrap();
    if ($task === 'migrate') {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    } elseif ($task === 'seed') {
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
    } elseif ($task === 'clear') {
        Illuminate\Support\Facades\Artisan::call('optimize:clear');
    } elseif ($task === 'done') {
        exit("Now DELETE api/install.php and remove INSTALL_TOKEN + ADMIN_PASSWORD from laravel/.env\n");
    } else {
        exit("Unknown task.\n");
    }
    echo Illuminate\Support\Facades\Artisan::output();
    echo "OK\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'FAILED: ' . $e->getMessage() . "\n";
}
