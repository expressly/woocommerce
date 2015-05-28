<?php

/**
 * Plugin Name: Expressly for WooCommerce
 * Description: ...
 * Version: 0.1.0
 * Author: Expressly Team
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once('vendor/autoload.php');

if ( ! class_exists( 'WC_Expressly' ) ) :
/*
    register_activation_hook(   __FILE__, array( 'WC_Expressly', 'register_activation_hook' ) );
    register_deactivation_hook( __FILE__, array( 'WC_Expressly', 'register_deactivation_hook' ) );
    register_uninstall_hook(    __FILE__, array( 'WC_Expressly', 'register_uninstall_hook' ) );
*/
    class WC_Expressly
    {
        /**
         * @var Silex\Application
         */
        public $app;

        /**
         * @var Symfony\Component\EventDispatcher\EventDispatcher
         */
        public $dispatcher;

        /**
         * Construct the plugin.
         */
        public function __construct()
        {
            /*
            register_activation_hook(   __FILE__, array( $this, 'register_activation_hook' ) );
            register_deactivation_hook( __FILE__, array( $this, 'register_deactivation_hook' ) );
            register_uninstall_hook(    __FILE__, array( $this, 'register_uninstall_hook' ) );
            */

            add_action( 'plugins_loaded', array( $this, 'init' ) );
        }

        /**
         * Initialize the plugin.
         */
        public function init()
        {
            // Checks if WooCommerce is installed.
            if ( class_exists( 'WC_Integration' ) ) {

                include_once('class-wc-expressly-merchantprovider.php');
                include_once('class-wc-expressly-integration.php');

                $this->setup();

                add_action( 'template_redirect', array( $this, 'template_redirect' ) );

                add_filter( 'query_vars', array( $this, 'add_query_vars_filter' ) );
                add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );

            } else {
                // throw an admin error if you like
            }
        }

        private function setup()
        {
            $client = new Expressly\Client();
            $app    = $client->getApp();

            $this->app = $app;
            $this->dispatcher = $this->app['dispatcher'];

            // override MerchantProvider
            $app['merchant.provider'] = $app->share(function ($app) {
                return new WC_Expressly_MerchantProvider();
            });
        }

        /**
         * @param $vars
         * @return array
         */
        public function add_query_vars_filter( $vars )
        {
            $vars[] = "__xly";
            return $vars;
        }

        /**
         *
         */
        public function template_redirect()
        {
            $__xly = get_query_var('__xly');

            if (preg_match("/^\/?expressly\/api\/ping\/?$/", $__xly)) {
                $this->ping();
                exit();
            }

            if (preg_match("/^\/?expressly\/api\/user\/([\w-\.]+@[\w-\.]+)\/?$/", $__xly, $matches)) {
                $email = array_pop($matches);
                //$this->retrieveUserByEmail($email);
            }

            if (preg_match("/^\/?expressly\/api\/([\w-]+)\/?$/", $__xly, $matches)) {
                $key = array_pop($matches);
                wp_redirect("/?__xly=migratestart&uuid={$key}");
            }

            switch ($__xly):
                /*case 'ping': {

                    echo '<pre>';
                    var_dump($__xly);
                    echo '<hr />';

                    try {

                        update_option('wc_expressly_password', Expressly\Entity\Merchant::createPassword());

                        $merchant = $this->app['merchant.provider']->getMerchant();
                        $response = $dispatcher->dispatch('merchant.register', new Expressly\Event\MerchantEvent($merchant));
                        var_dump($response);
                    } catch (Exception $e) {
                        var_dump($e);
                        die();
                    }



                } break;*/
                case 'migratestart': {

                    $merchant = $this->app['merchant.provider']->getMerchant();
                    $event = new Expressly\Event\CustomerMigrateEvent($merchant, $_GET['uuid']);


                    //$response = $event->getResponse();
                    echo $this->dispatcher->dispatch('customer.migrate.start', $event)->getResponse();
                    echo '<script type="text/javascript">
                            //    var trigger = document.getElementsByClassName("expressly_button")[0].getElementsByTagName("a")[0];
                            //    trigger.addEventListener("click", popupContinue);
                            (function () {
                                window.popupContinue = function () {
                                    var host = window.location.origin,
                    parameters = window.location.search,
                    uuid;

                parameters = parameters.split("&");

                for (var parameter in parameters) {
                                        if (parameters[parameter].indexOf("uuid") != -1) {
                                            uuid = parameters[parameter].split("=")[1];
                    }
                }

                window.location.replace(host + "?__xly=migratecomplete&uuid=" + uuid);
            };
                            })();
    </script>';

                } break;
                case "migratecomplete": {
                    // get key from url
                    if (empty($_GET['uuid'])) {
                        die('Undefined uuid');
                    }

                    // get json
                    $merchant = $this->app['merchant.provider']->getMerchant();
                    $event = new Expressly\Event\CustomerMigrateEvent($merchant, $_GET['uuid']);
                    $this->dispatcher->dispatch('customer.migrate.complete', $event);

                    $json = $event->getResponse();

                    if (!empty($json['code'])) {
                        die('empty code');
                    }

                    if (empty($json['migration'])) {
                        // record error

                        die('empty migration');
                    }

                    // 'user_already_migrated' should be proper error message, not a plain string
                    if ($json == 'user_already_migrated') {
                        die('user_already_migrated');
                    }

                    try {
                        $email = $json['migration']['data']['email'];

                        $user_id = username_exists( $email );

                        if ( null === $user_id ) {

                            $customer = $json['migration']['data']['customerData'];

                            // Generate the password and create the user
                            $password = wp_generate_password( 12, false );
                            $user_id  = wp_create_user( $email, $password, $email );

                            wp_update_user(
                                array(
                                    'ID'         => $user_id,
                                    'first_name' => $customer['firstName'],
                                    'last_name'  => $customer['lastName'],
                                    // TODO: Do we need it for WordPress
                                    'id_gender'  => $customer['gender'] && $customer['gender'] == Expressly\Entity\Customer::GENDER_FEMALE ? 2 : 1,
                                    'newsletter' => true,
                                    'optin'      => true,
                                )
                            );

                            if (!empty($customer['dob'])) {
                                wp_update_user(
                                    array(
                                        'ID'       => $user_id,
                                        'birthday' => date('Y-m-d', $customer['dob']),
                                    )
                                );
                            }

                            if (!empty($customer['companyName'])) {
                                wp_update_user(
                                    array(
                                        'ID'      => $user_id,
                                        'company' => $customer['companyName'],
                                    )
                                );
                            }

                            // Set the role
                            $user = new WP_User( $user_id );
                            $user->set_role( 'customer' );

                            // Email the user
                            //wp_mail( $email, 'Welcome!', 'Your Password: ' . $password );

                        } else {
                            $user = new WP_User( $user_id );
                        }

                        // Forcefully log user in
                        wp_set_auth_cookie($user_id);

                        // Add items (product/coupon) to cart
                        // TODO:

                        // Dispatch password creation email
                        // TODO:

                        /*
                        $id = CustomerCore::customerExists($email, true);
                        $psCustomer = new CustomerCore();

                        if ($id) {
                            $psCustomer = new CustomerCore($id);
                        } else {
                            $customer = $json['migration']['data']['customerData'];

                            $psCustomer->firstname = $customer['firstName'];
                            $psCustomer->lastname = $customer['lastName'];
                            $psCustomer->email = $email;
                            $psCustomer->passwd = md5('xly' . microtime());
                            $psCustomer->id_gender = $customer['gender'] && $customer['gender'] == Customer::GENDER_FEMALE ? 2 : 1;
                            $psCustomer->newsletter = true;
                            $psCustomer->optin = true;

                            if (!empty($customer['dob'])) {
                                $psCustomer->birthday = date('Y-m-d', $customer['dob']);
                            }
                            if (!empty($customer['companyName'])) {
                                $psCustomer->company = $customer['companyName'];
                            }

                            $psCustomer->add();

                            // Addresses
                            foreach ($customer['addresses'] as $address) {
                                $countryCodeProvider = $this->module->app['country_code.provider'];
                                $phone = isset($address['phone']) ?
                                    (!empty($customer['phones'][$address['phone']]) ?
                                        $customer['phones'][$address['phone']] : null) : null;
                                $psAddress = new AddressCore();

                                $psAddress->id_customer = $psCustomer->id;
                                $psAddress->alias = $address['addressAlias'];
                                $psAddress->firstname = $address['firstName'];
                                $psAddress->lastname = $address['lastName'];

                                if (!empty($address['address1'])) {
                                    $psAddress->address1 = $address['address1'];
                                }
                                if (!empty($address['address2'])) {
                                    $psAddress->address2 = $address['address2'];
                                }

                                $psAddress->postcode = $address['zip'];
                                $psAddress->city = $address['city'];

                                $iso2 = $countryCodeProvider->getIso2($address['country']);
                                $psAddress->id_country = CountryCore::getByIso($iso2);

                                if (!is_null($phone)) {
                                    if ($phone['type'] == Phone::PHONE_TYPE_MOBILE) {
                                        $psAddress->phone_mobile = $phone['number'];
                                    } elseif ($phone['type'] == Phone::PHONE_TYPE_HOME) {
                                        $psAddress->phone = $phone['number'];
                                    }
                                }

                                $psAddress->add();
                            }
                        }

                        // Forcefully log user in
                        $psCustomer->logged = 1;
                        $this->context->customer = $psCustomer;

                        $this->context->cookie->id_compare = isset($this->context->cookie->id_compare) ?
                            $this->context->cookie->id_compare : CompareProductCore::getIdCompareByIdCustomer($psCustomer->id);
                        $this->context->cookie->id_customer = (int)($psCustomer->id);
                        $this->context->cookie->customer_lastname = $psCustomer->lastname;
                        $this->context->cookie->customer_firstname = $psCustomer->firstname;
                        $this->context->cookie->logged = 1;
                        $this->context->cookie->is_guest = $psCustomer->isGuest();
                        $this->context->cookie->passwd = $psCustomer->passwd;
                        $this->context->cookie->email = $psCustomer->email;

                        // Add items (product/coupon) to cart
                        if (!empty($json['cart'])) {
                            $cartId = $psCustomer->getLastCart(false);
                            $psCart = new CartCore($cartId, $this->context->language->id);

                            if ($psCart->id == null) {
                                $psCart = new CartCore();
                                $psCart->id_language = $this->context->language->id;
                                $psCart->id_currency = (int)($this->context->cookie->id_currency);
                                $psCart->id_shop_group = (int)$this->context->shop->id_shop_group;
                                $psCart->id_shop = $this->context->shop->id;
                                $psCart->id_customer = $psCustomer->id;
                                $psCart->id_shop = $this->context->shop->id;
                                $psCart->id_address_delivery = 0;
                                $psCart->id_address_invoice = 0;
                                $psCart->add();
                            }

                            if (!empty($json['cart']['productId'])) {
                                $psProduct = new ProductCore($json['cart']['productId']);

                                if ($psProduct->checkAccess($psCustomer->id)) {
                                    $psProductAttribute = $psProduct->getDefaultIdProductAttribute();

                                    if ($psProductAttribute > 0) {
                                        $psCart->updateQty(1, $json['cart']['productId'], $psProductAttribute, null, 'up', 0,
                                            $this->context->shop);
                                    }
                                }
                            }

                            if (!empty($json['cart']['couponCode'])) {
                                $psCouponId = CartRuleCore::getIdByCode($json['cart']['couponCode']);

                                if ($psCouponId) {
                                    $psCart->addCartRule($psCouponId);
                                }
                            }

                            $this->context->cookie->id_cart = $psCart instanceof CartCore ? (int)$psCart->id : $psCart;
                        }

                        // Dispatch password creation email
                        $mailUser = ConfigurationCore::get('PS_MAIL_USER');
                        $mailPass = ConfigurationCore::get('PS_MAIL_PASSWD');
                        if (!empty($mailUser) && !empty($mailPass)) {
                            $context = ContextCore::getContext();

                            if (MailCore::Send(
                                $context->language->id,
                                'password_query',
                                MailCore::l('Password query confirmation'),
                                $mail_params = array(
                                    '{email}' => $psCustomer->email,
                                    '{lastname}' => $psCustomer->lastname,
                                    '{firstname}' => $psCustomer->firstname,
                                    '{url}' => $context->link->getPageLink('password', true, null,
                                        'token=' . $psCustomer->secure_key . '&id_customer=' . (int)$psCustomer->id)
                                ),
                                $psCustomer->email,
                                sprintf('%s %s', $psCustomer->firstname, $psCustomer->lastname)
                            )
                            ) {
                                $context->smarty->assign(array('confirmation' => 2, 'customer_email' => $psCustomer->email));
                            }
                        }

                        $this->context->cookie->write();
                        */
                    } catch (\Exception $e) {
                        // TODO: Log
                    }

                    wp_redirect("/");





                } break;
            endswitch;
        }

        /**
         *
         */
        private function ping()
        {
            try {
                $response = $this->dispatcher->dispatch('utility.ping', new Expressly\Event\ResponseEvent());
                echo json_encode($response->getResponse());
            } catch (Exception $e) {
                var_dump($e);
            }
        }

        /**
         * Add a new integration to WooCommerce.
         */
        public function add_integration( $integrations )
        {
            $integrations[] = 'WC_Expressly_Integration';

            return $integrations;
        }

        /**
         *
         */
        public static function register_activation_hook()
        {
            if ( ! current_user_can( 'activate_plugins' ) ) return;

            update_option( 'woocommerce_expressly_host',        sprintf('://%s', $_SERVER['HTTP_HOST']) );
            update_option( 'woocommerce_expressly_destination', '/' );
            update_option( 'woocommerce_expressly_offer',       true );
            update_option( 'woocommerce_expressly_password',    Expressly\Entity\Merchant::createPassword() );
            update_option( 'woocommerce_expressly_path',        '?__xly=' );

            /*
            $merchant = $this->app['merchant.provider']->getMerchant();
            $this->dispatcher->dispatch('merchant.register', new Expressly\Event\MerchantEvent($merchant));
            */
        }

        /**
         *
         */
        public static function register_deactivation_hook()
        {
            /*
            $merchant = $this->app['merchant.provider']->getMerchant();
            $this->dispatcher->dispatch('merchant.delete', new Expressly\Event\MerchantEvent($merchant));
            */

            delete_option( 'woocommerce_expressly_host' );
            delete_option( 'woocommerce_expressly_destination' );
            delete_option( 'woocommerce_expressly_offer' );
            delete_option( 'woocommerce_expressly_password' );
            delete_option( 'woocommerce_expressly_path' );
        }

        /**
         *
         */
        public static function register_uninstall_hook()
        {

        }

    }

    $WC_Expressly = new WC_Expressly();

endif;
