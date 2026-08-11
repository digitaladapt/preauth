<?php

declare(strict_types=1);

namespace App\Data;

use App\Enum\Scope;
use Symfony\Component\HttpFoundation\InputBag;

/** when scope is IP but ip-access is disabled, scope is to be considered cookie */
final class Payload
{
    public string $id;    /* session name, identifying who is logging in */
    public string $token; /* TOTP, typically six digits */
    public string $nonce; /* random unique string, to block duplicate submissions */
    public bool   $json;  /* should we return json (for the login page) */
    public Scope  $scope; /* type of access being requested */

    public static function decode(string $base64url): ?Payload
    {
        /* convert the base64url into json string */
        $base64 = strtr($base64url, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        if ($json) {
            /* convert the json string into real data */
            $data = json_decode($json);
            if (is_object($data)) {
                return Payload::create($data);
            }
        }
        return null;
    }

    public static function load(InputBag $input): ?Payload
    {
        /* convert form data into real data */
        if ($input->has('username') && $input->has('nonce') && $input->has('totp')) {
            return Payload::create((object)[
                'id'    => $input->get('username'),
                'nonce' => $input->get('nonce'),
                'token' => $input->get('totp'),
                'json'  => false,
            ]);
        }
        return null;
    }

    public static function create(object $data): ?Payload
    {
        /* if missing required fields id, nonce, or token */
        if (strlen(trim($data->id    ?? '')) < 1 ||
            strlen(trim($data->nonce ?? '')) < 1 ||
            strlen(trim($data->token ?? '')) < 1
        ) {
            /* returns null as the input is invalid */
            return null;
        }

        /* all input is limited */
        $payload = new Payload();
        $payload->id    = mb_substr(trim($data->id), 0, 128);
        $payload->nonce = mb_substr(trim($data->nonce), 0, 128);
        $payload->json  = ($data->json ?? true);
        $payload->scope = Scope::tryFrom($data->scope ?? '') ?? Scope::Cookie;
        $payload->token = mb_substr(trim($data->token), 0, 128);

        return Payload::constrict($payload);
    }

    public function toString(): string
    {
        return json_encode($this);
    }

    private static function constrict(Payload $payload): Payload
    {
        /* When scope is None, json will be considered false. */
        if ($payload->scope === Scope::None) {
            $payload->json = false;
        }

        return $payload;
    }
}
