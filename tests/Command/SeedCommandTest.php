<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Event;
use App\Entity\Transaction;
use App\Enum\EventStatus;
use App\Enum\Provider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:seed'));
    }

    public function testSeedCreatesEventsWithPostingsAndIntake(): void
    {
        $this->command->execute(['--count' => '14']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('Created 14 events (12 processed, 2 pending), skipped 0 existing.', $this->command->getDisplay());

        $events = $this->entityManager->getRepository(Event::class)->findAll();
        self::assertCount(14, $events);

        $pending = 0;
        $providers = [];
        foreach ($events as $event) {
            self::assertNotNull($event->getInboundRequest());
            $body = json_decode($event->getInboundRequest()->getBody(), true);
            $key = Provider::Card === $event->getProvider() ? 'transaction' : 'event_id';
            self::assertSame($event->getEventId(), $body[$key]);
            self::assertStringEndsWith('/events/'.$event->getProvider()->value, $event->getInboundRequest()->getUri());
            $providers[$event->getProvider()->value] = true;
            if (EventStatus::Pending === $event->getStatus()) {
                ++$pending;
                continue;
            }
            self::assertNotNull($event->getProcessedAt());
            self::assertCount(2, $this->entityManager->getRepository(Transaction::class)->findBy(['eventId' => $event->getEventId()]));
        }
        self::assertSame(2, $pending);
        self::assertSame(['bank' => true, 'card' => true], array_intersect_key($providers, ['bank' => 0, 'card' => 0]));
        self::assertCount(24, $this->entityManager->getRepository(Transaction::class)->findAll());
    }

    public function testSeedIsIdempotentAndPurgeResets(): void
    {
        $this->command->execute(['--count' => '5']);
        $this->command->execute(['--count' => '8']);

        self::assertStringContainsString('Created 3 events', $this->command->getDisplay());
        self::assertCount(8, $this->entityManager->getRepository(Event::class)->findAll());

        $this->command->execute(['--count' => '3', '--purge' => true]);

        self::assertStringContainsString('Created 3 events', $this->command->getDisplay());
        self::assertCount(3, $this->entityManager->getRepository(Event::class)->findAll());
    }

    public function testRejectsNonPositiveCount(): void
    {
        $status = $this->command->execute(['--count' => '0']);

        self::assertSame(2, $status);
        self::assertCount(0, $this->entityManager->getRepository(Event::class)->findAll());
    }
}
