<?php
declare(strict_types=1);

namespace App;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ConfigBag {
    private ClockInterface $clock;
    private int $cookieTtl;
    private int $limit;
    private int $limitTimeout;
    private int $limitTtl;
    private string $queryPrefix;
    private string $totpUri;
    private ?string $assetsDir;
    private ?int $ipTtl;
    private ?string $staticSecret;
    private bool $teapot;

    public function __construct(
        Utilities                                                 $utilities,
        ClockInterface                                            $clock,
        #[Autowire('%app.cookie_ttl%')] int                       $cookieTtl,
        #[Autowire('%app.limit%')] int                            $limit,
        #[Autowire('%app.limit_timeout%')] int                    $limitTimeout,
        #[Autowire('%app.limit_ttl%')] int                        $limitTtl,
        #[Autowire('%app.query_prefix%')] string                  $queryPrefix,
        #[Autowire('%app.totp_uri%')] string                      $totpUri,
        #[Autowire('%app.assets%')] bool                          $assets,
        #[Autowire('%kernel.project_dir%/public/assets/')] string $assetsDir,
        #[Autowire('%app.ip_ttl%')] ?int                          $ipTtl,
        #[Autowire('%app.static_secret%')] ?string                $staticSecret,
        #[Autowire('%app.teapot%')] bool                          $teapot,
    ) {
        $this->clock = $clock;
        $this->cookieTtl = $cookieTtl;
        $this->limit = ($limit >= 1) ? $limit : 4;
        $this->limitTimeout = ($limitTimeout >= 1) ? $limitTimeout : 21600;
        $this->limitTtl = ($limitTtl >= 1) ? $limitTtl : 86400;
        $this->queryPrefix = $queryPrefix;
        $this->totpUri = $totpUri ?: $utilities->loadTotp();
        $this->assetsDir = $assets ? $assetsDir : null;
        $this->ipTtl = $ipTtl ?: null;
        $this->staticSecret = $staticSecret ?: null;
        $this->teapot = $teapot;
    }

    public function clock(): ClockInterface {
        return $this->clock;
    }

    public function cookieTtl(): int {
        return $this->cookieTtl;
    }

    public function limit(): int {
        return $this->limit;
    }

    public function limitTimeout(): int {
        return $this->limitTimeout;
    }

    public function limitTtl(): int {
        return $this->limitTtl;
    }

    public function query(string $field): string {
        return "$this->queryPrefix$field";
    }

    public function totpUri(): string {
        return $this->totpUri;
    }

    public function assetsDir(): ?string {
        return $this->assetsDir;
    }

    public function ipTtl(): ?int {
        return $this->ipTtl;
    }

    public function staticSecret(): ?string {
        return $this->staticSecret;
    }

    public function teapot(): bool {
        return $this->teapot;
    }
}
