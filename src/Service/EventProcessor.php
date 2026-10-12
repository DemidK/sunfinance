<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Payload\InboundPayload;
use App\Dto\RequestContext;
use App\Entity\Event;
use App\Entity\InboundRequest;
use App\Enum\Provider;
use App\Exception\PersistenceException;
use App\Message\ProcessEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class EventProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function accept(Provider $provider, InboundPayload $payload, RequestContext $context): Event
    {
        $event = new Event(
            $provider,
            $payload->eventId(),
            $payload->eventType(),
            $payload->normalizedAmount(),
            $payload->currency(),
            $payload->occurredAt(),
        );
        $event->attachInboundRequest(InboundRequest::fromContext($event, $context));

        try {
            $this->entityManager->wrapInTransaction(function () use ($event): void {
                $this->entityManager->persist($event);
                $this->entityManager->flush();
                $this->bus->dispatch(new ProcessEventMessage($event->getId()));
            });
        } catch (\Throwable $e) {
            throw new PersistenceException($e);
        }

        return $event;
    }
}
