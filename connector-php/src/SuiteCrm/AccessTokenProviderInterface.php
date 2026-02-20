<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

interface AccessTokenProviderInterface
{
    public function getAccessToken(Profile $profile): string;
}
