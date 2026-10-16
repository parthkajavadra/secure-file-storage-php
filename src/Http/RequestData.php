<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads the request body the way DRF parsers do: JSON, form-encoded or multipart.
 */
final class RequestData
{
    /** @return array<string, mixed> */
    public static function from(Request $request): array
    {
        if ('json' === $request->getContentTypeFormat()) {
            $content = $request->getContent();
            if ('' === trim($content)) {
                return [];
            }
            try {
                $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new BadRequestHttpException('JSON parse error - '.$e->getMessage(), $e);
            }

            return \is_array($data) ? $data : [];
        }

        return $request->request->all();
    }
}
