<?php

namespace App\Trait;

use App\ConfigBag;
use OTPHP\Factory;
use OTPHP\TOTPInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait GetTotpTrait {
    protected readonly ConfigBag $config;

    protected function getTotp(): TOTPInterface {
        $otp = Factory::loadFromProvisioningUri(
            $this->config->totpUri(), $this->config->clock()
        );
        if ($otp instanceof TOTPInterface) {
            return $otp;
        }
        throw new HttpException(500, 'Internal Server Exception');
    }
}
