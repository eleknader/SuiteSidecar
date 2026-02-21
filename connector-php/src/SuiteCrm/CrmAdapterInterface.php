<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

interface CrmAdapterInterface
{
    public function lookupByEmail(string $email, array $include): array;

    public function logEmail(array $payload): array;

    public function createContact(array $payload): array;

    public function createLead(array $payload): array;
}
