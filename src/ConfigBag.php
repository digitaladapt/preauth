<?php

declare(strict_types=1);

namespace App;

use App\Enum\RemoteUserMode;
use App\Enum\UserVerification;
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
    private string $title;
    private bool $passkeyEnabled;
    private string $passkeyRpName;
    private UserVerification $passkeyUserVerification;
    private int $passkeyTimeout;
    private string $passkeyButtonName;
    private string $passkeyRegisterName;

    /** Passkey ceremony timeout in milliseconds (WebAuthn default). */
    private const int DEFAULT_PASSKEY_TIMEOUT = 60000;

    /** @throws InvalidArgumentException */
    public function __construct(
        Utilities $utilities,
        ClockInterface $clock,
        #[Autowire('%app.cookie_ttl%')] int $cookieTtl,
        #[Autowire('%app.totp_uri%')] string $totpUri,
        #[Autowire('%app.ip_ttl%')] ?int $ipTtl,
        #[Autowire('%app.teapot%')] bool $teapot,
        #[Autowire('%app.error_message%')] string $errorMessage,
        #[Autowire('%app.teapot_title%')] string $teapotTitle,
        #[Autowire('%app.too_many_title%')] string $tooManyTitle,
        #[Autowire('%app.remote_user%')] string $remoteUserMode,
        #[Autowire('%app.remote_user_static%')] string $remoteUserStatic,
        #[Autowire('%app.remote_user_map%')] string $remoteUserMap,
        #[Autowire('%app.title%')] string $title = 'Pre-Authentication System',
        #[Autowire('%app.passkey_enabled%')] bool $passkeyEnabled = false,
        #[Autowire('%app.passkey_rp_name%')] string $passkeyRpName = '',
        #[Autowire('%app.passkey_user_verification%')] string $passkeyUserVerification = 'required',
        #[Autowire('%app.passkey_timeout%')] int $passkeyTimeout = self::DEFAULT_PASSKEY_TIMEOUT,
        #[Autowire('%app.passkey_button_name%')] string $passkeyButtonName = 'Sign in with a passkey',
        #[Autowire('%app.passkey_register_name%')] string $passkeyRegisterName = 'Register this device as a passkey',
    ) {
        $this->clock = $clock;
        $this->cookieTtl = $cookieTtl;
        $this->totpUri = $totpUri ?: $utilities->loadTotp();
        $this->ipTtl = $ipTtl ?: null;
        $this->teapot = $teapot;
        $this->errorMessage = $errorMessage;
        $this->teapotTitle = $teapotTitle;
        $this->tooManyTitle = $tooManyTitle;

        $this->remoteUserMode = RemoteUserMode::tryFrom($remoteUserMode) ?? RemoteUserMode::Session;
        $this->remoteUserStatic = $remoteUserStatic;
        $this->remoteUserMap = $this->parseUserMap($remoteUserMap);
        $this->title = $title;
        $this->passkeyEnabled = $passkeyEnabled;
        $this->passkeyRpName = $passkeyRpName;
        $this->passkeyUserVerification = UserVerification::fromConfig($passkeyUserVerification);
        $this->passkeyTimeout = $passkeyTimeout > 0 ? $passkeyTimeout : self::DEFAULT_PASSKEY_TIMEOUT;
        $this->passkeyButtonName = $passkeyButtonName;
        $this->passkeyRegisterName = $passkeyRegisterName;
    }

    /**
     * Parse a comma-separated map string ("id1:user1,id2:user2") into an array.
     *
     * @return array<string,string>
     */
    private function parseUserMap(string $map): array
    {
        if ('' === $map) {
            return [];
        }

        $result = [];
        foreach (explode(',', $map) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (2 === \count($parts)) {
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

    public function title(): string
    {
        return $this->title;
    }

    /**
     * Whether the passkey feature is switched on by configuration.
     *
     * This says nothing about whether the configuration is *usable* — that is
     * {@see Service\PasskeyPolicyInterface::isEnabled()}, which also
     * requires the central-auth prerequisite (D1).
     */
    public function passkeyEnabled(): bool
    {
        return $this->passkeyEnabled;
    }

    /** Relying-party name shown in the authenticator prompt; blank falls back to the title. */
    public function passkeyRpName(): string
    {
        return $this->passkeyRpName;
    }

    /** User-verification requirement; an unrecognised value falls back to `required`. */
    public function passkeyUserVerification(): string
    {
        return $this->passkeyUserVerification->value;
    }

    /** Ceremony timeout in milliseconds. */
    public function passkeyTimeout(): int
    {
        return $this->passkeyTimeout;
    }

    /** Label for the "sign in with a passkey" button. */
    public function passkeyButtonName(): string
    {
        return $this->passkeyButtonName;
    }

    /** Label for the "register this device" checkbox. */
    public function passkeyRegisterName(): string
    {
        return $this->passkeyRegisterName;
    }
}
