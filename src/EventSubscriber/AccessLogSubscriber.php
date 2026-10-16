<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Port of core/middleware/access_log_middleware.py:
 *   - 400/401/403/404/405 responses are written to access.log
 *   - unhandled server errors are written to error.log
 */
final class AccessLogSubscriber implements EventSubscriberInterface
{
    private const LOGGED_STATUSES = [400, 401, 403, 404, 405];

    public function __construct(
        private readonly LoggerInterface $accessLogger,
        private readonly LoggerInterface $appErrorLogger,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
            KernelEvents::EXCEPTION => ['onException', 10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        if (!\in_array($status, self::LOGGED_STATUSES, true)) {
            return;
        }

        $request = $event->getRequest();
        $this->accessLogger->warning(sprintf(
            '[ACCESS LOG] %s | Path: %s | Method: %s | IP: %s | Status: %d | User: %s',
            $this->now(), $request->getPathInfo(), $request->getMethod(), $request->getClientIp(), $status, $this->userLabel(),
        ));
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        // HTTP errors (404, 403, ...) are ordinary responses, logged by onResponse.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $request = $event->getRequest();
        $this->appErrorLogger->error(sprintf(
            '[SERVER ERROR] %s | Path: %s | Method: %s | IP: %s | User: %s | Exception: %s',
            $this->now(), $request->getPathInfo(), $request->getMethod(), $request->getClientIp(), $this->userLabel(), $exception->getMessage(),
        ));
    }

    private function userLabel(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'Anonymous';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');
    }
}
