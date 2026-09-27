<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grants access after a successful authentication, regardless of method.
 *
 * Exists so the TOTP form and the passkey ceremony cannot drift apart: they must
 * produce identical cookies, headers and redirects, and the only reliable way to
 * guarantee that is for both to call the same code.
 */
interface SessionIssuerInterface
{
    /**
     * Record the authenticated identity according to the requested scope and
     * build the response the caller should return.
     *
     * @param string $identity the session id, as typed by the user
     * @param Scope  $scope    whether to set a cookie, an IP session, or neither
     * @param bool   $json     JSON for an AJAX caller, HTML for a form post
     */
    public function issue(string $identity, Scope $scope, Request $request, bool $json): Response;
}
