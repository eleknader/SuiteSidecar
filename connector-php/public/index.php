<?php

declare(strict_types=1);

use SuiteSidecar\Auth\AuthController;
use SuiteSidecar\Auth\AuthMiddleware;
use SuiteSidecar\Auth\AuthException;
use SuiteSidecar\Auth\JwtService;
use SuiteSidecar\Auth\SessionStore;
use SuiteSidecar\Http\Router;
use SuiteSidecar\Http\Response;
use SuiteSidecar\LookupController;
use SuiteSidecar\ProfileController;
use SuiteSidecar\ProfileRegistry;
use SuiteSidecar\ProfileResolutionException;
use SuiteSidecar\ProfileResolver;
use SuiteSidecar\SuiteCrm\MockAdapter;
use SuiteSidecar\SuiteCrm\OAuthTokenProvider;
use SuiteSidecar\SuiteCrm\SessionAccessTokenProvider;
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

$readHeaders = static function (): array {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headerName = str_replace('_', '-', ucwords(strtolower(substr($key, 5)), '_'));
            $headers[$headerName] = (string) $value;
        }
    }
    return $headers;
};

$jwtSecret = getenv('SUITESIDECAR_JWT_SECRET');
$jwtConfigured = is_string($jwtSecret) && trim($jwtSecret) !== '';
if (!$jwtConfigured) {
    error_log(
        '[requestId=' . Response::requestId() . '] Startup health check: '
        . 'SUITESIDECAR_JWT_SECRET is missing; authenticated endpoints are unavailable'
    );
}

$buildJwtService = static function () use ($jwtConfigured, $jwtSecret): ?JwtService {
    if (!$jwtConfigured) {
        return null;
    }

    $ttlRaw = getenv('SUITESIDECAR_JWT_TTL_SECONDS');
    $ttlSeconds = is_string($ttlRaw) && trim($ttlRaw) !== '' ? (int) $ttlRaw : 8 * 3600;

    return new JwtService((string) $jwtSecret, $ttlSeconds);
};

try {
    $profileRegistry = ProfileRegistry::loadDefault();
    if ($profileRegistry->count() === 0) {
        throw new \RuntimeException('No profiles configured');
    }

    $profileResolver = new ProfileResolver($profileRegistry);
    $oauthTokenProvider = new OAuthTokenProvider();
    $sessionStore = new SessionStore();
    $router = new Router();
    $systemController = new SystemController();
    $profileController = new ProfileController($profileRegistry->all());

    $router->get('/health', [$systemController, 'health']);
    $router->get('/version', [$systemController, 'version']);
    $router->get('/profiles', [$profileController, 'listProfiles']);
    $router->post('/auth/login', static function () use (
        $buildJwtService,
        $profileRegistry,
        $oauthTokenProvider,
        $sessionStore
    ): void {
        $jwtService = $buildJwtService();
        if ($jwtService === null) {
            Response::error('server_error', 'Authentication service is not configured', 500);
            return;
        }

        try {
            $authController = new AuthController($profileRegistry, $oauthTokenProvider, $jwtService, $sessionStore);
            $authController->login();
        } catch (AuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] Login setup failed: ' . $e->getMessage());
            Response::error('server_error', 'Internal server error', 500);
        }
    });
    $router->get('/lookup/by-email', static function () use (
        $buildJwtService,
        $readHeaders,
        $sessionStore,
        $profileResolver
    ): void {
        $jwtService = $buildJwtService();
        if ($jwtService === null) {
            Response::error('server_error', 'Authentication service is not configured', 500);
            return;
        }

        try {
            $headers = $readHeaders();
            $authMiddleware = new AuthMiddleware($jwtService, $sessionStore);
            $authContext = $authMiddleware->requireAuth($headers);
            if ($authContext === null) {
                return;
            }

            $profile = $profileResolver->resolveForLookup($_GET, $headers);

            $session = isset($authContext['session']) && is_array($authContext['session']) ? $authContext['session'] : [];
            $sessionProfileId = isset($session['profileId']) ? (string) $session['profileId'] : '';
            if ($sessionProfileId !== '' && $sessionProfileId !== $profile->id) {
                Response::error('unauthorized', 'Profile does not match authenticated session', 401);
                return;
            }

            if ($profile->apiFlavor === 'suitecrm_v8_jsonapi') {
                $tokenProvider = new SessionAccessTokenProvider($session, Response::requestId());
                $adapter = new V8Adapter(
                    $profile,
                    new V8Client(
                        $profile->suitecrmBaseUrl,
                        $tokenProvider,
                        $profile,
                        Response::requestId()
                    )
                );
            } else {
                $adapter = new MockAdapter();
            }

            $lookupController = new LookupController($adapter);
            $lookupController->byEmail();
        } catch (ProfileResolutionException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
        } catch (AuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] Lookup auth setup failed: ' . $e->getMessage());
            Response::error('server_error', 'Internal server error', 500);
        }
    });
    // Future authenticated endpoints (for example /email/log) should use the same auth + profile resolver flow.

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    $router->dispatch($method, $path);
} catch (\Throwable $e) {
    error_log('[requestId=' . Response::requestId() . '] Bootstrap error: ' . $e->getMessage());
    Response::error('server_error', 'Internal server error', 500);
}
