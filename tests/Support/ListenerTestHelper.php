<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ConfigBag;
use App\Service\DomainManager;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Helpers for constructing the collaborators that the kernel listeners
 * depend on, without booting the full Symfony container.
 */
trait ListenerTestHelper
{
    use TotpTestHelper;

    /** Build a Twig Environment pointed at the project's real templates. */
    private function makeTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['strict_variables' => true]);
        // the templates reference a global `env` object; supply one with the
        // keys used by base/login/error/_script/_style
        $twig->addGlobal('env', (object)[
            'title'            => 'Pre-Authentication System',
            'bg_color'         => '#029386',
            'fg_color'         => '#ffffff',
            'error_color'      => '#ffb16d',
            'id_name'          => 'Session ID',
            'token_name'       => 'Authentication Token',
            'submit_name'      => 'Submit',
            'error_message'    => 'Unsuccessful login attempt',
            'teapot'           => true,
            'teapot_title'     => "I'm a teapot",
            'teapot_message'   => 'I refuse to brew coffee',
            'too_many_title'   => 'Too many requests',
            'too_many_message' => 'Try again later',
            'debug'            => 0,
        ]);
        return $twig;
    }

    /**
     * A RateLimiterFactoryInterface whose created limiter returns a RateLimit
     * with the given remaining tokens.
     */
    private function makeRateLimiterFactory(int $remainingTokens): RateLimiterFactoryInterface
    {
        $limiter = $this->makeLimiter($remainingTokens);
        return new class ($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private LimiterInterface $limiter)
            {
            }
            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };
    }

    private function makeLimiter(int $remainingTokens): LimiterInterface
    {
        $rateLimit = new RateLimit(
            $remainingTokens,
            new \DateTimeImmutable('+10 seconds'),
            $remainingTokens > 0,
            10,
        );
        return new class ($rateLimit) implements LimiterInterface {
            public function __construct(private RateLimit $rateLimit)
            {
            }
            public function reserve(int $tokens = 1, ?float $maxTime = null): \Symfony\Component\RateLimiter\Reservation
            {
                throw new \Symfony\Component\RateLimiter\Exception\ReserveNotSupportedException();
            }
            public function consume(int $tokens = 1): RateLimit
            {
                return $this->rateLimit;
            }
            public function reset(): void
            {
            }
        };
    }

    /**
     * A factory whose limiter tracks how many consume(1) calls were made and
     * reports the limit as reached only after $threshold failures.
     */
    private function makeCountingRateLimiterFactory(int $threshold): RateLimiterFactoryInterface
    {
        $limiter = new class ($threshold) implements LimiterInterface {
            private int $consumed = 0;
            public function __construct(private int $threshold)
            {
            }
            public function reserve(int $tokens = 1, ?float $maxTime = null): \Symfony\Component\RateLimiter\Reservation
            {
                throw new \Symfony\Component\RateLimiter\Exception\ReserveNotSupportedException();
            }
            public function consume(int $tokens = 1): RateLimit
            {
                $this->consumed += $tokens;
                $remaining = max(0, $this->threshold - $this->consumed);
                return new RateLimit(
                    $remaining,
                    new \DateTimeImmutable('+10 seconds'),
                    $remaining > 0,
                    $this->threshold,
                );
            }
            public function reset(): void
            {
                $this->consumed = 0;
            }
        };
        return new class ($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private LimiterInterface $limiter)
            {
            }
            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };
    }
}
