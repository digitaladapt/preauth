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
