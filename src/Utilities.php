<?php
declare(strict_types=1);

namespace App;

use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;
use DateTimeImmutable;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class Utilities {
    public function __construct(
        private ClockInterface         $clock,
        private CacheItemPoolInterface $appPool,
    ) {}

    /** @throws InvalidArgumentException */
    public function loadTotp(): string {
        /* user forgot to set their TOTP_URI in the environment */
        if ($this->appPool->hasItem('totp')) {
            $totp = $this->appPool->getItem('totp')->get();
        } else {
            $totp = $this->makeTotp();
        }

        $this->showTotp($totp);
        return $totp;
    }

    /** @throws InvalidArgumentException */
    private function makeTotp(): string {
        /* we have not stored a totp into the app cache yet */
        $totpObj = TOTP::generate($this->clock);
        $totpObj->setLabel('Preauth-TOTP');
        $totp = $totpObj->getProvisioningUri();
        $totpItem = $this->appPool->getItem('totp');
        $totpItem->set($totp);
        /* per PSR6, if no expiration is set, implementation may set a default,
         * we want this to keep forever, so a few hundred years should do it */
        $totpItem->expiresAt(DateTimeImmutable::createFromFormat(
            'Y-m-d', '2999-12-31'
        ));
        $this->appPool->save($totpItem);
        return $totp;
    }

    private function showTotp(string $totp): void {
//        /* only show this at most, every 5 minutes */
//        $suppress = $this->appPool->getItem('suppress');
//        if ( ! $suppress->isHit()) {
        $writer = new Writer(new PlainTextRenderer());
        file_put_contents(
            'php://stderr', <<<RAW
            {$writer->writeString($totp)}
            $totp
            loading totp, because the env is not set, please copy above into TOTP_URI

            RAW, FILE_APPEND
        );
//            $suppress->expiresAfter(300);
//            $this->appPool->save($suppress);
//        }
    }
}
