<?php

declare(strict_types=1);

namespace App\Service;

interface DomainInterface
{
    /** IE: "auth.example.com" or null if not using a separate subdomain
     * @return ?string Returns auth subdomain if configured, otherwise null */
    public function getAuthSubdomain(): ?string;

    /** check if given url is an acceptable url for redirection
     * @param string $url Where we are thinking of sending the user
     * @return bool Returns true if it is acceptable to send the user there */
    public function validReturn(string $url): bool;

    /** check if host-base matches auth-base
     * @param string $host
     * @return bool returns true if and only if host matches base domain of auth */
    public function matchesAuth(string $host): bool;

    /** IE: "example.com" if central auth is something like "auth.example.com"
     * @return string|null returns base domain if we are doing central auth */
    public function authBase(): ?string;
}
