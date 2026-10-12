<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidJsonException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Request body is not valid JSON.');
    }
}
