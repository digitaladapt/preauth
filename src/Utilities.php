<?php
declare(strict_types=1);

namespace App;

use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;
use DateTimeImmutable;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class Utilities {
    /* top-level-domains which are known to have multiple parts
     * stored backwards TLD[uk][co] means ".co.uk" */
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
    private const PATH_REGEX = '/[^A-Za-z0-9_.\/-]+/';
    private const NO_UP_REGEX = '/\.{2,}/';
    private const KEY_REGEX = '/[^A-Za-z0-9_.]+/';

    public function __construct(
        private readonly ClockInterface         $clock,
        private readonly CacheItemPoolInterface $appCache,
    ) {}

    /**
     * This lets us determine the base domain of the given ip, localhost, or domain
     * "service.example.co.uk" into "example.co.uk" and "service.example.com" into "example.com"
     * @param string $host ip, localhost, or domain with zero or more subdomains
     * @param bool $strict return null if host is ip or localhost, default false
     * @return ?string returns same ip, hostname, or domain with all subdomains removed
     */
    public function baseDomain(string $host, bool $strict = false): ?string {
        /* if host is an ip address (or localhost), leave it as is */
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return $strict ? null : $host;
        }

        $parts = explode('.', $host);
        $keep = $this->baseLength($parts);
        $parts = array_slice($parts, -$keep);
        return implode('.', $parts);
    }

    /**
     * Build new domain name based on base of the given host and given subdomain
     * Handles edge cases like host being an ip or localhost
     * @param ?string $subdomain subdomain to use, allowed to be null
     * @param string $host domain (optionally with subdomains), ip, or localhost
     * @return string returns a valid hostname, typically "subdomain.baseDomain"
     */
    public function buildDomain(?string $subdomain, string $host): string {
        $base = $this->baseDomain($host, true);
        if ($base === null) {
            return $host;
        }
        return ($subdomain === null || $subdomain === '')
            ? $base : "$subdomain.$base";
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

    public function makeCacheKey(string $name): string {
        return preg_replace(self::KEY_REGEX, '_', $name);
    }

    public function cleanPath(string $path): string {
        return preg_replace([self::PATH_REGEX, self::NO_UP_REGEX], ['', '.'], $path);
    }

    public function hash(ParameterBag $query): string {
        /* remember this is *NOT* cryptographically secure */
        $array = $query->all();
        ksort($array);
        return hash('xxh3', json_encode($array));
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    public function loadTotp(): string {
        /* user forgot to set their TOTP_URI in the environment */
        if ($this->appCache->hasItem('totp')) {
            return $this->appCache->getItem('totp')->get();
        }

        return $this->makeTotp();
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    private function makeTotp(): string {
        /* we have not stored a totp into the app cache yet */
        $totpObj = TOTP::generate($this->clock);
        $totpObj->setLabel('Preauth-TOTP');
        $totp = $totpObj->getProvisioningUri();
        $totpItem = $this->appCache->getItem('totp');
        $totpItem->set($totp);
        /* per PSR6, if no expiration is set, implementation may set a default,
         * we want this to keep forever, so a few hundred years should do it */
        $totpItem->expiresAt(DateTimeImmutable::createFromFormat(
            'Y-m-d', '2999-12-31'
        ));
        $this->appCache->save($totpItem);
        $this->showTotp($totp);
        return $totp;
    }

    /** @throws InvalidArgumentException we sanitize cache keys, to prevent this */
    private function showTotp(string $totp): void {
        /* only show this at most, every 5 minutes */
        $suppress = $this->appCache->getItem('suppress');
        if ( ! $suppress->isHit()) {
            $writer = new Writer(new PlainTextRenderer());
            file_put_contents(
                'php://stderr', <<<RAW
                {$writer->writeString($totp)}
                $totp
                loading totp, because the env is not set, please copy above into TOTP_URI

                RAW, FILE_APPEND
            );
            $suppress->expiresAfter(300);
            $this->appCache->save($suppress);
        }
    }
}
