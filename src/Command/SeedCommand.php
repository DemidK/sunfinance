<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\InboundRequest;
use App\Enum\EventType;
use App\Enum\Provider;
use App\Repository\EventRepository;
use App\Service\TransactionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed', description: 'Fill the database with mock events and transactions')]
final class SeedCommand extends Command
{
    private const ID_PREFIX = 'seed_';
    private const CURRENCIES = ['EUR', 'USD', 'GBP'];
    private const USER_AGENTS = ['acme-webhooks/2.1', 'curl/8.5.0', 'Mozilla/5.0 (compatible; PaymentsBot/1.0)'];
    private const PENDING_EVERY = 7;
    private const CARD_STATUSES = [EventType::PaymentReceived->value => 'received', EventType::PaymentSent->value => 'sent', EventType::FeeCharged->value => 'fee'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly TransactionRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of events to create', '30')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete all events, inbound requests and transactions first')
            ->addOption('seed', 's', InputOption::VALUE_REQUIRED, 'Random seed for reproducible data', '42');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = (int) $input->getOption('count');
        if ($count < 1) {
            $io->error('Count must be a positive integer.');

            return Command::INVALID;
        }

        if ($input->getOption('purge')) {
            $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');
            $this->entityManager->clear();
            $io->note('Existing events, inbound requests and transactions were deleted.');
        }

        mt_srand((int) $input->getOption('seed'));

        $created = 0;
        $processed = 0;
        $skipped = 0;
        $types = EventType::cases();
        $providers = Provider::cases();

        for ($i = 1; $i <= $count; ++$i) {
            $eventId = sprintf('%s%04d', self::ID_PREFIX, $i);
            if (null !== $this->events->findOneBy(['eventId' => $eventId])) {
                ++$skipped;
                continue;
            }

            $provider = $providers[mt_rand(0, count($providers) - 1)];
            $type = $types[mt_rand(0, count($types) - 1)];
            $amount = number_format(mt_rand(100, 250000) / 100, 2, '.', '');
            $currency = self::CURRENCIES[mt_rand(0, count(self::CURRENCIES) - 1)];
            $occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval(sprintf('PT%dM', mt_rand(0, 30 * 24 * 60))))
                ->setTime((int) date('H'), mt_rand(0, 59), mt_rand(0, 59));

            $event = new Event($provider, $eventId, $type, $amount, $currency, $occurredAt);
            $event->attachInboundRequest($this->inboundRequest($event, $i));

            $this->entityManager->persist($event);
            $this->entityManager->flush();
            ++$created;

            if (0 !== $i % self::PENDING_EVERY) {
                $this->recorder->record($event);
                ++$processed;
            }
        }

        $this->entityManager->clear();

        $io->success(sprintf('Created %d events (%d processed, %d pending), skipped %d existing.', $created, $processed, $created - $processed, $skipped));

        return Command::SUCCESS;
    }

    private function inboundRequest(Event $event, int $index): InboundRequest
    {
        $body = json_encode(match ($event->getProvider()) {
            Provider::Bank => [
                'event_id' => $event->getEventId(),
                'type' => $event->getType()->value,
                'amount' => (float) $event->getAmount(),
                'currency' => $event->getCurrency(),
                'timestamp' => $event->getOccurredAt()->format(\DATE_ATOM),
            ],
            Provider::Card => [
                'transaction' => $event->getEventId(),
                'status' => self::CARD_STATUSES[$event->getType()->value],
                'amount' => (int) bcmul($event->getAmount(), '100', 0),
                'currency' => $event->getCurrency(),
                'time' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            ],
        }, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $userAgent = self::USER_AGENTS[mt_rand(0, count(self::USER_AGENTS) - 1)];
        $remoteIp = sprintf('203.0.113.%d', mt_rand(1, 254));

        $request = new InboundRequest(
            $event,
            $remoteIp,
            $userAgent,
            'POST',
            'http://localhost:8080/events/'.$event->getProvider()->value,
            'application/json',
            [
                'accept' => '*/*',
                'authorization' => '[redacted]',
                'content-length' => (string) strlen($body),
                'content-type' => 'application/json',
                'host' => 'localhost:8080',
                'user-agent' => $userAgent,
                'x-request-id' => sprintf('req-%06d', $index),
            ],
            $body,
            $event->getOccurredAt()->add(new \DateInterval(sprintf('PT%dS', mt_rand(1, 120)))),
        );
        $request->recordResponse(202, '');

        return $request;
    }
}
