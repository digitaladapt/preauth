# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| unreleased (v1 development) | ✅ |

## Reporting a Vulnerability

Report vulnerabilities privately to **security@digitaladapt.com** (or open a private
security advisory on the repository). Please include reproduction steps and affected
versions. You will receive an acknowledgement within 48 hours and a status update at
least weekly until resolution.

**Do not open a public issue for a suspected vulnerability.** preauth is an
authentication gateway — it sits in front of every protected service, so a
weakness here is a weakness everywhere behind it.

## Security model summary

preauth implements the auth half of the `forward_auth` pattern: a reverse proxy
calls it per request to decide whether a request may reach the upstream service.

- **Two outcomes per request: allow or intercept.** `AcceptListener` /
  `RejectListener` / `InterceptListener` decide, and the decision is made on
  every request rather than cached — a cached auth session is an anti-pattern
  (GUIDING-LIGHT §3.3d), which is also why this project gets **no service
  worker**.
- **The login flow is never cached.** The login page, failed logins, redirects
  and rate-limit responses are sent with
  `Cache-Control: no-cache, no-store, must-revalidate, proxy-revalidate, max-age=0, s-maxage=0`.
  An aggressive cache (notably older Safari) replaying a stale pre-auth response
  presents to the user as being logged back out after a refresh.
- **Headers are set by the app, not left to the proxy.** `X-Content-Type-Options:
  nosniff`, `X-Frame-Options: DENY`, a Content-Security-Policy, and
  `Strict-Transport-Security: max-age=31536000`. Docs recommend mirroring the
  caching headers at the edge as defence in depth, but the app does not depend
  on it.
- **TOTP is required.** Secrets come from `TOTP_URI`; if it is unset the app
  generates one and prints it for enrolment. Login state is carried in a signed
  payload (`src/Data/Payload.php`) bound to a nonce and a scope, not in a
  server-side session store.
- **Rate limiting is on by default**, with the block response configurable
  (`TEAPOT=false` returns 429 rather than 418).
- **`REMOTE_USER` is trusted input, not a secret.** In `remote_user` modes the
  gateway accepts an upstream-asserted identity, so the upstream must be the
  only path to the app. Do not expose preauth directly to the internet for this
  mode.
- **`.env` is never committed; secrets are env vars injected at runtime.** Real
  secrets belong in `.env.local` or `bin/console secrets:set`, read via
  `%env(...)%`. `.env.example` and `.env.test` are the committed env files.

### Passkeys (WebAuthn)

Off by default (`PASSKEY_ENABLED=0`). When on, the following hold:

- **Registration requires a valid TOTP code.** The checkbox rides on an
  ordinary login submission, and the code check in that same request is what
  authorises the ceremony. There is no enrolment token, no CLI path, and no way
  to create a credential without already holding the secret. The identity comes
  from the authenticated session, never from the request body.
- **The challenge is server-authoritative and single-use.** It is generated and
  stored server-side; the client's copy is never trusted. The stored record is
  deleted *before* verification runs, so a failed or replayed attempt cannot be
  retried against the same challenge. Records live in the in-memory `nonceCache`
  with a 300-second TTL and deliberately do not survive a restart.
- **Only the derived origin is accepted.** The allowed origin is always
  `https://{AUTH_SUBDOMAIN}`, computed from configuration and never from the
  request. `http://` is therefore rejected regardless of how the request
  arrived, and there is no setting that re-enables it. The library's deprecated
  `setSecuredRelyingPartyId()` escape hatch is not used, and development uses
  real TLS instead of an exemption.
- **The RP ID is the base domain**, so a credential is scoped to every service
  on that domain. This is the intended behaviour and the reason central auth is
  a hard prerequisite: without a single shared domain there is no sane RP ID.
- **Failures are indistinguishable.** An unknown credential, a bad signature
  and a wrong origin all produce the same response as a wrong TOTP code, so the
  endpoint cannot be used to enumerate credentials.
- **Failures share the login rate-limit budget.** A failed ceremony costs the
  same token as a wrong code, and once the limit is reached every method is
  blocked. Passkeys cannot be used to sidestep a lockout, and the resource guard
  that bounds ceremony *starts* is deliberately separate, so a legitimate login
  never spends failure budget.
- **The signature counter is not a security control.** Most passkeys —
  anything synchronised through a keychain — report a constant counter, so a
  counter-based clone check would lock users out of their own credentials.
  preauth accepts an unchanged counter and rejects only one that moves
  *backwards*, which is the only signal the value can carry. **Clone detection is
  deliberately not a property this feature claims.**
- **Attestation is deliberately not requested** (`attestation: 'none'`).
  Attestation conveyance is only a preference a client may ignore, and the FIDO
  metadata service is bypassed both by the zero AAGUID that privacy-preserving
  passkeys already send and by self attestation — while still refusing
  legitimate authenticators newer than its cached blob. This was measured rather
  than assumed; see §2.3 of
  `docs/passkey-auth-subdomain-plan.md` for the evidence. **Revisit if** a
  deployment needs to prove which make and model of authenticator is enrolled,
  or if a policy (rather than a preference) requires attested keys — in which
  case the metadata service must be pinned and kept current, and the zero-AAGUID
  case decided explicitly rather than by omission.
- **The ceremony replies are not cacheable.** They are the only 2xx this
  application returns straight to a browser (every other 2xx is consumed by the
  proxy's `forward_auth` check), so they carry the same anti-caching headers as
  the rest of the login flow.

## Scope

In scope: the application code in `src/`, the shipped `Caddyfile`, the
`Dockerfile`, and anything that affects the allow/intercept decision.

Out of scope: the `forward_auth` integration at the edge (a host-proxy
configuration concern, see `docs/examples/Caddyfile`) and the security of the
services preauth protects.

## Deployment note

preauth runs as a container and drops privileges via `USER` (Guiding Light
§6.4): the image runs as the non-root `app` user (uid/gid 1000) and owns the
state paths it needs. Only `/data` is written at runtime — the cache pools
behind sessions, backup codes and rate limiting — and `/config` is declared
because the base image points Caddy's XDG config dir there. If you pin a
different `user:` in your compose file, that user must be able to write to
both paths — otherwise login state and backup codes cannot be persisted.
