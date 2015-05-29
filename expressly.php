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

if ( ! class_exists( 'WC_Expressly' ) ) :

    require_once('vendor/autoload.php');
    require_once('class-wc-expressly-merchantprovider.php');

    /**
     *
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
            register_activation_hook( __FILE__,   array( $this, 'register_activation_hook' ) );
            register_deactivation_hook( __FILE__, array( $this, 'register_deactivation_hook' ) );
            register_uninstall_hook( __FILE__,    array( $this, 'register_uninstall_hook' ) );

            add_action( 'plugins_loaded',    array( $this, 'plugins_loaded' ) );
            add_action( 'init',              array( $this, 'init' ) );
            add_action( 'template_redirect', array( $this, 'template_redirect' ) );

            add_filter( 'query_vars',        array( $this, 'query_vars' ) );

            // ===== Set app, dispatcher & merchant ===== //
            $client = new Expressly\Client();
            $app    = $client->getApp();

            $app['merchant.provider'] = $app->share(function ($app) {
                return new WC_Expressly_MerchantProvider();
            });

            $this->app = $app;
            $this->dispatcher = $this->app['dispatcher'];
            // ===== //
        }

        public function init()
        {
            // add a rewrite rules.
            add_rewrite_rule('^expressly/api/ping/?',         'index.php?__xly=utility/ping',                      'top');
            add_rewrite_rule('^expressly/api/user/(.*)/?',    'index.php?__xly=customer/show&email=$matches[1]',   'top');
            add_rewrite_rule('^expressly/api/(.*)/migrate/?', 'index.php?__xly=customer/migrate&uuid=$matches[1]', 'top');
            add_rewrite_rule('^expressly/api/(.*)/?',         'index.php?__xly=customer/popup&uuid=$matches[1]',   'top');

            // Only for DEBUG
            flush_rewrite_rules();
        }

        /**
         * Initialize the plugin.
         */
        public function plugins_loaded()
        {
            add_filter( 'woocommerce_settings_tabs_array',      array( $this, 'woocommerce_settings_tabs_array' ), 50 );
            add_action( 'woocommerce_settings_tabs_expressly',  array( $this, 'woocommerce_settings_tabs_expressly' ) );
            add_action( 'woocommerce_update_options_expressly', array( $this, 'woocommerce_update_options_expressly' ) );
        }

        /**
         * Add a new settings tab to the WooCommerce settings tabs array.
         *
         * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
         * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
         */
        public static function woocommerce_settings_tabs_array( $settings_tabs )
        {
            $settings_tabs['expressly'] = __( 'Expressly', 'woocommerce' );

            return $settings_tabs;
        }

        /**
         * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
         *
         * @uses woocommerce_admin_fields()
         * @uses self::get_settings()
         */
        public static function woocommerce_settings_tabs_expressly()
        {
            woocommerce_admin_fields( self::get_settings() );
        }

        /**
         * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
         *
         * @uses woocommerce_update_options()
         * @uses self::get_settings()
         */
        public static function woocommerce_update_options_expressly()
        {
            woocommerce_update_options( self::get_settings() );
        }

        /**
         * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
         *
         * @return array Array of settings for @see woocommerce_admin_fields() function.
         */
        public static function get_settings()
        {
            $settings = array(
                'section_title' => array(
                    'name'     => __( 'Expressly', 'wc_expressly' ),
                    'type'     => 'title',
                    'desc'     => __( 'Provide settings for Expressly integration', 'wc_expressly' ),
                    'id'       => 'wc_expressly_section_title',
                ),

                'host' => array(
                    'name' => __( 'Host', 'wc_expressly' ),
                    'type' => 'text',
                    'desc' => __( 'will be hidden', 'wc_expressly' ),
                    'id'   => 'wc_expressly_host',
                ),
                'destination' => array(
                    'name' => __( 'Destination', 'wc_expressly' ),
                    'type' => 'text',
                    'desc' => __( 'Redirect destination after checkout', 'wc_expressly' ),
                    'id'   => 'wc_expressly_destination',
                ),
                'offer' => array(
                    'name' => __( 'Offer', 'wc_expressly' ),
                    'type' => 'checkbox',
                    'desc' => __( 'Show offers after checkout', 'wc_expressly' ),
                    'id'   => 'wc_expressly_offer',
                ),
                'password' => array(
                    'name' => __( 'Password', 'wc_expressly' ),
                    'type' => 'text',
                    'desc' => __( 'Expressly password for your store', 'wc_expressly' ),
                    'id'   => 'wc_expressly_password',
                ),
                'path' => array(
                    'name' => __( 'Path', 'wc_expressly' ),
                    'type' => 'text',
                    'desc' => __( 'will be hidden', 'wc_expressly' ),
                    'id'   => 'wc_expressly_path',
                ),
                'section_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_expressly_section_end',
                ),
            );

            return $settings;
        }

        /**
         * @param $vars
         * @return array
         */
        public function query_vars( $vars )
        {
            $vars[] = "__xly";
            $vars[] = "email";
            $vars[] = "uuid";

            return $vars;
        }

        /**
         *
         */
        public function template_redirect()
        {
            // Get Expressly API call
            $__xly = get_query_var('__xly');

            switch ($__xly):

                case 'utility/ping': {
                    $this->ping();
                } break;

                case 'customer/show': {
                    $this->retrieveUserByEmail(get_query_var('email'));
                } break;

                case "customer/migrate": {
                    $this->migratecomplete(get_query_var('uuid'));
                } break;

                case "customer/popup": {
                    $this->migratestart(get_query_var('uuid'));
                } break;

            endswitch;
        }

        private function migratecomplete($uuid)
        {
            // get key from url
            if (empty($uuid)) {
                die('Undefined uuid');
            }

            // get json
            $merchant = $this->app['merchant.provider']->getMerchant();
            $event = new Expressly\Event\CustomerMigrateEvent($merchant, $uuid);
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
        }

        /**
         * @param $uuid
         */
        private function migratestart($uuid)
        {
            $merchant = $this->app['merchant.provider']->getMerchant();
            $event = new Expressly\Event\CustomerMigrateEvent($merchant, $uuid);

            echo $this->dispatcher->dispatch('customer.migrate.start', $event)->getResponse();
            echo '<script type="text/javascript">
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

                window.location.replace(host + "?__xly=customer/migrate&uuid=" + "'.$uuid.'");
            };
                            })();
    </script>';
            exit();
        }

        /**
         *
         */
        private function ping()
        {
            try {
                $response = $this->dispatcher->dispatch('utility.ping', new Expressly\Event\ResponseEvent());
                wp_send_json($response->getResponse());
            } catch (Exception $e) {
                wp_send_json($e);
            }
        }

        /**
         * @param $emailAddr
         */
        private function retrieveUserByEmail($emailAddr)
        {
            try {
                if (!is_email($emailAddr)) {
                    wp_redirect('/');
                }

                $user = get_user_by('email', $emailAddr );

                if ($user) {

                    $customer = new Expressly\Entity\Customer();
                    $customer
                        ->setFirstName($user->first_name)
                        ->setLastName($user->last_name);
                        //->setCompany($psCustomer->company)
                        //->setBirthday(new \DateTime($psCustomer->birthday))
                        //->setDateUpdated(new \DateTime($psCustomer->date_upd));

                    //$gender = $psCustomer->id_gender ? Customer::GENDER_MALE : Customer::GENDER_FEMALE;
                    //$customer->setGender($gender);

                    $email = new Expressly\Entity\Email();
                    $email
                        ->setEmail($emailAddr)
                        ->setAlias('primary');

                    $customer->addEmail($email);

/*
 *
                    $first = true;
                    $context = ContextCore::getContext();

                    foreach ($psCustomer->getAddresses($context->language->id) as $psAddress) {
                        $address = new Address();
                        $address
                            ->setFirstName($psAddress['firstname'])
                            ->setLastName($psAddress['lastname'])
                            ->setAddress1($psAddress['address1'])
                            ->setAddress2($psAddress['address2'])
                            ->setCity($psAddress['city'])
                            ->setCompanyName($psAddress['company'])
                            ->setZip($psAddress['postcode'])
                            ->setAlias($psAddress['alias']);

                        $psCountry = new CountryCore($psAddress['id_country']);
                        $address->setCountry($psCountry->iso_code);

                        /
                         * PrestaShop uses the country prefix from the address, which is logically incorrect.
                         * An address may be in the UK, but the owner may have a DE number, this cannot be handled at current time.
                         * TODO: Find a way that actually works, will require an expressly table to relate customers, phones, and prefix
                         /
                        if (!empty($psAddress['phone'])) {
                            $phone = new Phone();
                            $phone
                                ->setType(Phone::PHONE_TYPE_HOME)
                                ->setNumber((string)$psAddress['phone'])
                                ->setCountryCode((int)$psCountry->call_prefix);

                            $customer->addPhone($phone);

                            if (empty($psAddress['phone_mobile'])) {
                                $address->setPhonePosition($customer->getPhoneIndex($phone));
                            }
                        }

                        if (!empty($psAddress['phone_mobile'])) {
                            $phone = new Phone();
                            $phone
                                ->setType(Phone::PHONE_TYPE_MOBILE)
                                ->setNumber((string)$psAddress['phone_mobile'])
                                ->setCountryCode((int)$psCountry->call_prefix);

                            $customer->addPhone($phone);

                            if (empty($psAddress['phone'])) {
                                $address->setPhonePosition($customer->getPhoneIndex($phone));
                            }
                        }

                        $customer->addAddress($address, $first, Address::ADDRESS_BOTH);
                        $first = false;
                    }
*/
                    $merchant = $this->app['merchant.provider']->getMerchant();
                    $response = new Expressly\Presenter\CustomerMigratePresenter($merchant, $customer, $emailAddr, $user->ID);

                    wp_send_json($response->toArray());
                }
            } catch (\Exception $e) {
                wp_send_json(array(
                    'error' => sprintf('%s - %s::%u', $e->getFile(), $e->getMessage(), $e->getLine())
                ));
            }
        }


        /**
         *
         */
        public function register_activation_hook()
        {
            if ( ! current_user_can( 'activate_plugins' ) ) return;

            update_option( 'wc_expressly_host',        sprintf('://%s', $_SERVER['HTTP_HOST']) );
            update_option( 'wc_expressly_destination', '/' );
            update_option( 'wc_expressly_offer',       'yes' );
            update_option( 'wc_expressly_password',    Expressly\Entity\Merchant::createPassword() );
            update_option( 'wc_expressly_path',        'index.php' );

            // TODO: Have error here "HTTP Status 400 - Required String parameter 'newPass' is not present"
            // $merchant = $this->app['merchant.provider']->getMerchant(true);
            // $this->dispatcher->dispatch('merchant.register', new Expressly\Event\MerchantEvent($merchant));
        }

        /**
         *
         */
        public function register_deactivation_hook()
        {
            /*
            $merchant = $this->app['merchant.provider']->getMerchant();
            $this->dispatcher->dispatch('merchant.delete', new Expressly\Event\MerchantEvent($merchant));
            */

            delete_option( 'wc_expressly_host' );
            delete_option( 'wc_expressly_destination' );
            delete_option( 'wc_expressly_offer' );
            delete_option( 'wc_expressly_password' );
            delete_option( 'wc_expressly_path' );
        }

        /**
         *
         */
        public function register_uninstall_hook()
        {

        }

    }

    $WC_Expressly = new WC_Expressly();

endif;
