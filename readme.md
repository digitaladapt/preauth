# Preauth
For when you want to expose a web service without letting the whole world try to access it. Because sometimes you want both a belt and suspenders.

I found myself needing to make my personal Nextcloud instance available outside my VPN, but was worried since it has had authentication exploits in the past. 

So, I built a simple authentication gateway, which eventually turned into this project.

It sits between your reverse proxy and web service to add extra protection, while still being easy to access from anywhere.

## Requirements

* Docker
* Caddy (as a reverse proxy)
* a web service you want to secure

It may be possible to use some other reverse proxy, but for now, I'm going to stick with just Caddy.

There is an example Caddyfile in /docs/ and env.example file to get you started. Within the Caddyfile is a snippet, which makes it easy to wrap your web service with preauth.

When someone tries to reach your protected web service, Caddy will check with preauth if they are allowed, if their preauth cookie is missing, invalid, or expired, we will show them to a login screen.

I say login, but it's really just a TOTP code (6 digit code which changes every 30 second). But once they enter the right code,they'll get their cookie and be shown the protected service. It is also possible to allow all requests from an approved IP address, but that is disabled by default.

First time you spin up the docker container it will generate a TOTP secret (which you'll load into your authenticator app); or generate you own.

Be sure to save that TOTP secret to your docker environment, so that it persistents beyond removing the container.

### History
#### v0.0.1 (June 26th, 2024)
Started off as a single file script which was part of my caddy config. Hardcoded TOTP secret, zero flexibility, but functional. Would stay like that, quietly working in production for about a full year before any real change.
