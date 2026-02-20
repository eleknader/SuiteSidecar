<?php

declare(strict_types=1);

use SuiteSidecar\Http\Router;
use SuiteSidecar\Http\Response;
use SuiteSidecar\LookupController;
use SuiteSidecar\ProfileController;
use SuiteSidecar\SuiteCrm\MockAdapter;
use SuiteSidecar\SuiteCrm\OAuthTokenProvider;
use SuiteSidecar\SuiteCrm\Profile;
use SuiteSidecar\SuiteCrm\V8Adapter;
use SuiteSidecar\SuiteCrm\V8Client;
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

try {
    $profiles = Profile::loadAll(dirname(__DIR__) . '/config/profiles.php');
    if ($profiles === []) {
        throw new \RuntimeException('No profiles configured');
    }

    $activeProfile = $profiles[0];
    $tokenProvider = new OAuthTokenProvider();

    if ($activeProfile->apiFlavor === 'suitecrm_v8_jsonapi') {
        $crmAdapter = new V8Adapter(
            $activeProfile,
            new V8Client(
                $activeProfile->suitecrmBaseUrl,
                $tokenProvider,
                $activeProfile,
                Response::requestId()
            )
        );
    } else {
        $crmAdapter = new MockAdapter();
    }

    $router = new Router();
    $systemController = new SystemController();
    $profileController = new ProfileController($profiles);
    $lookupController = new LookupController($crmAdapter);

    $router->get('/health', [$systemController, 'health']);
    $router->get('/version', [$systemController, 'version']);
    $router->get('/profiles', [$profileController, 'listProfiles']);
    $router->get('/lookup/by-email', [$lookupController, 'byEmail']);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    $router->dispatch($method, $path);
} catch (\Throwable $e) {
    error_log('[requestId=' . Response::requestId() . '] Bootstrap error: ' . $e->getMessage());
    Response::error('server_error', 'Internal server error', 500);
}
