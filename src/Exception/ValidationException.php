<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends \RuntimeException
{
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Validation failed.');
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
