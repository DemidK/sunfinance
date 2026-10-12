<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\MessageHandler\ProcessEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $queue = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');
    }

    public function testNotificationsFromBothProvidersEndUpInBalancesAndStatements(): void
    {
        $this->post('/events/bank', '{"event_id":"evt_b1","type":"payment_received","amount":100.00,"currency":"EUR","timestamp":"2026-01-01T10:00:00Z"}');
        $this->post('/events/bank', '{"event_id":"evt_b2","type":"payment_sent","amount":40.5,"currency":"EUR","timestamp":"2026-01-01T11:00:00Z"}');
        $this->post('/events/bank', '{"event_id":"evt_b3","type":"fee_charged","amount":7.25,"currency":"EUR","timestamp":"2026-01-01T11:00:01Z"}');
        $this->post('/events/card', '{"transaction":"1z12f32asddas3sdf4","status":"received","amount":10000,"currency":"EUR","time":"2026-01-02 09:15:00"}');
        $this->post('/events/card', '{"transaction":"8k0d9s8f7g6h5j4k3l","status":"sent","amount":2000,"currency":"USD","time":"2026-01-02 09:20:00"}');
        $this->post('/events/card', '{"transaction":"1z12f32asddas3sdf4","status":"received","amount":10000,"currency":"EUR","time":"2026-01-02 09:15:00"}');

        self::assertCount(6, $this->queue);
        $this->runWorker();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $statuses = array_map(static fn (Event $event): string => $event->getStatus()->value, $entityManager->getRepository(Event::class)->findBy([], ['id' => 'ASC']));
        self::assertSame(['processed', 'processed', 'processed', 'processed', 'processed', 'duplicate'], $statuses);

        self::assertSame([
            'account' => 'user_account',
            'balances' => [
                ['currency' => 'EUR', 'debit' => '207.25', 'credit' => '40.50', 'balance' => '166.75'],
                ['currency' => 'USD', 'debit' => '0.00', 'credit' => '20.00', 'balance' => '-20.00'],
            ],
        ], $this->get('/accounts/user_account/balance'));

        self::assertSame([
            ['currency' => 'EUR', 'debit' => '40.50', 'credit' => '200.00', 'balance' => '-159.50'],
            ['currency' => 'USD', 'debit' => '20.00', 'credit' => '0.00', 'balance' => '20.00'],
        ], $this->get('/accounts/system_cash_account/balance')['balances']);

        self::assertSame([
            ['currency' => 'EUR', 'debit' => '0.00', 'credit' => '7.25', 'balance' => '-7.25'],
        ], $this->get('/accounts/fee_account/balance')['balances']);

        $statement = $this->get('/accounts/user_account/transactions');
        self::assertSame(5, $statement['total']);
        self::assertSame(['8k0d9s8f7g6h5j4k3l', '1z12f32asddas3sdf4', 'evt_b3', 'evt_b2', 'evt_b1'], array_column($statement['items'], 'event_id'));
        self::assertSame(['credit', 'debit', 'debit', 'credit', 'debit'], array_column($statement['items'], 'direction'));
        self::assertSame(['20.00', '100.00', '7.25', '40.50', '100.00'], array_column($statement['items'], 'amount'));

        $page = $this->get('/accounts/user_account/transactions?limit=2&offset=2&currency=eur');
        self::assertSame(4, $page['total']);
        self::assertSame(['evt_b2', 'evt_b1'], array_column($page['items'], 'event_id'));

        $debits = $this->get('/accounts/system_cash_account/transactions?direction=debit');
        self::assertSame(2, $debits['total']);
        self::assertSame(['8k0d9s8f7g6h5j4k3l', 'evt_b2'], array_column($debits['items'], 'event_id'));

        self::assertSame(1, $this->get('/accounts/fee_account/transactions')['total']);
    }

    public function testRejectedNotificationsLeaveNoTrace(): void
    {
        $this->post('/events/bank', '{"event_id":"evt_bad","type":"payment_received","amount":0,"currency":"EUR","timestamp":"2026-01-01T10:00:00Z"}');
        self::assertResponseStatusCodeSame(422);
        $this->post('/events/card', 'not json');
        self::assertResponseStatusCodeSame(400);
        $this->post('/events/wallet', '{}');
        self::assertResponseStatusCodeSame(404);

        self::assertCount(0, $this->queue);
        $this->runWorker();

        foreach (['user_account', 'system_cash_account', 'fee_account'] as $account) {
            self::assertSame([], $this->get(sprintf('/accounts/%s/balance', $account))['balances']);
            self::assertSame(0, $this->get(sprintf('/accounts/%s/transactions', $account))['total']);
        }
    }

    private function post(string $path, string $body): void
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'provider-webhooks/1.0',
            'HTTP_X_REQUEST_ID' => bin2hex(random_bytes(6)),
        ], content: $body);

        foreach (static::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            $this->queue[] = $envelope->getMessage();
        }
    }

    private function runWorker(): void
    {
        $handler = static::getContainer()->get(ProcessEventHandler::class);
        foreach ($this->queue as $message) {
            $handler($message);
        }
        $this->queue = [];
    }

    private function get(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200, $path);
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
