<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listener;

use App\Listener\InterceptListener;
use App\Service\DomainManager;
use App\Service\PasskeyPolicyInterface;
use App\Tests\Support\ListenerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The login page is where "passkeys are unavailable" has to be visibly true.
 *
 * A disabled feature must be indistinguishable from one that does not exist, so
 * these tests compare the rendered page rather than trusting that a conditional
 * is in the right place.
 */
final class PasskeyUiTest extends TestCase
{
    use ListenerTestHelper;

    private function makeListener(string $authSubdomain, bool $passkeysAvailable): InterceptListener
    {
        $domainManager = new DomainManager(true, $authSubdomain);

        $policy = $this->createStub(PasskeyPolicyInterface::class);
        $policy->method('isAvailableFor')->willReturn($passkeysAvailable);

        $listener = new InterceptListener(
            $this->makeConfig(),
            $domainManager,
            $this->makeTwig(),
            $policy,
        );
        $listener->setLogger(new NullLogger());
        $listener->setNonceCache(new ArrayAdapter());

        return $listener;
    }

    /**
     * @param string $host          the host being requested
     * @param bool   $passkeys      whether passkeys are available for it
     * @param string $authSubdomain the configured auth subdomain
     */
    private function renderLoginPage(
        string $host,
        bool $passkeys,
        string $authSubdomain = 'auth.example.com',
    ): string {
        $listener = $this->makeListener($authSubdomain, $passkeys);
        $request = Request::create("https://$host/", 'GET');
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelRequest($event);

        return (string) $event->getResponse()?->getContent();
    }

    /**
     * The headline property: with passkeys unavailable the page must contain
     * nothing passkey-related at all.
     */
    public function test_the_login_page_is_unchanged_when_passkeys_are_unavailable(): void
    {
        $html = $this->renderLoginPage('auth.example.com', false);

        self::assertStringNotContainsString('preauth-passkey', $html);
        self::assertStringNotContainsString('preauth-register', $html);
        self::assertStringNotContainsString('publickey-credentials', $html);
    }

    public function test_the_login_page_offers_passkeys_when_available(): void
    {
        $html = $this->renderLoginPage('auth.example.com', true);

        /* the sign-in button */
        self::assertStringContainsString('id="preauth-passkey"', $html);
        /* the registration checkbox */
        self::assertStringContainsString('id="preauth-register"', $html);
        self::assertStringContainsString('name="register"', $html);
    }

    /**
     * The button and checkbox carry the configured labels, so an operator can
     * reword them without touching templates.
     */
    public function test_the_offered_ui_uses_the_configured_labels(): void
    {
        $html = $this->renderLoginPage('auth.example.com', true);

        self::assertStringContainsString($this->makeConfig()->passkeyButtonName(), $html);
        self::assertStringContainsString($this->makeConfig()->passkeyRegisterName(), $html);
    }

    /**
     * The checkbox only makes sense where the form actually POSTs, because
     * registration authorises itself with the TOTP code in that submission.
     * On a protected host the form is submitted by fetch() instead.
     */
    public function test_the_registration_checkbox_is_omitted_when_the_form_does_not_post(): void
    {
        /* A host outside the auth base domain renders the login page directly,
         * and that page submits via the inline fetch() rather than a real form
         * POST — so there is no submission for a registration to ride on. */
        $html = $this->renderLoginPage('unrelated.test', true, 'auth.example.com');

        self::assertStringContainsString('id="preauth-passkey"', $html);
        self::assertStringNotContainsString('id="preauth-register"', $html);
    }

    /**
     * The script must send base64url without padding, matching what
     * webauthn-lib decodes. Padding would be a silent failure at the library,
     * reported as "invalid signature" rather than as an encoding mistake.
     */
    public function test_the_script_encodes_ceremony_values_as_unpadded_base64url(): void
    {
        $html = $this->renderLoginPage('auth.example.com', true);

        self::assertStringContainsString("replace(/=+$/, '')", $html);
    }

    /**
     * Registration is dispatched through the same POST the TOTP form uses, and
     * marked as such so the server can tell the two apart.
     */
    public function test_the_script_marks_the_registration_submission(): void
    {
        $html = $this->renderLoginPage('auth.example.com', true);

        self::assertStringContainsString("register: 'passkey'", $html);
        self::assertStringContainsString('register-finish', $html);
    }
}
