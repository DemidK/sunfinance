<?php

declare(strict_types=1);

namespace App\Dto\Payload;

use App\Enum\EventType;

interface InboundPayload
{
    public static function fromArray(array $data): static;

    public function eventId(): string;

    public function eventType(): EventType;

    public function normalizedAmount(): string;

    public function currency(): string;

    public function occurredAt(): \DateTimeImmutable;
}
