<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;

final class LookupController
{
    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function byEmail(): void
    {
        $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
        if ($email === '') {
            Response::error('bad_request', 'Missing required query parameter: email', 400);
            return;
        }

        // Parse include=account,timeline (optional)
        $includeRaw = isset($_GET['include']) ? (string)$_GET['include'] : '';
        $include = array_values(array_filter(array_map('trim', explode(',', $includeRaw))));
        $payload = $this->adapter->lookupByEmail($email, $include);
        Response::json($payload, 200);
    }
}
