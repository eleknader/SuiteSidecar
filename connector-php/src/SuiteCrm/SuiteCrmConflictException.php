<?php

declare(strict_types=1);

namespace SuiteSidecar\SuiteCrm;

final class SuiteCrmConflictException extends SuiteCrmException
{
    public function __construct(
        string $message,
        private readonly ?array $existingRecord = null
    ) {
        parent::__construct($message);
    }

    public function getExistingRecord(): ?array
    {
        return $this->existingRecord;
    }
}
