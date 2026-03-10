<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * ROUTER — PHP Built-in Server (Railway deployment)
 * ══════════════════════════════════════════════════════════════════
 * 
 * This router handles static files and PHP routing when using
 * PHP's built-in development server on Railway.
 * 
 * Usage: php -S 0.0.0.0:$PORT router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

// Block access to sensitive files
$blocked = ['.bak', '.sql', '.ini', '.log', '.sh', '.db_initialized', '.gitignore', '.env'];
foreach ($blocked as $ext) {
    if (str_ends_with($uri, $ext) || str_contains($uri, '.db_initialized')) {
        http_response_code(403);
        echo '403 Forbidden';
        return true;
    }
}

// Block access to config directory except PHP files
if (preg_match('#^/config/(?!.*\.php$)#', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// Block access to database directory
if (str_starts_with($uri, '/database/')) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// Serve static files directly
if (is_file($path)) {
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    
    // Set proper MIME types for common static files
    $mimeTypes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'webp' => 'image/webp',
        'webm' => 'video/webm',
        'mp4'  => 'video/mp4',
    ];
    
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        // Cache static assets for 7 days
        header('Cache-Control: public, max-age=604800');
        readfile($path);
        return true;
    }
    
    // Let PHP handle .php files
    if ($ext === 'php') {
        return false;
    }
    
    // Serve other files
    return false;
}

// Directory index — serve index.php or admin_login.php
if (is_dir($path)) {
    if (is_file($path . '/index.php')) {
        $_SERVER['SCRIPT_NAME'] = $uri . '/index.php';
        require $path . '/index.php';
        return true;
    }
    // Root directory — serve admin_login.php as default
    if ($uri === '/' && is_file(__DIR__ . '/admin_login.php')) {
        require __DIR__ . '/admin_login.php';
        return true;
    }
}

// Default — let PHP handle it
return false;
