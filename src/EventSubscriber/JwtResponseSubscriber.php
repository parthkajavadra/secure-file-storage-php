<?php

namespace App\EventSubscriber;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the JWT endpoints answer like djangorestframework-simplejwt, so existing clients keep working:
 *   POST /api/token/          -> {"refresh": "...", "access": "..."}
 *   POST /api/token/refresh/  -> {"refresh": "...", "access": "..."}
 * and authentication errors use DRF's {"detail": "..."} shape.
 */
final class JwtResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Run after the refresh-token bundle has attached the refresh token.
            Events::AUTHENTICATION_SUCCESS => ['onAuthenticationSuccess', -100],
            Events::AUTHENTICATION_FAILURE => 'onAuthenticationFailure',
            Events::JWT_NOT_FOUND => 'onJwtNotFound',
            Events::JWT_INVALID => 'onJwtInvalid',
            Events::JWT_EXPIRED => 'onJwtInvalid',
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        if (\array_key_exists('token', $data)) {
            $data['access'] = $data['token'];
            unset($data['token']);
        }
        $event->setData($data);
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->unauthorized(['detail' => 'No active account found with the given credentials']));
    }

    public function onJwtNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse($this->unauthorized(['detail' => 'Authentication credentials were not provided.']));
    }

    public function onJwtInvalid(JWTInvalidEvent|JWTExpiredEvent $event): void
    {
        $event->setResponse($this->unauthorized([
            'detail' => 'Given token not valid for any token type',
            'code' => 'token_not_valid',
        ]));
    }

    /** @param array<string, string> $body */
    private function unauthorized(array $body): ApiResponse
    {
        return new ApiResponse($body, Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="api"']);
    }
}
