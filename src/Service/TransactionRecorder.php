<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Enum\EventStatus;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class TransactionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRepository $transactions,
    ) {
    }

    public function record(Event $event): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event): void {
            $this->entityManager->lock($event, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($event);

            if (EventStatus::Pending !== $event->getStatus()) {
                return;
            }

            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                [$event->getEventId()],
            );

            if ($this->transactions->existsForEvent($event->getEventId())) {
                $event->markDuplicate();

                return;
            }

            $type = $event->getType();

            $this->entityManager->persist(new Transaction(
                $event->getEventId(),
                $type->debitAccount(),
                $event->getAmount(),
                $event->getCurrency(),
                Direction::Debit,
            ));
            $this->entityManager->persist(new Transaction(
                $event->getEventId(),
                $type->creditAccount(),
                $event->getAmount(),
                $event->getCurrency(),
                Direction::Credit,
            ));

            $event->markProcessed();
        });
    }
}
