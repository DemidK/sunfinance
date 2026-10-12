<?php

declare(strict_types=1);

namespace App\Dto\Payload;

use App\Enum\EventType;
use App\Validator\DateTimeString;
use App\Validator\DateTimeStringValidator;
use Symfony\Component\Validator\Constraints as Assert;

final class BankPayload implements InboundPayload
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public mixed $eventId = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Choice(callback: [EventType::class, 'values'], message: 'Unknown event type.')]
    public mixed $type = null;

    #[Assert\NotNull]
    #[Assert\Type('numeric')]
    #[Assert\Positive]
    public mixed $amount = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 3, max: 3)]
    public mixed $currency = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[DateTimeString]
    public mixed $timestamp = null;

    public static function fromArray(array $data): static
    {
        $payload = new self();
        $payload->eventId = $data['event_id'] ?? null;
        $payload->type = $data['type'] ?? null;
        $payload->amount = $data['amount'] ?? null;
        $payload->currency = $data['currency'] ?? null;
        $payload->timestamp = $data['timestamp'] ?? null;

        return $payload;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): EventType
    {
        return EventType::from($this->type);
    }

    public function normalizedAmount(): string
    {
        return number_format((float) $this->amount, 2, '.', '');
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return DateTimeStringValidator::parse($this->timestamp) ?? throw new \InvalidArgumentException('Invalid timestamp.');
    }
}
