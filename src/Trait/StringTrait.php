<?php

declare(strict_types=1);

namespace App\Trait;

trait StringTrait
{
    /* cache keys can safely use alphanumeric, "_", and ".", remove the rest */
    private const string KEY_REGEX = '/[^A-Za-z0-9_.]+/';

    public function makeCacheKey(string $name): string
    {
        return mb_substr(preg_replace(static::KEY_REGEX, '_', $name), 0, 128);
    }
}
