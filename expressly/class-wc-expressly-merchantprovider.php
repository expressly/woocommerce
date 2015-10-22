<?php

use Expressly\Entity\Merchant;
use Expressly\Provider\MerchantProviderInterface;

class WC_Expressly_MerchantProvider implements MerchantProviderInterface
{
    private $merchant;

    const APIKEY = 'wc_expressly_apikey';
    const HOST = 'wc_expressly_host';
    const PATH = 'wc_expressly_path';

    public function __construct()
    {
        if (get_option('wc_expressly_destination')) {
            $this->updateMerchant();
        }
    }

    private function updateMerchant()
    {
        $merchant = new Expressly\Entity\Merchant();
        $merchant
            ->setApiKey(get_option(self::APIKEY))
            ->setHost(get_option(self::HOST))
            ->setPath(get_option(self::PATH));

        $this->merchant = $merchant;
    }

    public function setMerchant(Merchant $merchant)
    {
        update_option(self::APIKEY, $merchant->getApiKey());
        update_option(self::HOST, $merchant->getHost());
        update_option(self::PATH, $merchant->getPath());;

        $this->merchant = $merchant;

        return $this;
    }

    public function getMerchant($update = false)
    {
        if (!$this->merchant instanceof Merchant || $update) {
            $this->updateMerchant();
        }

        return $this->merchant;
    }
}
