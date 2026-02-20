<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class SuiteCrmAuthException extends SuiteCrmException
{
    public function __construct(string $message, private readonly int $statusCode = 401)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
