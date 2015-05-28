<?php

/**
 *
 */
class WC_Expressly_MerchantProvider implements Expressly\Provider\MerchantProviderInterface
{
    /**
     * @var Expressly\Entity\Merchant
     */
    private $merchant;

    /**
     *
     */
    public function __construct()
    {
        if (get_option('woocommerce_expressly_destination')) {
            $this->updateMerchant();
        }
    }

    /**
     *
     */
    private function updateMerchant()
    {
        $merchant = new Expressly\Entity\Merchant();
        $merchant
            ->setDestination(get_option('woocommerce_expressly_destination'))
            ->setHost(get_option('woocommerce_expressly_host'))
            ->setOffer(get_option('woocommerce_expressly_offer'))
            ->setPassword(get_option('woocommerce_expressly_password'))
            ->setPath(get_option('woocommerce_expressly_path'));

        $this->merchant = $merchant;
    }

    /**
     * @param \Expressly\Entity\Merchant $merchant
     * @return $this
     */
    public function setMerchant(Expressly\Entity\Merchant $merchant)
    {
        $this->merchant = $merchant;

        return $this;
    }

    /**
     * @param bool $update
     * @return \Expressly\Entity\Merchant
     */
    public function getMerchant($update = false)
    {
        if (!$this->merchant instanceof Expressly\Entity\Merchant || $update) {
            $this->updateMerchant();
        }

        return $this->merchant;
    }
}
