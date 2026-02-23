<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;

final class SystemController
{
    private const DEFAULT_VERSION = '0.1.0';

    public function health(): void
    {
        Response::json([
            'status' => 'ok',
            'time' => gmdate('c')
        ]);
    }

    public function version(): void
    {
        $appVersion = $this->envString('APP_VERSION') ?? self::DEFAULT_VERSION;
        $addInManifestVersion = $this->envString('SUITESIDECAR_ADDIN_MANIFEST_VERSION');
        $addInAssetVersion = $this->envString('SUITESIDECAR_ADDIN_ASSET_VERSION');

        Response::json([
            'name' => 'suitesidecar-connector-php',
            'version' => $appVersion,
            'apiVersion' => self::DEFAULT_VERSION,
            'addInManifestVersion' => $addInManifestVersion,
            'addInAssetVersion' => $addInAssetVersion,
            'gitSha' => getenv('APP_GIT_SHA') ?: null,
            'buildTime' => getenv('APP_BUILD_TIME') ?: null,
            'limits' => RuntimeLimits::resolve(),
        ]);
    }

    private function envString(string $key): ?string
    {
        $value = getenv($key);
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
