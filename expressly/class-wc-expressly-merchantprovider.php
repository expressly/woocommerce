<?php

use Expressly\Entity\Merchant;
use Expressly\Provider\MerchantProviderInterface;

class WC_Expressly_MerchantProvider implements MerchantProviderInterface
{
    private $merchant;

    const NAME = 'blogname';
    const UUID = 'wc_expressly_uuid';
    const DESTINATION = 'wc_expressly_destination';
    const HOST = 'wc_expressly_host';
    const OFFER = 'wc_expressly_offer';
    const PASSWORD = 'wc_expressly_password';
    const PATH = 'wc_expressly_path';
    const IMAGE = 'wc_expressly_image';
    const TERMS = 'wc_expressly_terms';
    const POLICY = 'wc_expressly_policy';

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
            ->setName(get_option(self::NAME))
            ->setUuid(get_option(self::UUID))
            ->setDestination(get_option(self::DESTINATION))
            ->setHost(get_option(self::HOST))
            ->setOffer(get_option(self::OFFER))
            ->setPassword(get_option(self::PASSWORD))
            ->setPath(get_option(self::PATH))
            ->setImage(get_option(self::IMAGE))
            ->setTerms(get_option(self::TERMS))
            ->setPolicy(get_option(self::POLICY));

        $this->merchant = $merchant;
    }

    public function setMerchant(Merchant $merchant)
    {
        update_option(self::UUID, $merchant->getUuid());
        update_option(self::DESTINATION, $merchant->getDestination());
        update_option(self::HOST, $merchant->getHost());
        update_option(self::OFFER, $merchant->getOffer());
        update_option(self::PASSWORD, $merchant->getPassword());
        update_option(self::PATH, $merchant->getPath());
        update_option(self::IMAGE, $merchant->getImage());
        update_option(self::TERMS, $merchant->getTerms());
        update_option(self::POLICY, $merchant->getPolicy());

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
