<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\InboundRequest;
use App\Entity\Transaction;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\Provider;
use App\Message\ProcessEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerTest extends WebTestCase
{
    private const BANK_BODY = '{"event_id":"evt_123","type":"payment_received","amount":100.00,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}';
    private const CARD_BODY = '{"transaction":"1z12f32asddas3sdf4","status":"received","amount":10000,"currency":"EUR","time":"2026-01-01 00:00:00"}';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE transactions, inbound_requests, events RESTART IDENTITY');
    }

    public function testBankNotificationIsStoredAndQueued(): void
    {
        $this->post('/events/bank', self::BANK_BODY, ['HTTP_USER_AGENT' => 'acme-bank-webhooks/2.1']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());

        $event = $this->entityManager->getRepository(Event::class)->findOneBy(['eventId' => 'evt_123']);
        self::assertNotNull($event);
        self::assertSame(Provider::Bank, $event->getProvider());
        self::assertSame(EventType::PaymentReceived, $event->getType());
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertSame('100.00', $event->getAmount());
        self::assertSame('EUR', $event->getCurrency());
        self::assertSame('2026-01-01T00:00:00+00:00', $event->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));

        $inbound = $event->getInboundRequest();
        self::assertNotNull($inbound);
        self::assertSame('127.0.0.1', $inbound->getRemoteIp());
        self::assertSame('acme-bank-webhooks/2.1', $inbound->getUserAgent());
        self::assertSame('POST', $inbound->getMethod());
        self::assertSame('http://localhost/events/bank', $inbound->getUri());
        self::assertSame('application/json', $inbound->getContentType());
        self::assertSame('[redacted]', $inbound->getHeaders()['authorization']);
        self::assertSame('req-777', $inbound->getHeaders()['x-request-id']);
        self::assertSame(self::BANK_BODY, $inbound->getBody());

        $queued = $this->sentMessages();
        self::assertCount(1, $queued);
        self::assertInstanceOf(ProcessEventMessage::class, $queued[0]);
        self::assertSame($event->getId(), $queued[0]->id);

        self::assertCount(0, $this->entityManager->getRepository(Transaction::class)->findAll());
    }

    public function testCardNotificationIsConvertedFromCentsAndQueued(): void
    {
        $this->post('/events/card', self::CARD_BODY, ['HTTP_USER_AGENT' => 'cardgate/7.0', 'HTTP_X_SIGNATURE' => 'sha256=abc']);

        self::assertResponseStatusCodeSame(202);

        $event = $this->entityManager->getRepository(Event::class)->findOneBy(['eventId' => '1z12f32asddas3sdf4']);
        self::assertNotNull($event);
        self::assertSame(Provider::Card, $event->getProvider());
        self::assertSame(EventType::PaymentReceived, $event->getType());
        self::assertSame(EventStatus::Pending, $event->getStatus());
        self::assertSame('100.00', $event->getAmount());
        self::assertSame('EUR', $event->getCurrency());
        self::assertSame('2026-01-01T00:00:00+00:00', $event->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));

        $inbound = $event->getInboundRequest();
        self::assertSame('http://localhost/events/card', $inbound->getUri());
        self::assertSame('cardgate/7.0', $inbound->getUserAgent());
        self::assertSame('sha256=abc', $inbound->getHeaders()['x-signature']);
        self::assertSame(self::CARD_BODY, $inbound->getBody());

        $queued = $this->sentMessages();
        self::assertCount(1, $queued);
        self::assertSame($event->getId(), $queued[0]->id);
    }

    public function testCardStatusesMapToEventTypes(): void
    {
        $this->post('/events/card', '{"transaction":"tx_sent","status":"sent","amount":2550,"currency":"usd","time":"2026-02-01 12:30:00"}');
        self::assertResponseStatusCodeSame(202);
        $this->post('/events/card', '{"transaction":"tx_fee","status":"fee","amount":99,"currency":"EUR","time":"2026-02-01 12:30:05"}');
        self::assertResponseStatusCodeSame(202);

        $sent = $this->entityManager->getRepository(Event::class)->findOneBy(['eventId' => 'tx_sent']);
        self::assertSame(EventType::PaymentSent, $sent->getType());
        self::assertSame('25.50', $sent->getAmount());
        self::assertSame('USD', $sent->getCurrency());

        $fee = $this->entityManager->getRepository(Event::class)->findOneBy(['eventId' => 'tx_fee']);
        self::assertSame(EventType::FeeCharged, $fee->getType());
        self::assertSame('0.99', $fee->getAmount());
    }

    public function testRepeatedEventIdIsStoredAgainAndQueued(): void
    {
        $this->post('/events/bank', self::BANK_BODY);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $this->sentMessages());

        $this->post('/events/bank', '{"event_id":"evt_123","type":"payment_sent","amount":5,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}');
        self::assertResponseStatusCodeSame(202);

        $events = $this->entityManager->getRepository(Event::class)->findBy(['eventId' => 'evt_123'], ['id' => 'ASC']);
        self::assertCount(2, $events);
        self::assertSame(EventType::PaymentReceived, $events[0]->getType());
        self::assertSame(EventType::PaymentSent, $events[1]->getType());
        self::assertSame('5.00', $events[1]->getAmount());
        self::assertSame(EventStatus::Pending, $events[1]->getStatus());

        $queued = $this->sentMessages();
        self::assertCount(1, $queued);
        self::assertSame($events[1]->getId(), $queued[0]->id);
    }

    public function testInvalidBankPayloadReturnsUnprocessableEntity(): void
    {
        $this->post('/events/bank', '{"type":"payment_received","amount":-10,"currency":"","timestamp":"x"}');

        self::assertResponseStatusCodeSame(422);

        $body = $this->json();
        self::assertSame('Validation failed.', $body['error']);
        self::assertSame(['event_id', 'amount', 'currency', 'timestamp'], array_values(array_unique(array_column($body['violations'], 'field'))));

        self::assertCount(0, $this->entityManager->getRepository(Event::class)->findAll());
        self::assertCount(0, $this->sentMessages());
    }

    public function testInvalidCardPayloadReturnsUnprocessableEntity(): void
    {
        $this->post('/events/card', '{"status":"refunded","amount":"10000","currency":"EURO","time":"01/01/2026"}');

        self::assertResponseStatusCodeSame(422);

        $body = $this->json();
        self::assertSame('Validation failed.', $body['error']);
        self::assertSame(['transaction', 'status', 'amount', 'currency', 'time'], array_values(array_unique(array_column($body['violations'], 'field'))));
        self::assertContains(['field' => 'status', 'message' => 'Unknown status.'], $body['violations']);

        $this->post('/events/card', '{"transaction":"tx_1","status":"received","amount":100.5,"currency":"EUR","time":"2026-01-01 00:00:00"}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['amount'], array_column($this->json()['violations'], 'field'));

        self::assertCount(0, $this->entityManager->getRepository(Event::class)->findAll());
    }

    public function testBankPayloadSentToCardEndpointIsRejected(): void
    {
        $this->post('/events/card', self::BANK_BODY);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['transaction', 'status', 'amount', 'time'], array_values(array_unique(array_column($this->json()['violations'], 'field'))));
    }

    public function testUnknownTypeReturnsUnprocessableEntity(): void
    {
        $this->post('/events/bank', '{"event_id":"evt_1","type":"refund_issued","amount":1,"currency":"EUR","timestamp":"2026-01-01T00:00:00Z"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'type', 'message' => 'Unknown event type.']], $this->json()['violations']);
        self::assertCount(0, $this->sentMessages());
    }

    public function testInvalidJsonReturnsBadRequest(): void
    {
        foreach (['/events/bank', '/events/card'] as $path) {
            $this->post($path, '{"event_id": "evt_1", ');

            self::assertResponseStatusCodeSame(400, $path);
            self::assertSame('Request body is not valid JSON.', $this->json()['error']);
        }

        $this->post('/events/bank', '"just a string"');
        self::assertResponseStatusCodeSame(400);

        self::assertCount(0, $this->sentMessages());
    }

    public function testUnknownProviderReturnsNotFound(): void
    {
        $this->post('/events/crypto', self::BANK_BODY);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Unknown provider "crypto".', $this->json()['error']);

        $this->post('/events', self::BANK_BODY);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->client->request('GET', '/events/bank');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAcceptedNotificationRecordsResponse(): void
    {
        $this->post('/events/bank', self::BANK_BODY);
        self::assertResponseStatusCodeSame(202);

        $records = $this->inboundRequests();
        self::assertCount(1, $records);
        self::assertSame(202, $records[0]->getResponseStatus());
        self::assertSame('', $records[0]->getResponseBody());
        self::assertSame('evt_123', $records[0]->getEvent()?->getEventId());
    }

    public function testRejectedNotificationsAreRecordedWithResponse(): void
    {
        $this->post('/events/bank', '{"event_id": ');
        $this->post('/events/card', '{"transaction":"tx_1"}');
        $this->post('/events/crypto', self::BANK_BODY);
        $this->client->request('GET', '/events/bank');

        $records = $this->inboundRequests();
        self::assertSame([400, 422, 404, 405], array_map(static fn (InboundRequest $r): ?int => $r->getResponseStatus(), $records));
        self::assertSame(['POST', 'POST', 'POST', 'GET'], array_map(static fn (InboundRequest $r): string => $r->getMethod(), $records));

        foreach ($records as $record) {
            self::assertNull($record->getEvent());
        }

        self::assertSame('{"event_id": ', $records[0]->getBody());
        self::assertSame('Request body is not valid JSON.', json_decode($records[0]->getResponseBody(), true)['error']);
        self::assertSame('Validation failed.', json_decode($records[1]->getResponseBody(), true)['error']);
        self::assertSame('/events/crypto', $records[2]->getPath());
        self::assertSame('[redacted]', $records[0]->getHeaders()['authorization']);
        self::assertCount(0, $this->entityManager->getRepository(Event::class)->findAll());
    }

    public function testAccountEndpointsAreNotRecorded(): void
    {
        $this->client->request('GET', '/accounts/user_account/balance');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $this->inboundRequests());
    }

    private function inboundRequests(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(InboundRequest::class)->findBy([], ['id' => 'ASC']);
    }

    private function post(string $path, string $body, array $extraServer = []): void
    {
        $this->client->request(
            'POST',
            $path,
            server: array_replace([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'acme-webhooks/2.1',
                'HTTP_AUTHORIZATION' => 'Bearer secret',
                'HTTP_X_REQUEST_ID' => 'req-777',
            ], $extraServer),
            content: $body,
        );
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sentMessages(): array
    {
        return array_map(static fn ($envelope): object => $envelope->getMessage(), static::getContainer()->get('messenger.transport.async')->getSent());
    }
}
