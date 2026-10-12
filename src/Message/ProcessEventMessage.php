<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessEventMessage
{
    public function __construct(public int $id)
    {
    }
}
