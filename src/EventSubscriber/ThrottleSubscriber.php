<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Rate limiting, mirroring the DRF throttle setup:
 *   - default for every API view: UserRateThrottle (200/min, per user) + AnonRateThrottle (5/min, per IP)
 *   - GeneratePublicLinkView:     UserRateThrottle only
 *   - PublicFileDownloadView:     AnonRateThrottle + PublicLinkThrottle (10/hour, per IP)
 *   - /api/token/ and /api/token/refresh/: defaults (anonymous -> 5/min per IP)
 */
final class ThrottleSubscriber implements EventSubscriberInterface
{
    private const TOKEN_PATHS = ['/api/token/', '/api/token/refresh/'];

    public function __construct(
        private readonly RateLimiterFactory $userLimiter,
        private readonly RateLimiterFactory $anonLimiter,
        private readonly RateLimiterFactory $publicLinkLimiter,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                // The token endpoints are answered by the firewall (priority 8), so throttle them before it.
                ['throttleTokenEndpoints', 9],
                // Everything else after the firewall, once the user is known.
                ['throttleStorageEndpoints', 7],
            ],
        ];
    }

    public function throttleTokenEndpoints(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->getPathInfo(), self::TOKEN_PATHS, true)) {
            return;
        }

        $this->apply($event, [[$this->anonLimiter, 'ip:'.$request->getClientIp()]]);
    }

    public function throttleStorageEndpoints(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || !str_starts_with($route, 'storage_')) {
            return;
        }

        $user = $this->security->getUser();
        $ipKey = 'ip:'.$request->getClientIp();
        $userKey = $user instanceof User ? 'user:'.$user->getId() : $ipKey;

        $limiters = match ($route) {
            'storage_generate_public_link' => [[$this->userLimiter, $userKey]],
            'storage_public_download' => $user instanceof User
                ? [[$this->publicLinkLimiter, $ipKey]]
                : [[$this->anonLimiter, $ipKey], [$this->publicLinkLimiter, $ipKey]],
            default => $user instanceof User
                ? [[$this->userLimiter, $userKey]]
                : [[$this->anonLimiter, $ipKey]],
        };

        $this->apply($event, $limiters);
    }

    /** @param list<array{RateLimiterFactory, string}> $limiters */
    private function apply(RequestEvent $event, array $limiters): void
    {
        $wait = 0;
        foreach ($limiters as [$factory, $key]) {
            $limit = $factory->create($key)->consume();
            if (!$limit->isAccepted()) {
                $wait = max($wait, $this->secondsUntil($limit));
            }
        }

        if ($wait > 0) {
            $event->setResponse($this->throttled($event->getRequest(), $wait));
        }
    }

    private function secondsUntil(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    private function throttled(Request $request, int $wait): ApiResponse
    {
        return new ApiResponse(
            ['detail' => sprintf('Request was throttled. Expected available in %d second%s.', $wait, 1 === $wait ? '' : 's')],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $wait],
        );
    }
}
