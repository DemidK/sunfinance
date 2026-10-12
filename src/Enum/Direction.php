<?php

declare(strict_types=1);

namespace App\Enum;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public static function values(): array
    {
        return array_map(static fn (self $direction): string => $direction->value, self::cases());
    }
}
