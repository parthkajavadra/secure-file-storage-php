<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON response encoded like DRF's JSONRenderer: slashes and Unicode are not escaped.
 */
class ApiResponse extends JsonResponse
{
    protected int $encodingOptions = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;
}
