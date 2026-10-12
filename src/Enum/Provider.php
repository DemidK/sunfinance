<?php

declare(strict_types=1);

namespace App\Enum;

use App\Dto\Payload\BankPayload;
use App\Dto\Payload\CardPayload;
use App\Dto\Payload\InboundPayload;

enum Provider: string
{
    case Bank = 'bank';
    case Card = 'card';

    public function payload(array $data): InboundPayload
    {
        return match ($this) {
            self::Bank => BankPayload::fromArray($data),
            self::Card => CardPayload::fromArray($data),
        };
    }
}
