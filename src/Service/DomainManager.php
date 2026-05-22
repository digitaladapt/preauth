<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DomainManager {
    /* top-level-domains which are known to have multiple parts */
    private const TLD = [
        'ai'  => ['com','net','off','org'],
        'am'  => ['radio'],
        'com' => ['br','cn','co','de','eu','gr','it','jpn','mex','ru','sa','uk','us','za'],
        'de'  => ['com'],
        'fm'  => ['radio'],
        'gg'  => ['co','net','org'],
        'in'  => ['co','firm','gen','ind','net','org'],
        'je'  => ['co','net','org'],
        'mx'  => ['com','net','org'],
        'net' => ['gb','hu','in','jp','se','uk'],
        'nz'  => ['co','net','org'],
        'org' => ['ae','us'],
        'ph'  => ['com','net','org'],
        'se'  => ['com'],
        'uk'  => ['co','me','org'],
    ];

    private bool $subdomainRedirect;
    private string $authSubdomain;

    public function __construct(
        #[Autowire('%app.subdomain_redirect%')] bool $subdomainRedirect,
        #[Autowire('%app.auth_subdomain%')] string   $authSubdomain,
    ) {
        $this->subdomainRedirect = $subdomainRedirect;
        $this->authSubdomain = $authSubdomain;
    }

    /**
     * IE: "auth.example.com" or null if not using a separate subdomain
     * @return ?string Returns auth subdomain if configured, otherwise null
     */
    public function getAuthSubdomain(): ?string {
        if ($this->authBase()) {
            return $this->authSubdomain;
        }
        return null;
    }

    /**
     * Check if given url is an acceptable url for redirection
     * @param string $url Where we are thinking of sending the user
     * @return bool Returns true if it is acceptable to send the user there, false otherwise
     */
    public function validReturn(string $url): bool {
        /* ensure url is valid and, when using an auth subdomain,
         * that the url host matches the base domain */
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if ($this->authBase()) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === null) {
                return false;
            }
            return $this->matchesAuth($host);
        }

        return true;
    }

    /**
     * @param string $host
     * @return bool returns true if and only if host matches base domain of auth
     */
    public function matchesAuth(string $host): bool {
        $hostBase = $this->baseDomain($host);
        $authBase = $this->baseDomain($this->authSubdomain);
        return $this->subdomainRedirect && $this->authSubdomain &&
            $authBase && $authBase === $hostBase;
    }

    public function authBase(): ?string {
        if ($this->subdomainRedirect && $this->authSubdomain && $this->baseDomain($this->authSubdomain)) {
            return $this->baseDomain($this->authSubdomain);
        }
        return null;
    }

    /**
     * This lets us determine the base domain of the given ip, localhost, or domain
     * "service.example.co.uk" into "example.co.uk" and "service.example.com" into "example.com"
     * things like "localhost" and "8.8.8.8" will return null
     * @param string $host ip, localhost, or domain with zero or more subdomains
     * @return ?string returns null if host is ip or localhost otherwise domain with all subdomains removed
     */
    private function baseDomain(string $host): ?string {
        /* if host is an ip address (or localhost), leave it as is */
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return null;
        }

        $parts = explode('.', $host);
        $keep = $this->baseLength($parts);
        $parts = array_slice($parts, -$keep);
        return implode('.', $parts);
    }

    private function baseLength(array $parts): int {
        $length = count($parts);
        $baseLength = min(2, $length);
        /* check if host should retain 3 parts, due to TLD */
        if (count($parts) > 2 && isset(self::TLD[$parts[$length-1]]) &&
            in_array($parts[$length-2], self::TLD[$parts[$length-1]], true)
        ) {
            $baseLength = min(3, $length);
        }
        return $baseLength;
    }
}
