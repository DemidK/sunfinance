<?php

declare(strict_types=1);

namespace App\Enum;

enum EventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
}
