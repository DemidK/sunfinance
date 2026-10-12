<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RequestContext;
use App\Enum\Provider;
use App\EventListener\InboundRequestRecorder;
use App\Exception\InvalidJsonException;
use App\Exception\ValidationException;
use App\Service\EventProcessor;
use App\Validator\ViolationFormatter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EventController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly EventProcessor $processor,
    ) {
    }

    #[Route('/events/{provider}', name: 'app_events_create', methods: ['POST'])]
    public function create(string $provider, Request $request): Response
    {
        $source = Provider::tryFrom($provider) ?? throw new NotFoundHttpException(sprintf('Unknown provider "%s".', $provider));

        $payload = $source->payload($this->decode($request));

        $violations = $this->validator->validate($payload);
        if (count($violations) > 0) {
            throw new ValidationException(ViolationFormatter::toArray($violations));
        }

        $context = $request->attributes->get(InboundRequestRecorder::CONTEXT_ATTRIBUTE) ?? RequestContext::fromRequest($request);
        $event = $this->processor->accept($source, $payload, $context);
        $request->attributes->set(InboundRequestRecorder::RECORD_ATTRIBUTE, $event->getInboundRequest());

        return new Response(status: Response::HTTP_ACCEPTED);
    }

    private function decode(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidJsonException();
        }

        if (!is_array($data)) {
            throw new InvalidJsonException();
        }

        return $data;
    }
}
