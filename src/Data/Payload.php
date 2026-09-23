<?php

declare(strict_types=1);

namespace App\Data;

use App\AppConstants;
use App\Enum\Scope;
use Symfony\Component\HttpFoundation\InputBag;

/** when scope is IP but ip-access is disabled, scope is to be considered cookie */
final class Payload
{
    public string $id;    /* session name, identifying who is logging in */
    public string $token; /* TOTP, typically six digits */
    public string $nonce; /* random unique string, to block duplicate submissions */
    public bool $json;  /* should we return json (for the login page) */
    public Scope $scope; /* type of access being requested */

    public static function decode(string $base64url): ?self
    {
        /* convert the base64url into json string */
        $base64 = strtr($base64url, '-_', '+/');
        $base64 .= str_repeat('=', (4 - \strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        if ($json) {
            /* convert the json string into real data */
            $data = json_decode($json);
            if (\is_object($data)) {
                return self::create($data);
            }
        }

        return null;
    }

    public static function load(InputBag $input): ?self
    {
        /* convert form data into real data */
        if ($input->has('username') && $input->has('nonce') && $input->has('totp')) {
            return self::create((object) [
                'id' => $input->get('username'),
                'nonce' => $input->get('nonce'),
                'token' => $input->get('totp'),
                'json' => false,
            ]);
        }

        return null;
    }

    public static function create(object $data): ?self
    {
        /* if missing required fields id, nonce, or token */
        if ('' === trim($data->id ?? '')
            || '' === trim($data->nonce ?? '')
            || '' === trim($data->token ?? '')
        ) {
            /* returns null as the input is invalid */
            return null;
        }

        /* all input is limited */
        $payload = new self();
        $payload->id = mb_substr(trim($data->id), 0, AppConstants::MAX_INPUT_LENGTH);
        $payload->nonce = mb_substr(trim($data->nonce), 0, AppConstants::MAX_INPUT_LENGTH);
        $payload->json = ($data->json ?? true);
        $payload->scope = Scope::tryFrom($data->scope ?? '') ?? Scope::Cookie;
        $payload->token = mb_substr(trim($data->token), 0, AppConstants::MAX_INPUT_LENGTH);

        return self::constrict($payload);
    }

    public function toString(): string
    {
        return json_encode($this);
    }

    private static function constrict(self $payload): self
    {
        /* When scope is None, json will be considered false. */
        if (Scope::None === $payload->scope) {
            $payload->json = false;
        }

        return $payload;
    }
}
