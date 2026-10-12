<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Event;
use App\Entity\InboundRequest;
use App\Entity\Transaction;
use App\Enum\Account;
use App\Enum\Direction;
use App\Enum\EventType;
use App\Enum\Provider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');

        $event = new Event(Provider::Bank, 'evt_admin', EventType::FeeCharged, '12.50', 'EUR', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $event->attachInboundRequest(new InboundRequest(
            $event,
            '203.0.113.7',
            'acme-webhooks/2.1',
            'POST',
            'http://localhost/events/bank',
            'application/json',
            ['content-type' => 'application/json', 'x-request-id' => 'req-777'],
            '{"event_id":"evt_admin","type":"fee_charged","amount":12.5,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}',
            new \DateTimeImmutable('2026-01-01T00:00:05Z'),
        ));
        $event->getInboundRequest()->recordResponse(202, '');
        $event->markProcessed();
        $entityManager->persist($event);

        $rejected = new InboundRequest(
            null,
            '198.51.100.9',
            'cardgate/7.0',
            'POST',
            'http://localhost/events/card',
            'application/json',
            ['content-type' => 'application/json'],
            '{"transaction":"tx_bad","status":"refund"}',
            new \DateTimeImmutable('2026-01-01T00:01:00Z'),
        );
        $rejected->recordResponse(422, '{"error":"Validation failed.","violations":[{"field":"status","message":"Unknown status."}]}');
        $entityManager->persist($rejected);
        $entityManager->persist(new Transaction('evt_admin', Account::User, '12.50', 'EUR', Direction::Debit));
        $entityManager->persist(new Transaction('evt_admin', Account::Fee, '12.50', 'EUR', Direction::Credit));
        $entityManager->flush();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->login('test', 'wrong');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid credentials');
    }

    public function testLoginShowsTransactions(): void
    {
        $this->login('test', 'test');

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Transactions');
        self::assertSelectorTextContains('body', 'evt_admin');
        self::assertSelectorTextContains('body', 'user_account');
        self::assertSelectorTextContains('body', 'fee_account');

        $this->client->request('GET', '/admin/event');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'fee_charged');
        self::assertSelectorTextContains('body', 'processed');
        self::assertSelectorTextContains('body', 'bank');
    }

    public function testEventDetailShowsIntakeBlock(): void
    {
        $this->login('test', 'test');

        $this->client->request('GET', '/admin/event/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Intake');
        self::assertSelectorTextContains('body', 'Received at');
        self::assertSelectorTextContains('body', 'Processed at');
        self::assertSelectorTextContains('body', '203.0.113.7');
        self::assertSelectorTextContains('body', 'acme-webhooks/2.1');
        self::assertSelectorTextContains('body', 'x-request-id');
        self::assertSelectorTextContains('body', 'req-777');
        self::assertSelectorTextContains('pre', '"event_id": "evt_admin"');
    }

    public function testNotificationsListShowsAcceptedAndRejected(): void
    {
        $this->login('test', 'test');

        $this->client->request('GET', '/admin/notification');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Notifications');
        self::assertSelectorTextContains('body', '/events/bank');
        self::assertSelectorTextContains('body', '/events/card');
        self::assertSelectorTextContains('body', '202');
        self::assertSelectorTextContains('body', '422');
        self::assertSelectorTextContains('body', 'evt_admin');
        self::assertSelectorTextContains('body', '198.51.100.9');
    }

    public function testNotificationDetailShowsRequestAndResponse(): void
    {
        $this->login('test', 'test');

        $this->client->request('GET', '/admin/notification/2');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Request body');
        self::assertSelectorTextContains('body', 'Response body');
        self::assertSelectorTextContains('body', 'cardgate/7.0');
        self::assertSelectorTextContains('body', '"transaction": "tx_bad"');
        self::assertSelectorTextContains('body', '"message": "Unknown status."');
    }

    public function testWriteActionsAreDisabled(): void
    {
        $this->login('test', 'test');

        foreach (['/admin/transaction/new', '/admin/transaction/1/edit', '/admin/event/new', '/admin/event/1/edit', '/admin/notification/new', '/admin/notification/1/edit'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function testApiStaysPublic(): void
    {
        $this->client->request(
            'POST',
            '/events/bank',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"event_id":"evt_public","type":"payment_sent","amount":1,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}',
        );
        self::assertResponseStatusCodeSame(202);

        $this->client->request(
            'POST',
            '/events/card',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"transaction":"tx_public","status":"received","amount":150,"currency":"EUR","time":"2026-01-01 00:00:00"}',
        );
        self::assertResponseStatusCodeSame(202);

        $this->client->request('GET', '/accounts/user_account/balance');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/accounts/user_account/transactions');
        self::assertResponseStatusCodeSame(200);
    }

    private function login(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            '_username' => $username,
            '_password' => $password,
        ]);

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->submit($form);
    }
}
