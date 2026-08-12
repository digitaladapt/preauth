<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Matches request paths against configured public path patterns.
 *
 * Patterns support simple wildcards:
 *  - `*`  matches any characters within a single path segment (not crossing `/`)
 *  - `**` matches any characters including `/` (crosses path segments)
 *
 * Patterns may optionally include a host prefix (e.g. `example.com/public/**`).
 * When no host prefix is given, the pattern matches on any host.
 */
interface PublicPathMatcherInterface
{
    /**
     * Returns true if the given host and path match any configured public pattern.
     *
     * @param string $host The request host (e.g. "code.example.com")
     * @param string $path The request path (e.g. "/public/repo/issues")
     */
    public function matches(string $host, string $path): bool;

    /**
     * Returns true if no public paths are configured (feature is disabled).
     */
    public function isEmpty(): bool;
}
