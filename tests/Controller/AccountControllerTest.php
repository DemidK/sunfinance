<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Transaction;
use App\Enum\Account;
use App\Enum\Direction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');

        $rows = [
            ['evt_1', Account::User, '100.00', 'EUR', Direction::Debit],
            ['evt_1', Account::SystemCash, '100.00', 'EUR', Direction::Credit],
            ['evt_2', Account::SystemCash, '40.50', 'EUR', Direction::Debit],
            ['evt_2', Account::User, '40.50', 'EUR', Direction::Credit],
            ['evt_3', Account::User, '7.25', 'EUR', Direction::Debit],
            ['evt_3', Account::Fee, '7.25', 'EUR', Direction::Credit],
            ['evt_4', Account::User, '20.00', 'USD', Direction::Debit],
            ['evt_4', Account::SystemCash, '20.00', 'USD', Direction::Credit],
        ];
        foreach ($rows as [$eventId, $account, $amount, $currency, $direction]) {
            $entityManager->persist(new Transaction($eventId, $account, $amount, $currency, $direction));
        }
        $entityManager->flush();
    }

    public function testBalanceIsGroupedByCurrency(): void
    {
        $body = $this->getJson('/accounts/user_account/balance');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'account' => 'user_account',
            'balances' => [
                ['currency' => 'EUR', 'debit' => '107.25', 'credit' => '40.50', 'balance' => '66.75'],
                ['currency' => 'USD', 'debit' => '20.00', 'credit' => '0.00', 'balance' => '20.00'],
            ],
        ], $body);
    }

    public function testBalanceCanBeNegative(): void
    {
        $body = $this->getJson('/accounts/fee_account/balance');

        self::assertSame([['currency' => 'EUR', 'debit' => '0.00', 'credit' => '7.25', 'balance' => '-7.25']], $body['balances']);
    }

    public function testBalanceOfAccountWithoutTransactionsIsEmpty(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('TRUNCATE TABLE transactions RESTART IDENTITY');

        $body = $this->getJson('/accounts/fee_account/balance');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['account' => 'fee_account', 'balances' => []], $body);
    }

    public function testUnknownAccountReturnsNotFound(): void
    {
        $body = $this->getJson('/accounts/nope/balance');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Unknown account "nope".', $body['error']);

        $this->getJson('/accounts/nope/transactions');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTransactionsAreListedNewestFirst(): void
    {
        $body = $this->getJson('/accounts/user_account/transactions');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('user_account', $body['account']);
        self::assertSame(4, $body['total']);
        self::assertSame(50, $body['limit']);
        self::assertSame(0, $body['offset']);
        self::assertSame(['evt_4', 'evt_3', 'evt_2', 'evt_1'], array_column($body['items'], 'event_id'));

        $item = $body['items'][0];
        self::assertSame(7, $item['id']);
        self::assertSame('debit', $item['direction']);
        self::assertSame('20.00', $item['amount']);
        self::assertSame('USD', $item['currency']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $item['created_at']);
    }

    public function testTransactionsSupportPaginationAndFilters(): void
    {
        $body = $this->getJson('/accounts/user_account/transactions?limit=2&offset=1');
        self::assertSame(4, $body['total']);
        self::assertSame(2, $body['limit']);
        self::assertSame(1, $body['offset']);
        self::assertSame(['evt_3', 'evt_2'], array_column($body['items'], 'event_id'));

        $body = $this->getJson('/accounts/user_account/transactions?currency=eur&direction=credit');
        self::assertSame(1, $body['total']);
        self::assertSame(['evt_2'], array_column($body['items'], 'event_id'));
    }

    public function testInvalidQueryReturnsUnprocessableEntity(): void
    {
        $body = $this->getJson('/accounts/user_account/transactions?limit=0&offset=-1&currency=EURO&direction=sideways');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed.', $body['error']);
        self::assertSame(['limit', 'offset', 'currency', 'direction'], array_values(array_unique(array_column($body['violations'], 'field'))));

        $this->getJson('/accounts/user_account/transactions?limit=201');
        self::assertResponseStatusCodeSame(422);

        $this->getJson('/accounts/user_account/transactions?limit=abc');
        self::assertResponseStatusCodeSame(422);
    }

    private function getJson(string $uri): array
    {
        $this->client->request('GET', $uri);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
