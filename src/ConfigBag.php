<?php
declare(strict_types=1);

namespace App;

use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfigBag {
    private ClockInterface $clock;
    private int $cookieTtl;
    private string $queryPrefix;
    private string $totpUri;
    private ?int $ipTtl;
    private bool $teapot;
    private string $errorMessage;
    private string $teapotTitle;
    private string $tooManyTitle;
    // New configuration for optional authentication subdomain redirection
    private bool $subdomainRedirect;
    private string $authSubdomain;

    /** @throws InvalidArgumentException */
    public function __construct(
        Utilities                                                 $utilities,
        ClockInterface                                            $clock,
        #[Autowire('%app.cookie_ttl%')] int                       $cookieTtl,
        #[Autowire('%app.query_prefix%')] string                  $queryPrefix,
        #[Autowire('%app.totp_uri%')] string                      $totpUri,
        #[Autowire('%app.ip_ttl%')] ?int                          $ipTtl,
        #[Autowire('%app.teapot%')] bool                          $teapot,
        #[Autowire('%app.error_message%')] string                 $errorMessage,
        #[Autowire('%app.teapot_title%')] string                  $teapotTitle,
        #[Autowire('%app.too_many_title%')] string                $tooManyTitle,
        #[Autowire('%app.subdomain_redirect%')] bool              $subdomainRedirect,
        #[Autowire('%app.auth_subdomain%')] string                $authSubdomain,
    ) {
        $this->clock = $clock;
        $this->cookieTtl = $cookieTtl;
        $this->queryPrefix = $queryPrefix;
        $this->totpUri = $totpUri ?: $utilities->loadTotp();
        $this->ipTtl = $ipTtl ?: null;
        $this->teapot = $teapot;
        $this->errorMessage = $errorMessage;
        $this->teapotTitle = $teapotTitle;
        $this->tooManyTitle = $tooManyTitle;
        $this->subdomainRedirect = $subdomainRedirect;
        $this->authSubdomain = $authSubdomain;
    }

    public function clock(): ClockInterface {
        return $this->clock;
    }

    public function cookieTtl(): int {
        return $this->cookieTtl;
    }

    public function query(string $field): string {
        return "$this->queryPrefix$field";
    }

    public function totpUri(): string {
        return $this->totpUri;
    }

    public function ipTtl(): ?int {
        return $this->ipTtl;
    }

    public function teapot(): bool {
        return $this->teapot;
    }

    public function errorMessage(): string {
        return $this->errorMessage;
    }

    public function teapotTitle(): string {
        return $this->teapotTitle;
    }

    public function tooManyTitle(): string {
        return $this->tooManyTitle;
    }

    /**
     * Whether the application should redirect unauthenticated requests to a dedicated authentication subdomain.
     */
    public function subdomainRedirect(): bool {
        return $this->subdomainRedirect;
    }

    /**
     * The subdomain (e.g., "auth.example.com") to which unauthenticated requests should be redirected.
     */
    public function authSubdomain(): string {
        return $this->authSubdomain;
    }
}
