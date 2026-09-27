<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\Payload;
use App\Enum\Scope;
use App\Trait\GetTotpTrait;
use App\Trait\MakeNonceTrait;
use App\Trait\StringTrait;
use Override;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a TOTP code (or backup code) and, on success, grants access.
 *
 * The "grant access" half now lives in {@see SessionIssuer} so the passkey
 * ceremony produces an identical response. This class keeps the part that is
 * genuinely specific to code-based login: verifying the code and enforcing the
 * single-use nonce.
 */
final readonly class LoginManager implements LoginInterface
{
    use GetTotpTrait;
    use MakeNonceTrait;
    use StringTrait;

    public function __construct(
        private BackupCodeInterface $backupCodeManager,
        private SessionIssuerInterface $sessionIssuer,
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

        return $this->sessionIssuer->issue(
            $payload->id,
            $payload->scope,
            $request,
            $payload->json,
        );
    }
}
