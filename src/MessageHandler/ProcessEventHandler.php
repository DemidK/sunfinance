<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessEventMessage;
use App\Repository\EventRepository;
use App\Service\TransactionRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ProcessEventHandler
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly TransactionRecorder $recorder,
    ) {
    }

    public function __invoke(ProcessEventMessage $message): void
    {
        $event = $this->events->find($message->id);

        if (null === $event) {
            throw new UnrecoverableMessageHandlingException(sprintf('Event #%d not found.', $message->id));
        }

        $this->recorder->record($event);
    }
}
