<?php

declare(strict_types=1);

use SuiteSidecar\Http\Router;
use SuiteSidecar\Http\Response;
use SuiteSidecar\LookupController;
use SuiteSidecar\ProfileController;
use SuiteSidecar\SuiteCrm\MockAdapter;
use SuiteSidecar\SystemController;

require_once __DIR__ . '/../vendor/autoload.php';

// Default JSON
header('Content-Type: application/json');

// Basic CORS (tighten later; MVP only)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

$crmAdapter = new MockAdapter();
$systemController = new SystemController();
$profileController = new ProfileController();
$lookupController = new LookupController($crmAdapter);

$router->get('/health', [$systemController, 'health']);
$router->get('/version', [$systemController, 'version']);
$router->get('/profiles', [$profileController, 'listProfiles']);
$router->get('/lookup/by-email', [$lookupController, 'byEmail']);

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    $router->dispatch($method, $path);
} catch (Throwable $e) {
    Response::error('server_error', $e->getMessage(), 500);
}
