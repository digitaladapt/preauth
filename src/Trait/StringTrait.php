<?php

declare(strict_types=1);

namespace App\Trait;

use App\AppConstants;
use Symfony\Component\HttpFoundation\Response;

trait StringTrait
{
    /* cache keys can safely use alphanumeric, "_", and ".", remove the rest */
    private const string KEY_REGEX = '/[^A-Za-z0-9_.]+/';

    public function makeCacheKey(string $name): string
    {
        return mb_substr(preg_replace(static::KEY_REGEX, '_', $name), 0, AppConstants::MAX_INPUT_LENGTH);
    }

    /**
     * Build the plain-text success response body and headers for an authenticated request.
     * The body is a simple greeting that includes the session id.
     */
    public function authSuccessResponse(string $id): Response
    {
        return new Response("hi $id", headers: [
            'Content-Type' => 'text/plain',
            'Remote-User'  => $id,
        ]);
    }
}
