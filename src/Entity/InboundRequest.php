<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\RequestContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inbound_requests')]
#[ORM\Index(name: 'idx_inbound_requests_received_at', columns: ['received_at'])]
#[ORM\Index(name: 'idx_inbound_requests_response_status', columns: ['response_status'])]
class InboundRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'inboundRequest')]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: true, unique: true, onDelete: 'CASCADE')]
    private ?Event $event;

    #[ORM\Column(name: 'remote_ip', length: 45, nullable: true)]
    private ?string $remoteIp;

    #[ORM\Column(name: 'user_agent', type: Types::TEXT, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(length: 10)]
    private string $method;

    #[ORM\Column(type: Types::TEXT)]
    private string $uri;

    #[ORM\Column(name: 'content_type', length: 255, nullable: true)]
    private ?string $contentType;

    #[ORM\Column(type: Types::JSON)]
    private array $headers;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'received_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'response_status', type: Types::SMALLINT, nullable: true)]
    private ?int $responseStatus = null;

    #[ORM\Column(name: 'response_body', type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    public function __construct(
        ?Event $event,
        ?string $remoteIp,
        ?string $userAgent,
        string $method,
        string $uri,
        ?string $contentType,
        array $headers,
        string $body,
        \DateTimeImmutable $receivedAt,
    ) {
        $this->event = $event;
        $this->remoteIp = $remoteIp;
        $this->userAgent = $userAgent;
        $this->method = $method;
        $this->uri = $uri;
        $this->contentType = $contentType;
        $this->headers = $headers;
        $this->body = $body;
        $this->receivedAt = $receivedAt;
    }

    public static function fromContext(?Event $event, RequestContext $context): self
    {
        return new self(
            $event,
            $context->remoteIp,
            $context->userAgent,
            $context->method,
            $context->uri,
            $context->contentType,
            $context->headers,
            $context->body,
            $context->receivedAt,
        );
    }

    public function recordResponse(int $status, string $body): void
    {
        $this->responseStatus = $status;
        $this->responseBody = $body;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getRemoteIp(): ?string
    {
        return $this->remoteIp;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getPath(): string
    {
        return parse_url($this->uri, \PHP_URL_PATH) ?: $this->uri;
    }

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
