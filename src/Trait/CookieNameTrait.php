<?php
declare(strict_types=1);

namespace App\Trait;

trait CookieNameTrait {
    private const string COOKIE_NAME = '__Host-Http-Preauth';
    private const string AUTH_COOKIE_NAME = '__Http-Domain-Preauth';
    private const string HEADER_NAME = 'X-Preauth';

    final protected function cookieName(): string {
        return static::COOKIE_NAME;
    }

    final protected function authCookieName(): string {
        return static::AUTH_COOKIE_NAME;
    }

    final protected function headerName(): string {
        return static::HEADER_NAME;
    }
}
