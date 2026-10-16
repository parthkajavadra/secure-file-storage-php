<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns HTTP errors under /api into DRF-style JSON: {"detail": "..."}.
 * Runs after the security exception listener (priority 1) and before Symfony's error renderer.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        if (!str_starts_with($request->getPathInfo(), '/api') || !$exception instanceof HttpExceptionInterface) {
            return;
        }

        $detail = match (true) {
            $exception instanceof MethodNotAllowedHttpException => sprintf('Method "%s" not allowed.', $request->getMethod()),
            $exception instanceof NotFoundHttpException => 'Not found.',
            $exception instanceof AccessDeniedHttpException => 'You do not have permission to perform this action.',
            $exception instanceof BadRequestHttpException && '' !== $exception->getMessage() => $exception->getMessage(),
            default => Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
        };

        $event->setResponse(new ApiResponse(['detail' => $detail], $exception->getStatusCode(), $exception->getHeaders()));
    }
}
