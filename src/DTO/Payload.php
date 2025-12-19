<?php
declare(strict_types=1);

namespace App\DTO;

use App\Enum\Scope;

/* When scope is None, json will be considered false.
 * When using password, scope will be considered None.
 * When scope is Ip but ip-access is disabled, scope will be considered Cookie.
 * When using password but password is disabled, request will always fail. */
final class Payload {
    public string  $id;       /* session name, identifying who is logging in */
    public ?string $token;    /* totp, typically six digits */
    public ?string $password; /* static secret, alternative to token, if enabled */
    public string  $nonce;    /* random unique string, to prevent duplicate submissions */
    public bool    $json;     /* should we return json (for the login page) */
    public Scope   $scope;    /* type of access being requested */

    public static function create(object $data): ?static {
        /* if missing required fields id or nonce */
        if (strlen($data->id ?? '') < 1 || strlen($data->nonce ?? '') < 1 ||
            /* if missing both token and password */
            (strlen($data->token ?? '') < 1 && strlen($data->password ?? '') < 1)
        ) {
            /* returns null if the input is invalid */
            return null;
        }

        $instance = new static();

        $instance->id = $data->id;

        if (strlen($data->token ?? '') > 0) {
            $instance->token = $data->token;
        } else {
            $instance->password = $data->password;
        }

        $instance->nonce = $data->nonce;

        $instance->json = ($data->json ?? true);

        switch ($data->scope ?? '') {
            case 'ip':   $instance->scope = Scope::Ip;     break;
            case 'none': $instance->scope = Scope::None;   break;
            default:     $instance->scope = Scope::Cookie; break;
        }

        return $instance;
    }
}

