<?php
declare(strict_types=1);

namespace App\Trait;

trait CookieNameTrait {
    private const COOKIE_NAME = '__Host-Http-Preauth';
    private const HEADER_NAME = 'X-Preauth';

    final protected function cookieName(): string {
        return static::COOKIE_NAME;
    }

    final protected function headerName(): string {
        return static::HEADER_NAME;
    }
}
