<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\Provider;
use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_event_id', columns: ['event_id'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: Provider::class)]
    private Provider $provider;

    #[ORM\Column(name: 'event_id', length: 255)]
    private string $eventId;

    #[ORM\Column(length: 32, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 16, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\OneToOne(mappedBy: 'event', targetEntity: InboundRequest::class, cascade: ['persist', 'remove'])]
    private ?InboundRequest $inboundRequest = null;

    public function __construct(
        Provider $provider,
        string $eventId,
        EventType $type,
        string $amount,
        string $currency,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->provider = $provider;
        $this->eventId = $eventId;
        $this->type = $type;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function isProcessed(): bool
    {
        return EventStatus::Processed === $this->status;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getInboundRequest(): ?InboundRequest
    {
        return $this->inboundRequest;
    }

    public function attachInboundRequest(InboundRequest $inboundRequest): void
    {
        $this->inboundRequest = $inboundRequest;
    }

    public function markProcessed(): void
    {
        $this->status = EventStatus::Processed;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markDuplicate(): void
    {
        $this->status = EventStatus::Duplicate;
        $this->processedAt = new \DateTimeImmutable();
    }
}
