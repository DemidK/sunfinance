<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Dto\RequestContext;
use App\Entity\InboundRequest;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class InboundRequestRecorder implements EventSubscriberInterface
{
    public const CONTEXT_ATTRIBUTE = '_inbound_context';
    public const RECORD_ATTRIBUTE = '_inbound_request';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 512],
            KernelEvents::RESPONSE => ['onResponse', -512],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && self::supports($request)) {
            $request->attributes->set(self::CONTEXT_ATTRIBUTE, RequestContext::fromRequest($request));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);
        if (!$event->isMainRequest() || !$context instanceof RequestContext) {
            return;
        }

        $response = $event->getResponse();

        try {
            $entityManager = $this->doctrine->getManager();
            if (!$entityManager->isOpen()) {
                $entityManager = $this->doctrine->resetManager();
            }

            $record = $request->attributes->get(self::RECORD_ATTRIBUTE);
            if (!$record instanceof InboundRequest || !$entityManager->contains($record)) {
                $record = InboundRequest::fromContext(null, $context);
                $entityManager->persist($record);
            }

            $record->recordResponse($response->getStatusCode(), (string) $response->getContent());
            $entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to record inbound request.', ['exception' => $e, 'uri' => $context->uri]);
        }
    }

    private static function supports(Request $request): bool
    {
        $path = $request->getPathInfo();

        return '/events' === $path || str_starts_with($path, '/events/');
    }
}
