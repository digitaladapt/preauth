<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel as AppKernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel used by the functional test suite.
 *
 * In production the nonce cache is backed by APCu, which naturally persists
 * across PHP requests.  In the test environment the nonce cache is an
 * in-memory ArrayAdapter; Symfony's ServicesResetter clears it between
 * requests (even with KernelBrowser::disableReboot()), which would discard
 * the nonce issued on the login-page request before the login-submission
 * request can verify it.
 *
 * This kernel removes the kernel.reset tag from the nonceCache (and
 * rateLimitCache) pools so their in-memory state survives across requests
 * within a single test, mirroring the persistence behaviour of APCu.
 */
class TestKernel extends AppKernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['nonceCache', 'rateLimitCache', 'sessionCache', 'sessionStorage'] as $poolId) {
                    if ($container->hasDefinition($poolId)) {
                        $container->getDefinition($poolId)->clearTag('kernel.reset');
                    }
                }
            }
        });
    }
}
