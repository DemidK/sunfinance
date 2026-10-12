<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Entity\Transaction;
use App\Enum\Account;
use App\Enum\Direction;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\Provider;
use App\Message\ProcessEventMessage;
use App\MessageHandler\ProcessEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessEventHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ProcessEventHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(ProcessEventHandler::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');
    }

    public static function eventTypes(): iterable
    {
        yield 'payment_received' => [EventType::PaymentReceived, Account::User, Account::SystemCash];
        yield 'payment_sent' => [EventType::PaymentSent, Account::SystemCash, Account::User];
        yield 'fee_charged' => [EventType::FeeCharged, Account::User, Account::Fee];
    }

    #[DataProvider('eventTypes')]
    public function testProcessingCreatesDoubleEntry(EventType $type, Account $debit, Account $credit): void
    {
        $event = $this->storeEvent($type);

        ($this->handler)(new ProcessEventMessage($event->getId()));

        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(Event::class)->findOneBy(['eventId' => 'evt_123']);
        self::assertSame(EventStatus::Processed, $stored->getStatus());
        self::assertNotNull($stored->getProcessedAt());

        $transactions = $this->entityManager->getRepository(Transaction::class)->findBy(['eventId' => 'evt_123'], ['id' => 'ASC']);
        self::assertCount(2, $transactions);

        self::assertSame($debit, $transactions[0]->getAccount());
        self::assertSame(Direction::Debit, $transactions[0]->getDirection());
        self::assertSame($credit, $transactions[1]->getAccount());
        self::assertSame(Direction::Credit, $transactions[1]->getDirection());

        foreach ($transactions as $transaction) {
            self::assertSame('100.00', $transaction->getAmount());
            self::assertSame('EUR', $transaction->getCurrency());
        }
    }

    public function testRedeliveredMessageDoesNotDuplicateTransactions(): void
    {
        $event = $this->storeEvent(EventType::PaymentReceived);
        $message = new ProcessEventMessage($event->getId());

        ($this->handler)($message);
        ($this->handler)($message);

        self::assertCount(2, $this->entityManager->getRepository(Transaction::class)->findAll());
    }

    public function testRepeatedEventIdIsMarkedDuplicateWithoutPostings(): void
    {
        $first = $this->storeEvent(EventType::PaymentReceived);
        $second = $this->storeEvent(EventType::PaymentSent, 'evt_123');

        ($this->handler)(new ProcessEventMessage($first->getId()));
        ($this->handler)(new ProcessEventMessage($second->getId()));

        $this->entityManager->clear();

        self::assertSame(EventStatus::Processed, $this->entityManager->find(Event::class, $first->getId())->getStatus());

        $duplicate = $this->entityManager->find(Event::class, $second->getId());
        self::assertSame(EventStatus::Duplicate, $duplicate->getStatus());
        self::assertNotNull($duplicate->getProcessedAt());

        $transactions = $this->entityManager->getRepository(Transaction::class)->findBy(['eventId' => 'evt_123'], ['id' => 'ASC']);
        self::assertCount(2, $transactions);
        self::assertSame([Account::User, Account::SystemCash], array_map(static fn (Transaction $t): Account => $t->getAccount(), $transactions));
    }

    public function testUnknownEventIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler)(new ProcessEventMessage(999999));
    }

    private function storeEvent(EventType $type, string $eventId = 'evt_123'): Event
    {
        $event = new Event(Provider::Bank, $eventId, $type, '100.00', 'EUR', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }
}
