<?php

declare(strict_types=1);

namespace App\Exception;

final class PersistenceException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Failed to persist event.', 0, $previous);
    }
}
