<?php

declare(strict_types=1);

namespace App\Service;

use App\AppConstants;
use App\Data\Payload;
use App\Enum\Scope;
use App\Trait\GetTotpTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Override;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates a TOTP code (or backup code) and, on success, either grants
 * access or starts a passkey registration.
 *
 * The "grant access" half lives in {@see SessionIssuer} so the passkey ceremony
 * produces an identical response. This class keeps the part genuinely specific
 * to code-based login: verifying the code and enforcing the single-use nonce.
 *
 * **Why the registration hand-off lives here.** Ticking "register this device"
 * turns the form submission into a registration ceremony, and the TOTP check is
 * what authorises it. That check — and the nonce check — already happen here, so
 * a ceremony started anywhere earlier would mean validating the nonce somewhere
 * new and risking spending it twice.
 */
final readonly class LoginManager implements LoginInterface
{
    use GetTotpTrait;
    use MakeNonceTrait;
    use StringTrait;

    public function __construct(
        private BackupCodeInterface $backupCodeManager,
        private SessionIssuerInterface $sessionIssuer,
        private PasskeyInterface $passkeys,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Override]
    public function checkToken(Payload $payload, Request $request): ?Response
    {
        /* when scope is IP but ip-access is disabled, scope is to be considered cookie */
        if (Scope::Ip === $payload->scope && !$this->config->ipTtl()) {
            /* requested to grant ip access, but that is not enabled */
            $payload->scope = Scope::Cookie;
        }

        if (!$this->getTotp()->verify($payload->token, null, 1)
            && !$this->backupCodeManager->verifyAndConsume($payload->token)
        ) {
            return null;
        }

        /* token is correct (TOTP or Backup) */

        /* if server nonce is found and is valid */
        $nonceItem = $this->nonceCache->getItem($this->makeCacheKey($payload->nonce));
        if (!$nonceItem->isHit() || !$nonceItem->get()) {
            return null;
        }

        /* mark nonce as spent */
        $nonceItem->set(false); /* invalid */
        $nonceItem->expiresAfter(self::NONCE_TTL); /* keep briefly */
        $this->nonceCache->save($nonceItem);

        /* the code and the nonce are both good from here on */

        if ($payload->register) {
            return $this->startRegistration($payload);
        }

        return $this->sessionIssuer->issue(
            $payload->id,
            $payload->scope,
            $request,
            $payload->json,
        );
    }

    /**
     * The registration hand-off: authorisation is already proven, so this issues
     * the ceremony options back to the page instead of a session.
     *
     * `SessionIssuer` is deliberately not involved — the session is granted only
     * once the new credential has actually been verified, at `register-finish`.
     */
    private function startRegistration(Payload $payload): ?Response
    {
        try {
            $payloadOut = $this->passkeys->beginRegistration($payload->id);
        } catch (Throwable) {
            /* a ceremony that cannot start must not become a 500 on the login
             * page; falling through to the caller's failure path is the same
             * treatment a wrong code gets */
            return null;
        }

        $response = new Response(
            (string) json_encode(['register' => $payloadOut]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
        $response->headers->set(AppConstants::PASSKEY_CEREMONY_MARKER, '1');

        return $response;
    }
}
