<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

interface CrmAdapterInterface
{
    public function lookupByEmail(string $email, array $include): array;
}
