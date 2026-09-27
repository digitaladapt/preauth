<?php

declare(strict_types=1);

namespace App\CacheWarmer;

use App\Exception\PasskeyConfigurationException;
use App\Service\PasskeyPolicyInterface;
use Override;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Fails the build (or container start) when passkeys are enabled in a
 * configuration that cannot support them.
 *
 * `docker/entrypoint.sh` runs `cache:warmup` on every production boot with the
 * real environment already injected, so a misconfiguration is caught while the
 * container is starting — the deployment aborts — rather than surfacing later as
 * a passkey button that silently never works.
 *
 * The warmer is **not** optional: an optional warmer may be skipped, which would
 * let a bad configuration through.
 */
final readonly class PasskeyConfigurationWarmer implements CacheWarmerInterface
{
    public function __construct(
        private PasskeyPolicyInterface $passkeyPolicy,
    ) {
    }

    /**
     * @return string[]
     *
     * @throws PasskeyConfigurationException when passkeys are enabled but unusable
     */
    #[Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->passkeyPolicy->assertConfigurationIsUsable();

        return [];
    }

    /**
     * Never optional: skipping this warmer would defeat its entire purpose.
     */
    #[Override]
    public function isOptional(): bool
    {
        return false;
    }
}
