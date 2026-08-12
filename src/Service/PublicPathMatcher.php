<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Matches request paths against configured public path patterns.
 *
 * Patterns are provided as a comma-separated string in the format:
 *   /path/pattern, host.example.com/path/pattern, or a mix.
 *
 * Wildcards:
 *  - * matches any characters within a single path segment (not crossing /)
 *  - ** matches any characters including / (crosses path segments)
 *
 * Query strings are not part of the pattern — matching is against the
 * path only.
 */
final readonly class PublicPathMatcher implements PublicPathMatcherInterface
{
    /** @var list<array{host: ?string, regex: string}> */
    private array $patterns;

    public function __construct(
        #[Autowire('%app.public_paths%')] string $publicPaths,
    ) {
        $this->patterns = $this->parse($publicPaths);
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    public function matches(string $host, string $path): bool
    {
        if ($this->patterns === []) {
            return false;
        }

        $host = strtolower($host);

        foreach ($this->patterns as $entry) {
            if ($entry['host'] !== null && $entry['host'] !== $host) {
                continue;
            }

            if (preg_match($entry['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the comma-separated PUBLIC_PATHS string into pattern entries.
     *
     * @return list<array{host: ?string, regex: string}>
     */
    private function parse(string $publicPaths): array
    {
        if (trim($publicPaths) === '') {
            return [];
        }

        $patterns = [];

        foreach (explode(',', $publicPaths) as $raw) {
            $entry = trim($raw);
            if ($entry === '') {
                continue;
            }

            // Check for a host prefix (anything before the first /)
            $host = null;
            $path = $entry;

            if (preg_match('/^([a-z0-9.-]+)(\/.+)$/i', $entry, $m)) {
                $host = strtolower($m[1]);
                $path = $m[2];
            }

            // Validate path starts with /
            if (!str_starts_with($path, '/')) {
                continue;
            }

            $patterns[] = [
                'host'  => $host,
                'regex' => $this->compilePattern($path),
            ];
        }

        return $patterns;
    }

    /**
     * Convert a wildcard path pattern into a regex string.
     *
     * Star becomes a character class matching one or more non-slash chars.
     * Double-star at end of pattern matches zero or more of any char.
     * Double-star followed by slash matches zero or more path segments.
     * Other characters are escaped as literal regex.
     */
    private function compilePattern(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            // Check for ** (must be at current position)
            if ($i + 1 < $length && $pattern[$i] === '*' && $pattern[$i + 1] === '*') {
                $i += 2;
                if ($i >= $length) {
                    // ** at end of pattern: zero or more chars including /
                    $regex .= '.*';
                } elseif ($pattern[$i] === '/') {
                    // /**/  in middle: zero or more intermediate segments
                    $regex .= '(?:.*/)?';
                    $i += 1; // skip the / after **
                } else {
                    // ** not followed by / or end, treat as .*
                    $regex .= '.*';
                }
            } elseif ($pattern[$i] === '*') {
                $regex .= '[^/]+';
                $i += 1;
            } else {
                $regex .= preg_quote($pattern[$i], '#');
                $i += 1;
            }
        }

        return '#^' . $regex . '$#';
    }
}
