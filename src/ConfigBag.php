<?php

declare(strict_types=1);

namespace App;

use App\Enum\RemoteUserMode;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfigBag
{
    private ClockInterface $clock;
    private int $cookieTtl;
    private string $totpUri;
    private ?int $ipTtl;
    private bool $teapot;
    private string $errorMessage;
    private string $teapotTitle;
    private string $tooManyTitle;
    private RemoteUserMode $remoteUserMode;
    private string $remoteUserStatic;
    /** @var array<string,string> */
    private array $remoteUserMap;

    /** @throws InvalidArgumentException */
    public function __construct(
        Utilities                                  $utilities,
        ClockInterface                             $clock,
        #[Autowire('%app.cookie_ttl%')] int        $cookieTtl,
        #[Autowire('%app.totp_uri%')] string       $totpUri,
        #[Autowire('%app.ip_ttl%')] ?int           $ipTtl,
        #[Autowire('%app.teapot%')] bool           $teapot,
        #[Autowire('%app.error_message%')] string  $errorMessage,
        #[Autowire('%app.teapot_title%')] string   $teapotTitle,
        #[Autowire('%app.too_many_title%')] string $tooManyTitle,
        #[Autowire('%app.remote_user%')] string    $remoteUserMode,
        #[Autowire('%app.remote_user_static%')] string $remoteUserStatic,
        #[Autowire('%app.remote_user_map%')] string $remoteUserMap,
    ) {
        $this->clock        = $clock;
        $this->cookieTtl    = $cookieTtl;
        $this->totpUri      = $totpUri ?: $utilities->loadTotp();
        $this->ipTtl        = $ipTtl ?: null;
        $this->teapot       = $teapot;
        $this->errorMessage = $errorMessage;
        $this->teapotTitle  = $teapotTitle;
        $this->tooManyTitle = $tooManyTitle;

        $this->remoteUserMode  = RemoteUserMode::tryFrom($remoteUserMode) ?? RemoteUserMode::Session;
        $this->remoteUserStatic = $remoteUserStatic;
        $this->remoteUserMap   = $this->parseUserMap($remoteUserMap);
    }

    /**
     * Parse a comma-separated map string ("id1:user1,id2:user2") into an array.
     *
     * @return array<string,string>
     */
    private function parseUserMap(string $map): array
    {
        if ($map === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $map) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $result;
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    public function cookieTtl(): int
    {
        return $this->cookieTtl;
    }

    public function totpUri(): string
    {
        return $this->totpUri;
    }

    public function ipTtl(): ?int
    {
        return $this->ipTtl;
    }

    public function teapot(): bool
    {
        return $this->teapot;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    public function teapotTitle(): string
    {
        return $this->teapotTitle;
    }

    public function tooManyTitle(): string
    {
        return $this->tooManyTitle;
    }

    public function remoteUserMode(): RemoteUserMode
    {
        return $this->remoteUserMode;
    }

    public function remoteUserStatic(): string
    {
        return $this->remoteUserStatic;
    }

    /**
     * @return array<string,string>
     */
    public function remoteUserMap(): array
    {
        return $this->remoteUserMap;
    }
}
