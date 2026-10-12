<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\InvalidJsonException;
use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener(event: ExceptionEvent::class)]
final class ExceptionListener
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/admin')) {
            return;
        }

        $exception = $event->getThrowable();

        [$status, $body] = match (true) {
            $exception instanceof InvalidJsonException => [Response::HTTP_BAD_REQUEST, ['error' => $exception->getMessage()]],
            $exception instanceof ValidationException => [Response::HTTP_UNPROCESSABLE_ENTITY, ['error' => $exception->getMessage(), 'violations' => $exception->getViolations()]],
            $exception instanceof PersistenceException => [Response::HTTP_INTERNAL_SERVER_ERROR, ['error' => $exception->getMessage()]],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), ['error' => $exception->getMessage() ?: Response::$statusTexts[$exception->getStatusCode()] ?? 'Error']],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, ['error' => 'Internal server error.']],
        };

        if ($status >= 500) {
            $this->logger?->error($exception->getMessage(), ['exception' => $exception]);
        }

        $response = new JsonResponse($body, $status);
        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }

        $event->setResponse($response);
    }
}
