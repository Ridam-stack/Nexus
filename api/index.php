<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Manual PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

use App\Core\Router;

$router = new Router();

// Auth Routes
$router->add('POST', '/auth/register', 'AuthController', 'register');
$router->add('POST', '/auth/login', 'AuthController', 'login');
$router->add('GET', '/auth/me', 'AuthController', 'me');

// Server Routes
$router->add('GET', '/servers', 'ServerController', 'index');
$router->add('POST', '/servers', 'ServerController', 'create');
$router->add('GET', '/servers/{id}', 'ServerController', 'show');

// Channel Routes
$router->add('GET', '/channels/{channel_id}/messages', 'MessageController', 'getChannelMessages');
$router->add('POST', '/channels/{channel_id}/messages', 'MessageController', 'sendMessage');

// Friend Routes
$router->add('GET', '/friends', 'UserController', 'getFriends');
$router->add('POST', '/friends/add', 'UserController', 'addFriend');

// User Profile Routes
$router->add('POST', '/user/profile', 'UserController', 'updateProfile');
$router->add('POST', '/user/status', 'UserController', 'updateStatus');

// Dispatch
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

header('Content-Type: application/json');

try {
    $router->dispatch($method, $uri);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
