<?php
declare(strict_types=1);

namespace Preauth;

use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Uid\Uuid;

class Auth {
    /* the encryption cipher we are using */
    private const CIPHER = 'camellia-256-ctr';
    /* only allow A-z 0-9 _ - */
    private const URL64 = '/[^A-Za-z0-9_-]+/';
    /* cookie name */
    private const NAME = '_auth_uuid';
    /* directory to store sessions in */
    private const BASE = '/tmp/sessions/';
    /* top-level-domains which are known to have multiple parts */
    private const TLD = [
        'ai'  => ['com','net','off','org'],
        'am'  => ['radio'],
        'com' => ['br','cn','co','de','eu','gr','it','jpn','mex','ru','sa','uk','us','za'],
        'de'  => ['com'],
        'fm'  => ['radio'],
        'gg'  => ['co','net','org'],
        'in'  => ['co','firm','gen','ind','net','org'],
        'je'  => ['co','net','org'],
        'mx'  => ['com','net','org'],
        'net' => ['gb','hu','in','jp','se','uk'],
        'nz'  => ['co','net','org'],
        'org' => ['ae','us'],
        'ph'  => ['com','net','org'],
        'se'  => ['com'],
        'uk'  => ['co','me','org'],
    ];

    /** @var string $preauth subdomain (without domain) the login page will use */
    private string $preauth;
    /** @var string $domain domain (without subdomains) we are controlling auth for */
    private string $domain;
    /** @var int $expire time-to-live of auth cookie */
    private int $expire;
    /** @var array $get like $_GET, but based on data given by reverse proxy */
    private array $get = [];
    /** @var string $key secret for encryption to store sessions */
    private string $key;
    /** @var string $token secret for 2FA token */
    private string $token;
    /** @var bool $stop set to false to print login page */
    private bool $stop = true;
    /** @var string $id user provided name for their session */
    private string $id;

    /**
     * @param string $host domain which auth is relative to
     */
    public function __construct(string $host) {
        $this->preauth = getenv('PREAUTH_SUBDOMAIN') ?: 'preauth';
        $this->domain = $this->baseDomain($host);
        $this->key = base64_decode(getenv('PREAUTH_KEY') ?: '');
        $this->token = getenv('PREAUTH_TOKEN') ?: '';
        $this->expire = time() + 60 * ((int)getenv('PREAUTH_TTL') ?: 43200);
        parse_str((parse_url(($_SERVER['HTTP_X_FORWARDED_URI'] ?? ''), PHP_URL_QUERY) ?: ''), $this->get);
    }

    /**
     * @return boolean returns true if we should display login page, false if we are done
     */
    public function showTemplate(): bool {
        return ( ! $this->stop);
    }

    /**
     * @return string returns the return-to-url, if one was specified, empty string otherwise
     */
    public function getReturnTo(): string {
        $rt = $this->get['rt'] ?? '';
        if ( ! is_string($rt)) {
            $rt = '';
        }
        return $rt;
    }

    /**
     * @return string returns our current domain with all subdomains removed
     */
    public function getBaseDomain(): string {
        return $this->domain;
    }

    /**
     * Entry point of code, review request and determine course of action
     */
    public function run(): void {
        if ( ! $this->key || ! $this->token) {
            $this->die();
        }

        /* already logged in with valid session, return 200, so caddy permits request */
        $uuid = $this->getExistingUUID();
        if ($uuid) {
            echo "ok $this->id";
            return;
        }
        /* if request is valid login attempt, (set cookie and) return to where they came from */
        if ($this->login()) {
            if ($this->getReturnTo()) {
                header("Location: {$this->getReturnTo()}");
            } else if (getenv('PREAUTH_SEND_TO')) {
                header("Location: " . getenv('PREAUTH_SEND_TO'));
            }
            echo "ok $this->id";
            return;
        }

        /* not already logged in, not trying to login, but on auth page, so present login screen */
        if ($_SERVER['HTTP_HOST'] === "$this->preauth.$this->domain") {
            header('http/1.1 401 Unauthorized', true, 401);
            $this->stop = false;
        } else {
            /* not already logged in, not trying to login, and not on auth page, so send to login screen */
            $rt = rawurlencode(
                ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https') . '://' .
                ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $this->domain) .
                ($_SERVER['HTTP_X_FORWARDED_URI'] ?? '/')
            );
            header("Location: https://$this->preauth.$this->domain/?rt=$rt");
        }
    }

    /**
     * This lets us determine the base domain:
     * "service.example.co.uk" into "example.co.uk" and "service.example.com" into "example.com"
     * @param string $host domain with zero or more subdomains
     * @return string returns same domain with all subdomains removed
     */
    private function baseDomain(string $host): string {
        $parts = array_reverse(explode('.', $host));
        $keep = min(2, count($parts));
        /* check if host should retain 3 parts, due to TLD */
        if (count($parts) > 2 && isset(self::TLD[$parts[0]]) && in_array($parts[1], self::TLD[$parts[0]], true)) {
            $keep = 3;
        }
        $parts = array_reverse(array_slice($parts, 0, $keep));
        return implode('.', $parts);
    }

    /**
     * Stop, critical server config issue
     */
    private function die(): string {
        error_log('PREAUTH_KEY and/or PREAUTH_TOKEN are not set, unable to continue.');
        header('http/1.1 500 Internal Server Error', true, 500);
        include __DIR__ . '/../500.php';
        exit(0);
    }

    /**
     * @return string|null returns the existing UUID if there is one and it is valid, null otherwise
     */
    private function getExistingUUID(): ?string {
        /* filter user input */
        $encUUID = preg_replace(self::URL64, '', ($_COOKIE[self::NAME] ?? ''));

        /* session exists as a file, in the format '<iv-b64>$<session-name>', where the filename is the encoded-uuid */
        if ($encUUID && is_file(self::BASE . $encUUID)) {
            [$iv, $id, $expire] = explode('$', (file_get_contents(self::BASE . $encUUID) ?: '') . '$$');
            if ($iv) {
                /* raw-uuid means not encoded, but still encrypted */
                $rawUUID = base64_decode(strtr($encUUID, '+/', '-_'));
                $rawIV = base64_decode($iv);
                $uuid = openssl_decrypt($rawUUID, self::CIPHER, $this->key, 0, $rawIV);
                if (Uuid::isValid($uuid) && (int)$expire >= time()) {
                    $this->id = substr(preg_replace(self::URL64, '', $id), 0, 100);
                    return $uuid;
                }
                /* we have something encrypted, but it is expired or not valid, so delete the session file */
                unlink(self::BASE . $encUUID);
            }
        }
        return null;
    }

    /**
     * @return string returns a newly generated random uuid
     */
    private function newUUID(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Upon valid token and non-empty id, creates a uuid, stores session file and sets the cookie
     * @return bool returns true if token and id are provided, and token is valid, false otherwise
     */
    private function login(): bool {
        /* fields must both be filled out */
        if (isset($this->get['token'], $this->get['id']) && $this->get['token'] && $this->get['id']) {
            /* filter user input */
            $id = substr(preg_replace(self::URL64, '', $this->get['id']), 0, 100);
            $otp = TOTP::createFromSecret($this->token);
            $date = date('Y-m-d H:i:s');

            /* determine real remote-host, if local address, find next level up */
            $remoteHost = $_SERVER['REMOTE_HOST'];
            if (IpUtils::isPrivateIp($remoteHost)) {
                $remoteList = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
                $remoteIndex = array_search($remoteHost, $remoteList);
                if ($remoteIndex > 0) {
                    $remoteHost = $remoteList[$remoteIndex - 1];
                }
            }

            /* if given token is valid, login, store session file and set the cookie */
            if ($otp->now() === $this->get['token']) {
                $this->id = $id;
                $uuid = $this->newUUID();
                $rawIV = random_bytes(openssl_cipher_iv_length(self::CIPHER));
                $rawUUID = openssl_encrypt($uuid, self::CIPHER, $this->key, 0, $rawIV);
                $encUUID = strtr(base64_encode($rawUUID), '-_', '+/');
                file_put_contents(self::BASE . $encUUID, base64_encode($rawIV) . "\$$id\$$this->expire\$$date\$$remoteHost\$\n");
                setcookie(
                    self::NAME,
                    $encUUID,
                    $this->expire,
                    '/',           /* all paths      */
                    $this->domain, /* all subdomains */
                    true,          /* https only     */
                    true           /* no js access   */
                );
                error_log("[$date] successful login by id: $id");
                return true;
            }
            /* log failed logins, so we can fail2ban bad actors */
            error_log("[$date] failed login attempted by ip: $remoteHost");
        }
        return false;
    }
}

