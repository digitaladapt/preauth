<?php

declare(strict_types=1);

namespace App\Trait;

use App\AppConstants;
use App\ConfigBag;
use App\Enum\RemoteUserMode;
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
     * Build the plain-text success response body and headers for an
     * authenticated request. The body is a simple greeting that includes
     * the session id. The Remote-User header is set (or omitted) based on
     * the configured remote-user mode.
     */
    public function authSuccessResponse(string $id, ConfigBag $config): Response
    {
        $headers = ['Content-Type' => 'text/plain'];

        $headerValue = $this->resolveRemoteUser($id, $config);
        if ($headerValue !== null) {
            $headers['Remote-User'] = $headerValue;
        }

        return new Response("hi $id", headers: $headers);
    }

    /**
     * Resolve the Remote-User header value based on the configured mode.
     * Returns null when the header should not be sent.
     */
    private function resolveRemoteUser(string $id, ConfigBag $config): ?string
    {
        return match ($config->remoteUserMode()) {
            RemoteUserMode::Session => $id,
            RemoteUserMode::Static  => $config->remoteUserStatic(),
            RemoteUserMode::Mapped  => $config->remoteUserMap()[$id] ?? $id,
            RemoteUserMode::None    => null,
        };
    }
}
