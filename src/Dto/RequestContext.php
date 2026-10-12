<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final readonly class RequestContext
{
    private const REDACTED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'x-api-key'];

    public function __construct(
        public ?string $remoteIp,
        public ?string $userAgent,
        public string $method,
        public string $uri,
        public ?string $contentType,
        public array $headers,
        public string $body,
        public \DateTimeImmutable $receivedAt,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = in_array($name, self::REDACTED_HEADERS, true) ? '[redacted]' : implode(', ', $values);
        }
        ksort($headers);

        return new self(
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            $request->getMethod(),
            $request->getUri(),
            $request->headers->get('Content-Type'),
            $headers,
            $request->getContent(),
            new \DateTimeImmutable(),
        );
    }
}
