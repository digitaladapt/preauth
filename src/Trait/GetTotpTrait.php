<?php

declare(strict_types=1);

namespace App\Trait;

use App\ConfigBag;
use OTPHP\Factory;
use OTPHP\TOTPInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Service\Attribute\Required;

trait GetTotpTrait
{
    protected readonly ConfigBag $config;

    #[Required]
    public function setConfig(ConfigBag $config): void
    {
        $this->config = $config;
    }

    protected function getTotp(): TOTPInterface
    {
        $otp = Factory::loadFromProvisioningUri(
            $this->config->totpUri(),
            $this->config->clock()
        );
        if ($otp instanceof TOTPInterface) {
            return $otp;
        }
        throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
    }
}
