# Plan — Passkey Authentication for the Dedicated Auth Subdomain

**Status:** 📋 Draft for review — no application code written yet
**Target:** next minor release (version to confirm — see Q1.1)
**Prepared:** 2026-09-26 against `main` @ `0458d9b`
**Revised:** 2026-09-27 — review round 2 (D4/D5, §2.3)
**Verified against:** `web-auth/webauthn-lib` 5.3.9 on PHP 8.5.11 / Symfony 8.1

---

## 0. Decisions locked in (from review feedback)

Five clarifications from the project owner reshape this plan. They are
**decisions**, not options, and everything below follows from them.

| # | Decision | Consequence |
|---|---|---|
| **D1** | **Central auth (dedicated auth subdomain) is a hard prerequisite** for passkeys. Without it, a passkey would collide with / confuse the passkey for the protected service itself. | Passkeys are simply **not offered** unless `SUBDOMAIN_REDIRECT=true` *and* `AUTH_SUBDOMAIN` is set. The RP ID is *always* `authBase()`. There is no single-host passkey mode, no per-service RP ID, and no ambiguity to document away. |
| **D2** | **Registration happens in the browser**, initiated by a simple "register passkey" checkbox on the login form — not a CLI command. | Registration reuses the existing login form, nonce/CSRF machinery and TOTP verification. This also **answers the identity question**: the identity is the `Session ID` field the user already types, exactly as with TOTP. |
| **D3** | **Rate limiting covers all forms of login.** If an IP is rate-limited, that includes passkeys. | Passkey ceremonies run **behind** the existing `RejectListener` gate and consume the **same** login limiter budget on failure. No way to sidestep a lockout by switching methods. |
| **D4** | **HTTPS is required — in development too.** No "secured relying party" exemption is supported, deprecated or otherwise. | The derived allowed-origin is *always* `https://…`, built from config and never from the request. The `PASSKEY_ALLOWED_ORIGINS` escape hatch from the first draft is **deleted**. Local development uses real TLS (§4.2). HTTPS becomes part of the boot-time assertion alongside D1 (Q3.1). |
| **D5** | **Attestation is `none`, deliberately.** The "set a real value instead" instinct was tested and is wrong *here* — every alternative is either broken or bypassable (§2.3). | Records are anonymous: zero AAGUID, `EmptyTrustPath`. No metadata service, no `web-token/jwt-library` dependency, no download of the FIDO BLOB. `SECURITY.md` states the reasoning and the conditions that would change it. |

Consequences worth stating plainly:

- The separate `passkey_limiter`, `PASSKEY_ENABLED=false` default, and the whole
  "should we support single-host passkeys?" question from the first draft are
  **gone**. D1 removes the configuration matrix; D3 removes the second limiter.
- The first draft's §7 (CLI registration, enrolment tokens, `--identity`) is
  **deleted**. D2 replaces it with a checkbox.
- D4 keeps D1 exactly as strict — HTTPS is an **additional** requirement, never a
  relaxation. D5 is the one place where "use the stricter-sounding option" loses,
  and §2.3 shows the measurements behind that.

---

## 1. Goal

Let a user authenticate with a passkey (Touch ID, Windows Hello, Android
biometrics, hardware security key) instead of typing a 6-digit TOTP code —
served from the dedicated auth subdomain, where one passkey unlocks every
service on the base domain.

TOTP and backup codes remain and are never removed (Q2.1).

---

## 2. What the spike proved

The first draft contained claims that had not been executed. They now have been:
the library was installed, and a script performed a **complete registration and
assertion ceremony with real ES256 cryptography**, plus the negative cases.

**Environment:** PHP 8.5.11, Composer 2.10.3, `web-auth/webauthn-lib` **5.3.9**.
Baseline suite green before and after install: **313 tests / 738 assertions**.
`composer audit`: *"No security vulnerability advisories found."*
`.ci/conformance.sh --profile=auth-gateway`: **all 35 checks pass**.

### 2.1 Confirmed correct

| Claim | Result |
|---|---|
| Installs on PHP 8.5 + Symfony 8.1 with no conflicts | ✅ resolves to 5.3.9; `lint:container`, `lint:yaml`, `lint:twig` all pass |
| Only needs `ext-json` + `ext-openssl` | ✅ (`ext-openssl` is present in every official PHP image, so `Dockerfile` needs **no** extension work) |
| No Symfony Security bundle, no Doctrine, no bundler | ✅ `CeremonyStepManagerFactory` + `Authenticator*ResponseValidator::create()` are pure; the `webauthn-symfony-bundle` is unnecessary |
| rpId `example.com` admits an origin on `auth.example.com` | ✅ assertion ACCEPTED |
| …and also on `app.example.com` with the *same* credential | ✅ ACCEPTED — one passkey across all subdomains, as designed |
| Credential is cryptographically bound to the rpId | ✅ a forged `rpIdHash` is rejected: *"rpId hash mismatch"* |
| An origin outside the allow-list is rejected | ✅ *"Invalid origin. Not in the list of allowed origins."* |
| Wrong origin / wrong challenge rejected | ✅ `AuthenticatorResponseVerificationException` in both cases |
| CSP `publickey-credentials-*` do **not** inherit `default-src` | ✅ confirmed in the CSP3 spec (§6.8.3 fallback list omits WebAuthn directives) — the CSP change in §5.5 is required |
| base64url credential IDs survive `makeCacheKey()` without collision | ✅ 200 000 random 32-byte IDs, zero collisions |
| `attestation: 'none'` yields an anonymous record | ✅ `attestationType="none"`, zero AAGUID, `EmptyTrustPath` (§2.3, config A) |
| Origin scheme can never be inferred from the request | ✅ the scheme comes from the single allow-list string; an `https://` entry rejects an `http://` origin (§4.2) |
| `localhost` cannot accidentally enable passkeys | ✅ `baseDomain('localhost') === null` ⇒ `authBase() === null` ⇒ D1 unsatisfied (§4.2) |
| `auth.localhost` *does* satisfy D1 | ✅ `authBase() === 'auth.localhost'` (§4.2) |
| No MDS ⇒ no `web-token/jwt-library` needed | ✅ `FidoAllianceCompliantMetadataService` throws unless the JWT library is present; not installed, and MDS is not used (D5) |

### 2.2 Corrections to the first draft (things that would have bitten us)

| # | First draft said | Reality | Impact |
|---|---|---|---|
| **C1** | "`CredentialRecord` is JSON-serializable, so it fits the no-database constraint." | It is a **plain class**, not `JsonSerializable`. Persistence goes through `WebauthnSerializerFactory` (a Symfony Serializer with ~25 custom normalizers). | The store must use that factory. `symfony/serializer`, `property-info`, `property-access` arrive as transitive deps — no extra work, but the store can't just `json_encode()`. |
| **C2** | (unstated) treat option objects as plain JSON | `json_encode($creationOptions)` **throws** `JsonException: Malformed UTF-8` — the challenge is raw binary. Options **must** be serialized by the same factory, which base64url-encodes binary fields. | Both the `begin` payload and the stored record go through one `SerializerInterface`. Caught immediately by the spike; would otherwise have been a runtime 500 on first test. |
| **C3** | "the package carries 3 published advisories" | `composer audit` against 5.3.9 reports **none**. | No remediation work; record the clean audit in the CHANGELOG. |
| **C4** | counter handling not mentioned | Counter replay raises `CounterException`, which can **mask** the real reason a verification failed. | Test helper must use an incrementing counter per ceremony, or negative tests give false passes (this actually happened during the spike and had to be fixed). |
| **C5** | separate `passkey_limiter` + `publicRateLimitCache`-style pool | Decision D3 makes it redundant for the *login* budget. | Drop it. One small limiter remains, for a different purpose (§5.4). |

### 2.3 Attestation: why `none`, measured rather than assumed

The review asked the right question — *"is there any downside to `null`, and if it
needs a note in `SECURITY.md`, shouldn't we set a real value?"* — so it was
tested instead of argued. Seven configurations were run against 5.3.9
(`spike_attestation.php`, `spike_att2.php`). Results are summarised, not
predicted:

| # | Configuration | Outcome | What the server actually learns |
|---|---|---|---|
| **A** | `attestation=none`, `fmt=none` | ✅ accepted | `attestationType="none"`, aaguid all-zero, `EmptyTrustPath`. **Nothing.** |
| **B** | `attestation=direct`, `fmt=packed` **self**, no MDS | ✅ accepted | A real AAGUID string — but no metadata to interpret it against, so it is untrusted and uninterpretable. |
| **C** | `attestation=direct`, `fmt=packed` **basic** (`x5c` cert), no MDS | ❌ **rejected** | *"The Metadata Statement Repository is mandatory when requesting attestation objects."* |
| **C2** | …same, MDS enabled, metadata **empty** | ❌ **rejected** | *"The Metadata Statement for the AAGUID … is missing."* This is the real cost of MDS: **every** authenticator must be known in advance. |
| **C3** | MDS enabled, but the client sends a **zero** AAGUID | ✅ **accepted** | *"Null AAGUID detected. Skipping metadata verification."* — **MDS is bypassable by design.** |
| **C4** | MDS enabled, `fmt=packed` **self** attestation, AAGUID **unknown** to MDS | ✅ **accepted** | `processSelfAttestation()` returns early when the AAGUID has no metadata entry, so **self attestation is never refused by MDS** — even a *known-unknown* device passes. |
| **D** | `attestation=direct` requested, client sends `fmt=none` | ✅ **accepted** | Asking for `direct` does **not** compel compliance — conveyance is a *preference*, so the RP cannot force it. |

Three conclusions follow, and they are the reason D5 is `none`:

1. **Attestation cannot be *enforced*, only *requested*.** Configuration D shows a
   client answering a `direct` request with `none` and being accepted regardless.
   Any policy that depends on the client cooperating is not a security control.
2. **MDS is bypassable two different ways.** C3 is the decisive row: a zero AAGUID
   short-circuits metadata verification *before* the repository is ever consulted.
   Since passkeys from Apple/Google/Windows deliberately send zero AAGUIDs, an
   attacker can present the same shape and skip MDS entirely — while legitimate
   users are unaffected. C4 closes the remaining door on the same conclusion: with
   `fmt=packed` **self** attestation (the format a software/platform authenticator
   can produce without any vendor certificate), `processSelfAttestation()` returns
   early when the AAGUID has no metadata entry, so even a device that is *unknown*
   to MDS is accepted. Taken together: an MDS deployment refuses honest
   certificate-bearing authenticators that postdate its cached BLOB (C2), while
   still admitting the bypassable and self-attested cases. That is the worst
   combination — friction for legitimate users, no assurance gained.
3. **`none` is not a weaker version of the same check — it is the honest
   description of reality.** The property that actually protects users is that the
   credential is cryptographically bound to the RP ID and origin (§2.1), which
   holds identically in every row above. Attestation answers *"which device model
   is this?"* — a question this project does not need to answer, because it does
   not run a device-allow-list policy.

**What a real value would actually cost**, for the record: `direct` requires the
metadata repository (C) — verified as a hard failure, not a warning — which means
`web-token/jwt-library`, `symfony/http-client`, a periodic download of the FIDO
Alliance BLOB, certificate-chain validation on every registration, and a new
failure mode where a legitimate new phone is **rejected at enrolment** because its
AAGUID postdates the cached BLOB. All of that to gain a bypassable signal.

> **Where to revisit this.** D5 is the right call *for a self-hosted
gateway that does not distinguish devices*. It stops being the right call if the
project ever wants to (a) refuse specific authenticator models, or (b) prove
enrolment happened on hardware rather than a synced passkey. Both would require
MDS **plus** a decision to reject zero AAGUIDs — which is why the reasoning is
recorded in `SECURITY.md` rather than left implicit in a constant.

---

## 3. The flow, end to end

### 3.1 First-time setup (D2 — in the browser)

```
Browser → https://app.example.com/dashboard
  forward_auth → preauth (host=app.example.com) → InterceptListener
     matchesAuth() && host !== auth subdomain
  ⇒ 303 https://auth.example.com/?return=https%3A%2F%2Fapp.example.com%2Fdashboard

Browser → https://auth.example.com/?return=…
┌──────────────────────────────────────────────────────┐
│  Pre-Authentication System                           │
│                                                      │
│  Session ID:          [ lyra              ]          │
│  Authentication Token:[ 123456            ]          │
│  [x] Register this device as a passkey   ← new       │
│                    [ Submit ]                        │
└──────────────────────────────────────────────────────┘
```

The checkbox only appears when passkeys are available (D1 satisfied) — see §4.

**Submission with the box ticked** becomes a three-step ceremony:

```
1. POST / (auth host), form fields username+totp+nonce+register=passkey
     LoginListener → LoginManager verifies TOTP/backup code + nonce   [unchanged]
     ↳ instead of issuing a session, it starts a REGISTRATION ceremony:
       stores  passkey_reg_<cid> → { challenge, identity, userHandle }  (TTL 300s)
     ⇐ 200 JSON { register: { publicKey: <options>, ceremonyId: <cid> } }

2. Browser: navigator.credentials.create({ publicKey: options })
     → user approves with Touch ID / Windows Hello / security key

3. POST / (auth host)  X-Preauth-Passkey: register-finish
     body: { ceremonyId, credential: <attestation JSON> }
     PasskeyListener → PasskeyManager verifies attestation against the stored
       challenge; stores the credential under the identity from the record
     ⇐ 303 Location: <return url>  +  Set-Cookie: __Http-Domain-Preauth=…
```

The TOTP check in step 1 is what authorises registration. There is no separate
enrolment token, no CLI, and **no way to create a credential without already
holding a valid TOTP code** — which is exactly the security property the CLI
design was reaching for.

> **Implementation note — where the hand-off actually goes.**
> `LoginListener::onKernelRequest()` is a straight chain: it builds a `Payload`,
> calls `$this->loginManager->checkToken(...)`, and on a non-null response it
> does `$event->setResponse($response); return;` — on `null` it immediately
> scores a failure and consumes a rate-limit token. There is no "authenticated
> but do not issue a session" branch to hook.
>
> So the clean split is: `LoginListener` detects `register=passkey` in the POST
> body and marks the **`Payload`** with the intent; `LoginManager::checkToken()`
> verifies TOTP/backup-code **and the nonce** exactly as it does today, and only
> then, if the intent is set, delegates to the registration ceremony instead of
> issuing a session. That keeps the nonce/CSRF guarantee in the one place that
> already enforces it — the alternative (starting a ceremony from the listener
> before `checkToken` runs) would move nonce validation and would need care to
> avoid double-spending it.

### 3.2 Everyday login (assertion)

```
Browser → https://auth.example.com/?return=…
  [ 🔑 Sign in with a passkey ]        ← button, one tap
  ─────────── or use a code ───────────
  Session ID: [ … ]  Token: [ … ]  [ Submit ]

Passkey button:
1. POST /  X-Preauth-Passkey: login-begin
     ⇐ 200 JSON { publicKey: { challenge, rpId, allowCredentials[], … },
                  ceremonyId }
2. navigator.credentials.get({ publicKey })
3. POST /  X-Preauth-Passkey: login-finish   body: { ceremonyId, credential }
     PasskeyManager verifies the assertion against the stored record
     ⇐ 303 + cookie, or 401 JSON { message, nonce }
```

No username is typed: the credential carries its own identity (stored at
registration). `allowCredentials` lists all registered credentials, so the OS
picker decides which device to use.

### 3.3 Listener priority (D3)

```
Priority  Listener                 Action
────────  ─────────────────────    ─────────────────────────────────────────
99        AcceptListener           Valid cookie → 200 OK
88        AllowListener            Valid IP session → 200 OK
84        PublicAccessListener     Public path + rate limit → 200/429
77        RejectListener           LOGIN RATE-LIMIT GATE → 418/429
70        PasskeyListener  (new)   WebAuthn ceremony → JSON
66        LoginListener            TOTP / backup-code login
55        InterceptListener        Fallback → redirect or login page
```

**Why 70 — after `RejectListener` and before `LoginListener`:**

- **After 77 (D3):** a rate-limited IP is refused *before* any ceremony can
  start. Passkeys cannot be used to sidestep a lockout. This is the whole point
  of the reviewer's third clarification, and it reverses the first draft.
- **Before 66:** essential. `LoginListener` treats *any* POST to the auth
  subdomain as a login attempt (`$domainManager->getAuthSubdomain() === $host`).
  A ceremony `finish` POST has no `username`/`totp`, so `Payload::load()` returns
  `null` and the request would be scored as a **failed login and burn a rate-limit
  token**. `PasskeyListener` must claim the request first.

`PasskeyListener` sets a response for *every* request carrying its header —
including malformed ones — so control never falls through to
`InterceptListener`, which would render HTML to a `fetch()` caller. (Q3.2)

---

## 4. Availability rule (D1 + D4)

Passkeys are offered **only** when all of these hold:

```php
$passkeysAvailable =
       $config->passkeyEnabled()          // PASSKEY_ENABLED=1  (default 0)
    && null !== $domainManager->authBase() // SUBDOMAIN_REDIRECT=1 && AUTH_SUBDOMAIN set
    && $domainManager->getAuthSubdomain() === $request->getHost();  // we are ON the auth host
```

…and, separately, the **deployment** must satisfy HTTPS (D4). That is checked
once at boot rather than per request, because "is this request HTTPS" is not the
right question behind a TLS-terminating proxy — see §4.2.

Consequences:

- **RP ID is always `authBase()`** — never the request host, never configurable
  per-service. `example.com` for `auth.example.com`.
- **Allowed origins is exactly one entry**: `https://{AUTH_SUBDOMAIN}`, built
  from config. Because `InterceptListener` funnels every unauthenticated user to
  the auth host, no other origin ever needs to run a ceremony. This is the
  tightest configuration that still delivers "one passkey, every service" (§2.1).
- On a protected host, `InterceptListener` already redirects before rendering a
  login page, so the checkbox is naturally absent there.
- If someone sets `PASSKEY_ENABLED=1` without central auth, the app must
  **fail loudly at boot**, not silently ignore it (Q3.1). A silent ignore is how
  you get "I enrolled a passkey and now I can't log in" support tickets.

`rpName` for the OS prompt defaults to `TITLE`.

### 4.1 Identity and userHandle

The identity is the `Session ID` the user typed — the same value TOTP uses, so
`Remote-User` modes (`session`/`static`/`mapped`) keep working unchanged.

- `userHandle` = `hash('sha256', $identity, true)` (32 raw bytes). Fixed length,
  stable per identity, and does not leak the label into the authenticator.
- On assertion, the identity is read from the **stored credential record**, not
  from the client-returned `userHandle`. The client's copy is never trusted.
- Because registration is gated behind a successful TOTP login, one identity
  cannot be registered by someone who does not already hold the TOTP secret.

### 4.2 HTTPS (D4) — enforced, not exempted

D4 removes the exemption system entirely: **there is no code path that accepts an
`http://` origin for passkeys**, and no configuration that re-enables one. The
library's `setSecuredRelyingPartyId()` (deprecated since 5.2, confirmed in
`CeremonyStepManagerFactory`) is **never called**.

Measured behaviour of the origin check (`spike_origin.php`), all with rpId
`example.com`:

| Allowed origins | Client origin | Result |
|---|---|---|
| `https://auth.example.com` | `https://auth.example.com` | ✅ accepted |
| `http://localhost:8000` | `http://localhost:8000` | ✅ accepted — **only** because `http://` was explicitly allow-listed |
| `localhost:8000` (host-only) | `http://localhost:8000` | ❌ rejected |
| `https://auth.example.com` | `http://auth.example.com` | ❌ rejected |
| `https://example.com` +subdomains | `https://app.example.com` | ✅ accepted |
| `https://example.com` +subdomains | `http://app.example.com` | ❌ rejected |
| `https://example.com` (no subdomains) | `https://app.example.com` | ❌ rejected — *"Subdomains are not allowed."* |

The scheme is therefore never inferred from the request; it comes from the single
`https://{AUTH_SUBDOMAIN}` string. Note the second row — the library *will* accept
plain HTTP **if the operator writes it into the allow-list**, which is precisely
the hole D4 closes by deleting `PASSKEY_ALLOWED_ORIGINS`.

**Two gotchas this creates for local development**, both verified against
`DomainManager` (`spike_devhost.php`):

1. `baseDomain('localhost')` returns **`null`** by design, so `authBase()` is also
   `null` and **`localhost` can never satisfy D1** — passkeys stay off there no
   matter what. `auth.localhost`, by contrast, resolves to `authBase()` of
   `auth.localhost` and *does* satisfy D1.
2. Because the origin must be `https://`, dev cannot simply point a browser at
   `http://auth.localhost`. The supported dev workflow is therefore **a local TLS
   certificate**, not an exemption:

```
# Development with real TLS — the only supported way to exercise passkeys
AUTH_SUBDOMAIN=auth.preauthtest.local
SUBDOMAIN_REDIRECT=true
PASSKEY_ENABLED=1
# /etc/hosts → 127.0.0.1 auth.preauthtest.local app.preauthtest.local
# mkcert auth.preauthtest.local app.preauthtest.local
# Caddy terminates TLS with the mkcert cert and reverse_proxies to :80
```

This is a **documentation and CI** change, not an application-code change: the app
already sits behind a TLS-terminating proxy in production (`docker/Caddyfile`
serves plain HTTP on `:80`, `trusted_headers` includes `x-forwarded-proto`), so
D4 adds no runtime branching. `docs/examples/Caddyfile` gains a TLS-enabled
development block, and the functional tests (§7.2) drive the HTTPS origin directly
because they build `clientDataJSON` by hand — no real TLS needed in the suite.

> **Not `localhost`.** Because D4 forbids `http://`, the classic
> `http://localhost` dev story simply does not apply to passkeys. `localhost` is
> treated as *"passkeys unavailable"*, which keeps D1 intact instead of carving
> out an exception that would then need its own tests.

---

## 5. Design detail

### 5.1 `PasskeyManager` (new service)

Owns both ceremonies. Library types stay inside this class so a future v6 rename
touches one file.

```php
final readonly class PasskeyManager implements PasskeyInterface
{
    public function beginLogin(Request $request): array;        // → options + ceremonyId
    public function finishLogin(array $body, Request $request): ?Response;
    public function beginRegistration(string $identity, Request $request): array;
    public function finishRegistration(array $body, Request $request): ?Response;
}
```

Built on the verified recipe:

```php
$attestationManager = AttestationStatementSupportManager::create();
$attestationManager->add(NoneAttestationStatementSupport::create());   // D5 (§2.3)

$csm = new CeremonyStepManagerFactory();
$csm->setAllowedOrigins(["https://{$domainManager->getAuthSubdomain()}"]);
$csm->setAlgorithmManager(AlgorithmManager::create()->add(ES256::create()));
$csm->setAttestationStatementSupportManager($attestationManager);

$attestationValidator = AuthenticatorAttestationResponseValidator::create($csm->creationCeremony());
$assertionValidator   = AuthenticatorAssertionResponseValidator::create($csm->requestCeremony());
$serializer           = (new WebauthnSerializerFactory($attestationManager))->create();
```

- `setSecuredRelyingPartyId()` is **deprecated in 5.2** (confirmed in the source,
  `@deprecated since 5.2.0 … Use setAllowedOrigins instead`) — **never called
  (D4)**. Development uses real TLS, not an exemption (§4.2).
- `attestation: 'none'` for registration **(D5, §2.3)**; no metadata service, so
  neither `web-token/jwt-library` nor `symfony/http-client` is needed — the
  latter confirmed absent from the current install, so reaching for MDS would add
  a second new dependency, not just code.
- Counter: keep the library default; document that clone detection is not relied
  upon (Q3.5). Test helpers must increment (C4).

### 5.2 Ceremony state

Stored in the **`nonceCache`** pool (already APCu, already excluded from
`kernel.reset` in `TestKernel`, already short-lived, and — correctly — *not*
persisted to disk, so ceremonies do not survive a restart):

```
passkey_cer_<ceremonyId> → { type: 'login'|'register',
                             challenge: <base64url>,
                             identity?: string,      // register only
                             userHandle?: string,    // register only
                             returnUrl?: string,
                             createdAt: <iso8601> }   TTL 300s
```

- `ceremonyId` is a fresh 15-byte base64url string, issued to the client. The
  client's copy of the challenge is **never** trusted; the server-side record is
  authoritative.
- **Single-use**: deleted on read at `finish`, before verification, so a failed
  or replayed assertion cannot be retried against the same challenge.
- TTL 300 s (5 min) rather than the nonce's 120 s, because a user has to
  interact with a biometric prompt.

### 5.3 Credential store (new service)

```php
final readonly class PasskeyCredentialStore implements PasskeyCredentialStoreInterface
{
    public function all(): array;                       // for allowCredentials
    public function find(string $credentialId): ?array;  // record + metadata
    public function save(CredentialRecord $record, string $identity, string $label): void;
    public function updateCounter(CredentialRecord $record): void;
    public function remove(string $credentialId): bool;
    public function count(): int;
}
```

Cache layout in **`sessionCache`** (the persisted pool):

```
passkey_cred_<makeCacheKey(credentialId)> → { record: <serialized CredentialRecord>,
                                              identity: string,
                                              label: string,
                                              createdAt: iso8601,
                                              lastUsedAt: iso8601|null }
passkey_index                             → { <credentialId>: {identity, label, createdAt}, … }
```

> ⚠️ **Verified gotcha.** `PersistCache::persist()` only flushes keys recorded by
> a `MonitorCacheKeys` instance, and it watches `sessionCache`. `LoginManager`
> and `BackupCodeManager` therefore each wrap their injected pool:
> `$this->sessionCache = new MonitorCacheKeys($sessionCache);`.
> `PasskeyCredentialStore` **must do the same**, or credentials live only in APCu
> and vanish on the next container restart — a bug that would surface only after
> a redeploy. Add an explicit test asserting the write is visible in the
> underlying persistent pool.

`passkey_index` avoids scanning the whole key space for the login page's
`allowCredentials` list.

### 5.4 Rate limiting (D3)

**No new limiter for the login budget.** Instead:

| Event | Limiter behaviour |
|---|---|
| Any request to the auth host, incl. `*-begin` | `RejectListener` (77) gates first — a blocked IP never reaches `PasskeyListener` |
| `login-finish` **failure** | consumes `login_limiter` (1 token) — identical to a wrong TOTP code |
| `register-finish` **failure** | consumes `login_limiter` |
| successful ceremony | consumes nothing |
| `*-begin` | not consumed (a legitimate login must not burn failure budget) |

This satisfies "if the login attempt has been rate limited, that would include
all forms of login": after 10 failures the IP is blocked for *every* method, and
failures from any method count toward the same 10.

To stop `begin`-spam from filling the cache with ceremony records, add **one**
small limiter that bounds *starts* only — it is a resource guard, not the auth
budget:

```yaml
passkey_begin_burst:
    policy: 'sliding_window'
    limit: '%env(int:PASSKEY_BEGIN_BURST_COUNT)%'    # default 30
    interval: '%env(int:PASSKEY_BEGIN_BURST_TIME)% seconds'   # default 60
    cache_pool: 'passkeyRateLimitCache'
```

plus a `passkeyRateLimitCache` pool (APCu in prod, array in test) and an entry in
`tests/TestKernel`'s reset-exclusion list. On over-limit, `begin` answers
`429` with `Retry-After`, matching `PublicAccessListener`. (Q3.6 asks whether this
guard is wanted at all.)

### 5.5 Response caching and CSP

**Caching.** `SecurityHeadersListener` sets `no-store` only on *non-2xx*
responses, on the assumption that 2xx is consumed by `forward_auth`. That is
false here: `begin` returns a **`200` JSON body straight to the browser**, and
the auth subdomain is `reverse_proxy`-ed with no `forward_auth` in front of it at
all. Ceremony responses must therefore be no-store too. Proposed: `PasskeyListener`
marks them with an internal `X-Preauth-Ceremony` header, and
`SecurityHeadersListener` turns that into the full no-store set and strips the
marker — keeping the caching policy in the one place that owns it. (Q3.7)

**CSP.** `publickey-credentials-get` / `publickey-credentials-create` do **not**
fall back to `default-src` (confirmed, §2.1), and the current policy is
`default-src 'none'`. When passkeys are available the policy becomes:

```
default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline';
connect-src 'self'; publickey-credentials-get 'self'; publickey-credentials-create 'self';
```

`connect-src 'self'` must be added **in both modes** — today it is added only for
the inline (non-auth-subdomain) case, but the passkey flow always uses `fetch()`.
When passkeys are unavailable the header is byte-identical to today.

### 5.6 Templates and script

| File | Purpose |
|---|---|
| `templates/_passkey.html.twig` | "Sign in with a passkey" button + `navigator.credentials.get()` handler |
| `templates/login.html.twig` | gains the checkbox (register) and includes the button, both only when available |
| `templates/_passkey_register.html.twig` | `navigator.credentials.create()` handler, driven by the JSON returned in step 1 of §3.1 |

`_script.html.twig` keeps its existing submit handler; ticking the checkbox
switches the submit into the registration branch. Kept as separate templates so
the "passkeys unavailable ⇒ byte-identical login page" property stays testable.

Base64url helpers must mirror the library's encoding exactly (no padding,
`-`/`_` alphabet); the spike's working script is the reference.

### 5.7 Configuration

| Variable | Default | Notes |
|---|---|---|
| `PASSKEY_ENABLED` | `0` | master switch; requires **D1 and D4** or the app fails at boot (Q3.1) |
| `PASSKEY_RP_NAME` | `%env(TITLE)%` | shown by the OS prompt |
| `PASSKEY_USER_VERIFICATION` | `required` | `required`/`preferred`/`discouraged` |
| `PASSKEY_TIMEOUT` | `60000` | ms, passed to the browser |
| `PASSKEY_BEGIN_BURST_COUNT` / `_TIME` | `30` / `60` | §5.4 resource guard |
| `PASSKEY_BUTTON_NAME` | `Sign in with a passkey` | styling-option family |
| `PASSKEY_REGISTER_NAME` | `Register this device as a passkey` | checkbox label |

> **Deleted by D4:** `PASSKEY_ALLOWED_ORIGINS`. The allowed origin is always
> derived as `https://{AUTH_SUBDOMAIN}` and there is no override — see §4.2.

Defaults preserve today's behaviour exactly.

---

## 6. Files

**New**

| File | Purpose |
|---|---|
| `src/Service/PasskeyManager.php` + `PasskeyInterface.php` | both ceremonies, library types contained |
| `src/Service/PasskeyCredentialStore.php` + interface | cache-backed records + index |
| `src/Listener/PasskeyListener.php` | priority 70, header dispatch, JSON |
| `templates/_passkey.html.twig` | login button + assertion script |
| `templates/_passkey_register.html.twig` | registration script |
| `tests/Support/PasskeyTestHelper.php` | ES256 generator, ceremony builder, incrementing counter (C4) |
| `tests/Unit/Service/PasskeyManagerTest.php` | ceremony control flow |
| `tests/Unit/Service/PasskeyCredentialStoreTest.php` | storage, index, persistence, key collisions |
| `tests/Unit/Listener/PasskeyListenerTest.php` | every branch |
| `tests/Functional/PasskeyFlowTest.php` | real crypto end-to-end (§7.2) |

**Changed**

| File | Change |
|---|---|
| `composer.json` / `composer.lock` / `symfony.lock` | `web-auth/webauthn-lib: ^5.3` (done on the spike branch) |
| `phpunit.dist.xml` | recipe-added `doctrine/deprecations` triggers (spike artefact — keep) |
| `config/packages/property_info.yaml` | recipe-added (spike artefact — keep) |
| `config/services.yaml` | `app.passkey_*` parameters |
| `config/packages/rate_limiter.yaml` | `passkey_begin_burst` |
| `config/packages/cache.yaml` + `test/cache.yaml` | `passkeyRateLimitCache` |
| `config/packages/twig.yaml` | passkey globals |
| `src/ConfigBag.php` | `passkeyEnabled()`, `rpName()`, `userVerification()`, `timeout()`, labels |
| `src/Kernel.php` or a compiler pass | boot-time check that **D1 and D4** hold when enabled (Q3.1, §4.2) |
| `src/Listener/SecurityHeadersListener.php` | CSP additions; ceremony no-store marker |
| `src/Listener/LoginListener.php` | detect `register=passkey` on the POST and mark the `Payload` with the intent (see §3.1 note) |
| `src/Service/LoginManager.php` | on success-with-intent, delegate to the registration ceremony instead of issuing a session; extract the session-issuing tail (Q3.8) |
| `templates/login.html.twig` | checkbox + button |
| `tests/TestKernel.php` | `passkeyRateLimitCache` in the reset-exclusion list |
| `tests/Support/ListenerTestHelper.php` | passkey limiter factory |
| `.env.test`, `docs/examples/.env.example`, `docs/examples/Caddyfile`, `docs/examples/compose.yaml` | config + docs; **TLS dev block** (§4.2) |
| `readme.md`, `CHANGELOG.md`, `ROADMAP.md`, `SECURITY.md`, `DESIGN_CONSIDERATIONS.md` | §9 |

---

## 7. Testing

### 7.1 Reusing the spike

`tests/Support/PasskeyTestHelper.php` is the spike's working code, refactored:
ES256 keypair → COSE key → `authenticatorData` → sign → JSON. Two rules learned
the hard way:

- **Increment the counter every ceremony** (C4), or a negative test can pass for
  the wrong reason (`CounterException` masking the real failure).
- **Serialise options through `WebauthnSerializerFactory`**, never `json_encode` (C2).

### 7.2 Cases

| Case | Expected |
|---|---|
| Register on `auth.example.com` (rpId `example.com`), then assert from the same host | ✅ 303, `__Http-Domain-Preauth`, `Domain=example.com`, `Remote-User` |
| Assert the same credential from `app.example.com` | ✅ success by design — asserted explicitly so the scope is documented in code |
| Register while `PASSKEY_ENABLED=0` / without central auth | ❌ checkbox absent; `begin` inert; no cache writes |
| Registration submitted with a **bad TOTP** | ❌ 401, no ceremony started, login limiter consumed |
| Registration with a **spent nonce** | ❌ 401, no ceremony |
| `begin` from a rate-limited IP | ❌ 418/429 from `RejectListener`, never reaches passkey code |
| Failed assertion | ❌ 401, **login limiter consumed** (D3) |
| Failed assertion ×  N, then a correct TOTP | ❌ still blocked — shared budget |
| Assertion replayed with the same `ceremonyId` | ❌ 401 (record deleted on read) |
| Unknown `credentialId` | ❌ 401, same generic message as a bad TOTP (no enumeration) |
| Origin not in the allow-list | ❌ 401 (`Invalid origin…`) |
| **`http://` origin with the derived `https://` allow-list** | ❌ 401 — D4; asserted explicitly so the exemption cannot creep back |
| **`PASSKEY_ALLOWED_ORIGINS` is not consulted** | ❌ setting it has no effect (D4) |
| **`PASSKEY_ENABLED=1` with `AUTH_SUBDOMAIN=localhost`** | ❌ boot failure — D1 unsatisfiable (§4.2) |
| **`PASSKEY_ENABLED=1` on plain HTTP deployment** | ❌ boot failure — D4 (Q3.1) |
| Forged `rpIdHash` | ❌ 401 (`rpId hash mismatch`) |
| Zero AAGUID / self attestation payload | ✅ accepted exactly as a `none` record would be — documents D5's reasoning in code |
| Ceremony responses | ✅ full no-store header set |
| Login page when passkeys unavailable | ✅ byte-identical to today |
| Persistence | ✅ a saved credential is present in the **persistent** pool, not just APCu |
| `begin` spam | ✅ bounded by `passkey_begin_burst` |

### 7.3 Gates

Baseline to preserve: **313 tests / 738 assertions**, 100 % line/method/class
coverage, `phpstan` level 6 clean, `php-cs-fixer` clean, `composer audit` clean,
conformance 35/35. Note `phpunit.dist.xml` runs with `failOnDeprecation=true`, so
deprecations from the new dependency must be watched (the recipe already added
the `doctrine/deprecations` triggers).

---

## 8. Implementation order

Each step is independently committable and leaves the suite green.

1. **Dependency** *(done on the spike branch)* — `composer require
   web-auth/webauthn-lib`; suite + lints + audit + conformance verified.
2. **Availability + config** — `ConfigBag` accessors, `services.yaml`, boot-time
   **D1 + D4** assertion, Twig globals, test env. Feature fully inert; assert the
   login page is unchanged. Includes the TLS development setup in
   `docs/examples/` (§4.2), so contributors can exercise the feature locally.
3. **Credential store** — with `MonitorCacheKeys` wrapping and the persistence
   test. No WebAuthn types needed yet (`CredentialRecord` can be stubbed).
4. **`PasskeyManager`** — both ceremonies, ceremony state, single-use deletion,
   limiter consumption on failure. Unit-tested with a stubbed validator.
5. **`PasskeyListener`** — priority 70, header dispatch, always terminate,
   no-store marker. Unit-test every branch incl. "post-shaped request must not
   reach `LoginListener`".
6. **Extract session issuing** from `LoginManager` so both paths share it —
   prove equality against the existing `LoginManagerTest`/`AuthenticationFlowTest`
   before touching anything else (Q3.8).
7. **Registration UI** — checkbox in `login.html.twig`, the `Payload`-intent
   hand-off described in §3.1, `_passkey_register.html.twig`.
8. **Login UI + CSP** — `_passkey.html.twig`, `SecurityHeadersListener`, extend
   `CacheControlFlowTest` and `SecurityHeadersListenerTest`.
9. **Functional tests** with real crypto (§7.2).
10. **Docs** (§9) and **final gates**, then PR to `main`.

---

## 9. Documentation

| File | Update |
|---|---|
| `readme.md` | "Passkey Authentication" section: **the central-auth prerequisite**, **the HTTPS requirement (development included)**, enabling, the checkbox, the passkey button, RP ID, fallbacks |
| `CHANGELOG.md` | `[Unreleased]` `Added`/`Security`; record library 5.3.9 and the clean audit |
| `ROADMAP.md` | Phase 2c done, noting the deviations from the original sketch (browser registration, no bundle, D1/D3/D4/D5) |
| `SECURITY.md` | ceremony model, challenge TTL/one-shot, RP ID scope, **the D5 attestation rationale and the conditions that would reverse it (§2.3)**, HTTPS-only origins, counter caveat, shared rate-limit budget |
| `DESIGN_CONSIDERATIONS.md` | the 2xx-caching gap; `CredentialRecord` serialization; the shared-limiter decision; **why attestation was deliberately declined** |
| `docs/examples/.env.example` | new variables; **note that `PASSKEY_ALLOWED_ORIGINS` does not exist by design** |
| `docs/examples/Caddyfile` | auth-subdomain block already `reverse_proxy`-ed; **add a TLS-enabled development block (§4.2)** and note why the plain-HTTP shortcut is not offered |

---

## 10. Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | RP ID / origin misconfiguration | D1 removes the matrix: RP ID is always `authBase()`, origins is always the auth host. Asserted by tests. |
| R2 | Ceremony responses cached (first browser-facing 2xx) | §5.5 marker + `CacheControlFlowTest` cases |
| R3 | CSP blocks the ceremony | §5.5 directives; verify in a real browser during staging (Q3.9) |
| R4 | Library churn (v5 renamed types; `setSecuredRelyingPartyId` deprecated) | pin `^5.3`; library types contained in `PasskeyManager`; avoid deprecated calls |
| R5 | Credential loss on restart | `MonitorCacheKeys` wrap + explicit persistence test (§5.3) |
| R6 | Non-technical users lose their passkey device | TOTP/backup codes unchanged and always available; the checkbox is opt-in |
| R7 | `begin` cache-fill | §5.4 resource guard |
| R8 | New transitive deps (`symfony/serializer`, `property-info`) | already installed as part of the spike; container lint passes |
| R9 | **A deployment enables passkeys without TLS, and the feature silently half-works** | D4 + the extended boot assertion (§4.2, Q3.1): `PASSKEY_ENABLED=1` in a non-HTTPS configuration **fails at `cache:warmup`** instead of failing later in the browser |
| R10 | **"We should verify the device" creeps back in as a requirement** | §2.3 records the measurements and the two conditions that would justify revisiting; a functional test asserts a zero-AAGUID payload is handled deliberately, so any change is a visible, reviewed diff |

---

## 11. Remaining open questions

D1–D5 removed most of the first draft's 22 questions. These are what is left;
each has a proposal, so "yes" is a valid answer.

**Q1.1 — Version target.** `CHANGELOG.md`'s `[Unreleased]` heading still says
v1.1 while git tags reach `v1.3.0`. Target the next minor and repair the heading
in a separate labelled commit? *Proposal: yes.*

**Q1.2 — Where the checkbox appears.** *Proposal: always visible when passkeys
are available (same as the login button), since a user who has just landed on
the auth page is exactly the person most likely to be enrolling a new device.*

**Q1.3 — What if the same device registers twice** (same identity, second
passkey)? *Proposal: allow it — the OS may legitimately create a second
credential, and `excludeCredentials` will let the authenticator dedupe. `Q2.6`
of the first draft (a cap) becomes: cap at a configurable N (default 20).*

**Q2.1 — Re-confirm: TOTP stays?** *Proposal: yes, unchanged, and never
disabled by enabling passkeys.*

**Q3.1 — How to enforce the D1 prerequisite.** Boot-time hard failure when
`PASSKEY_ENABLED=1` without central auth, or log a warning and disable?
*Proposal: **hard failure** at container start (`cache:warmup`) — a silent
disable is how you get "my passkey stopped working" tickets.* **Extended by D4:**
the same boot check also asserts HTTPS, so "enabled but unusable" cannot ship.
The check is on **configuration**, not on the request, because behind a TLS
terminating proxy `isSecure()` is not authoritative (§4.2).

**Q3.2 — Always terminate a ceremony with JSON?** *Proposal: yes — any request
carrying `X-Preauth-Passkey` gets a JSON response, never the HTML login page.*

**Q3.3 — Attestation policy.** **RESOLVED — D5: `none`.** Measured, not assumed:
`direct` cannot be enforced (config D), MDS is bypassable by the zero AAGUIDs that
real passkeys send (config C3), and requiring MDS would reject legitimate new
authenticators (config C2) while adding two dependencies. Full evidence and the
conditions that would reverse it are in **§2.3**. *Set a real value instead*
was considered and rejected on the evidence.

**Q3.4 — Local development over HTTP.** **RESOLVED — D4: not supported.** No
`securedRelyingPartyId` exemption, deprecated or otherwise; local development uses
real TLS with a local certificate (§4.2). `PASSKEY_ALLOWED_ORIGINS` is deleted.
Note `localhost` deliberately cannot satisfy D1, so there is no half-configured
state to document away.

**Q3.5 — Counter checking.** *Proposal: keep the library default; document that
counter-based clone detection is not relied upon (many passkeys always report 0).*

**Q3.6 — Keep the `begin` resource guard?** It is not part of the login budget
(D3 governs that) — it only bounds cache-fill. *Proposal: keep it; it is ~15
lines and mirrors the existing `public_limiter` pattern.*

**Q3.7 — Caching-policy mechanism.** `X-Preauth-Ceremony` marker header consumed
by `SecurityHeadersListener` (keeps cache policy in one place), or set headers
directly in `PasskeyListener`? *Proposal: the marker.*

**Q3.8 — Extract the session-issuing tail from `LoginManager`?** *Proposal: yes,
as its own commit — duplicating cookie/redirect/`Remote-User` logic is how the
two paths drift.*

**Q3.9 — Browser matrix.** Which browsers must be verified by hand on staging
(iOS Safari, Chrome, Firefox, and a hardware key) before release? *Proposal: all
four; note the CSP directive is the most likely divergence.*

---

### Resolved in this round

| Question | Resolution |
|---|---|
| Q3.3 — attestation value | **D5: `none`**, with measurements in §2.3 |
| Q3.4 — dev over HTTP | **D4: real TLS only**; `PASSKEY_ALLOWED_ORIGINS` deleted (§4.2) |
| Q3.1 — boot check scope | extended to assert **D1 *and* D4** |

---

## 12. Rollback

- `PASSKEY_ENABLED=0` (the default) makes the feature inert; reverting is
  redeploying the previous image tag. No migrations.
- If passkeys were enabled and are rolled back, credential records remain in
  `sessionCache`/filesystem under `passkey_*` keys, unread by the old code.
  Sessions continue to work; nothing is invalidated.
- The dependency addition reverts with `composer.lock`.

---

## 13. Notes for the reviewer

- **Round 2 added D4 (HTTPS required, no exemptions) and D5 (attestation stays
  `none`, on measured evidence).** D4 is covered in §4.2, D5 in §2.3; the two
  questions that drove them are marked resolved in §11.
- Three claims in this revision were **measured, not reasoned**: the attestation
  matrix (§2.3), the origin/HTTPS behaviour (§4.2), and the `localhost` × D1
  interaction (§4.2). Scripts: `spike_attestation.php`, `spike_att2.php`,
  `spike_origin.php`, `spike_devhost.php`.
- Incidentally confirmed while testing: `symfony/http-client` is **not** in the
  current install, so MDS would have been a second new dependency, not a drop-in.
- The spike branch (`spike/passkey-deps`) currently carries `composer.json`,
  `composer.lock`, `symfony.lock`, `phpunit.dist.xml` and
  `config/packages/property_info.yaml` changes. Decide whether step 2 continues
  on that branch or starts fresh from `main`.
- The spike scripts themselves were **removed** from the working tree (kept in
  `/tmp/spike-backup/` for reference) so they never reach a PR; the reusable
  parts are folded into `tests/Support/PasskeyTestHelper.php` in step 9.
- The environment details (PHP 8.5.11 via Sury, Composer, `pcov`) are local to
  this container and are not a project change.

---

*End of plan.*
