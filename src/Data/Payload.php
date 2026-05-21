<?php
declare(strict_types=1);

namespace App\Data;

use App\Enum\Scope;

/* When scope is Ip but ip-access is disabled, scope is to be considered Cookie. */
/* When using password but password is disabled, request will always fail. */
final class Payload {
    public string $id;    /* session name, identifying who is logging in */
    public string $token; /* totp, typically six digits */
    public string $nonce; /* random unique string, to block duplicate submissions */
    public bool   $json;  /* should we return json (for the login page) */
    public Scope  $scope; /* type of access being requested */

    public static function decode(string $base64url): ?Payload {
        /* convert the base64url into json string */
        $json = base64_decode(str_pad(strtr($base64url, '-_', '+/'),
            strlen($base64url) % 4, '='
        ), true);
        if ($json) {
            /* convert the json string into real data */
            $data = json_decode($json);
            if (is_object($data)) {
                return Payload::create($data);
            }
        }
        return null;
    }

    public static function create(object $data): ?Payload {
        /* if missing required fields id, nonce, or token */
        if (strlen($data->id ?? '') < 1 ||
            strlen($data->nonce ?? '') < 1 ||
            strlen($data->token ?? '') < 1
        ) {
            /* returns null as the input is invalid */
            return null;
        }

        /* all input is limited */
        $payload = new Payload();
        $payload->id    = mb_substr($data->id, 0, 128);
        $payload->nonce = mb_substr($data->nonce, 0, 128);
        $payload->json  = ($data->json ?? true);
        $payload->scope = Scope::tryFrom($data->scope ?? '') ?? Scope::Cookie;
        $payload->token = mb_substr($data->token, 0, 128);

        return Payload::constrict($payload);
    }

    public static function constrict(Payload $payload): Payload {
        /* When scope is None, json will be considered false. */
        if ($payload->scope === Scope::None) {
            $payload->json = false;
        }

        return $payload;
    }

    public function toString(): string {
        return json_encode($this);
    }
}
