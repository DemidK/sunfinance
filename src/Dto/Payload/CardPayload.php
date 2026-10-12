<?php

declare(strict_types=1);

namespace App\Dto\Payload;

use App\Enum\EventType;
use App\Validator\DateTimeString;
use App\Validator\DateTimeStringValidator;
use Symfony\Component\Validator\Constraints as Assert;

final class CardPayload implements InboundPayload
{
    private const STATUSES = [
        'received' => EventType::PaymentReceived,
        'sent' => EventType::PaymentSent,
        'fee' => EventType::FeeCharged,
    ];

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public mixed $transaction = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Choice(callback: 'statuses', message: 'Unknown status.')]
    public mixed $status = null;

    #[Assert\NotNull]
    #[Assert\Type('integer')]
    #[Assert\Positive]
    public mixed $amount = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 3, max: 3)]
    public mixed $currency = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[DateTimeString]
    public mixed $time = null;

    public static function fromArray(array $data): static
    {
        $payload = new self();
        $payload->transaction = $data['transaction'] ?? null;
        $payload->status = $data['status'] ?? null;
        $payload->amount = $data['amount'] ?? null;
        $payload->currency = $data['currency'] ?? null;
        $payload->time = $data['time'] ?? null;

        return $payload;
    }

    public static function statuses(): array
    {
        return array_keys(self::STATUSES);
    }

    public function eventId(): string
    {
        return $this->transaction;
    }

    public function eventType(): EventType
    {
        return self::STATUSES[$this->status];
    }

    public function normalizedAmount(): string
    {
        return bcdiv((string) $this->amount, '100', 2);
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return DateTimeStringValidator::parse($this->time) ?? throw new \InvalidArgumentException('Invalid time.');
    }
}
