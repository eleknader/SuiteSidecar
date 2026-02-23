<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;

final class SystemController
{
    public function health(): void
    {
        Response::json([
            'status' => 'ok',
            'time' => gmdate('c')
        ]);
    }

    public function version(): void
    {
        Response::json([
            'name' => 'suitesidecar-connector-php',
            'version' => '0.1.0',
            'gitSha' => getenv('APP_GIT_SHA') ?: null,
            'buildTime' => getenv('APP_BUILD_TIME') ?: null,
            'limits' => RuntimeLimits::resolve(),
        ]);
    }
}
