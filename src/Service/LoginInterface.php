<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\Payload;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface LoginInterface
{
    /** @throws InvalidArgumentException */
    public function checkToken(Payload $payload, Request $request): ?Response;
}
