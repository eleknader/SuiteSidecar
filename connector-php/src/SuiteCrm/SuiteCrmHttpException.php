<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class SuiteCrmHttpException extends SuiteCrmException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $bodySnippet,
        private readonly string $endpoint,
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBodySnippet(): string
    {
        return $this->bodySnippet;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
