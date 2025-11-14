# Preauth
Pre-authorization, because sometimes you need both a belt and suspenders.

The goal of this is to make it as simple as possible to put a web service behind an extra layer of authentication.

Maybe you need the extra protection because it's a very sensitive system, or because it's a legacy system with known security issues.

## Requirements

* Docker
* Caddy (as a reverse proxy)
* a domain
* a web service you want to secure 

It may be possible to use some other reverse proxy, but for now, I'm going to stick with just Caddy.

There is an example Caddyfile and example .env file to get you started. Within the Caddyfile is a snippet, which makes it easy to wrap your web service with preauth.

Preauth will need a subdomain on the same domain as the service it's securing, the default is "preauth", but you can use whatever you want.

When someone tries to reach your protected web service, Caddy will check with preauth if they are allowed, if their preauth cookie is missing, invalid, or expired, we will redirect them to a login screen.

I say login, but it's really just a TOTP code (6 digit code which changes every 30 second). But once they enter the right code,they'll get their cookie and be redirected to the protected service.

First time you spin up the docker container it will generate an encryption key for session storage, and the TOTP secret (which you'll load into your authenticator app).

Be sure to save those and add them to the containers environment, or it will generate new values every time it restarts.