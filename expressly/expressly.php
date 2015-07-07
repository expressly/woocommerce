<?php

/**
 * Plugin Name: Expressly for WooCommerce
 * Description: ...
 * Version: 0.1.0
 * Author: Expressly Team
 */

use Expressly\Entity\Address;
use Expressly\Entity\Customer;
use Expressly\Entity\Email;
use Expressly\Entity\Invoice;
use Expressly\Entity\MerchantType;
use Expressly\Entity\Order;
use Expressly\Entity\Phone;
use Expressly\Event\CustomerMigrateEvent;
use Expressly\Event\PasswordedEvent;
use Expressly\Exception\ExceptionFormatter;
use Expressly\Exception\GenericException;
use Expressly\Presenter\BatchCustomerPresenter;
use Expressly\Presenter\BatchInvoicePresenter;
use Expressly\Presenter\CustomerMigratePresenter;
use Expressly\Presenter\PingPresenter;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WC_Expressly')) {
    require_once('vendor/autoload.php');
    require_once('class-wc-expressly-merchantprovider.php');

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
            register_activation_hook(__FILE__, array($this, 'register_activation_hook'));
            register_deactivation_hook(__FILE__, array($this, 'register_deactivation_hook'));
            register_uninstall_hook(__FILE__, array($this, 'register_uninstall_hook'));

            add_action('plugins_loaded', array($this, 'plugins_loaded'));
            add_action('init', array($this, 'init'));
            add_action('template_redirect', array($this, 'template_redirect'));

            add_filter('query_vars', array($this, 'query_vars'));

            $client = new Expressly\Client(MerchantType::WOOCOMMERCE);
            $app = $client->getApp();

            $app['merchant.provider'] = $app->share(function () {
                return new WC_Expressly_MerchantProvider();
            });

            $this->app = $app;
            $this->dispatcher = $this->app['dispatcher'];
            $this->merchantProvider = $this->app['merchant.provider'];
        }

        public function init()
        {
            // add a rewrite rules.
            add_rewrite_rule('^expressly/api/ping/?', 'index.php?__xly=utility/ping', 'top');
            add_rewrite_rule('^expressly/api/batch/invoice/?', 'index.php?__xly=batch/invoice', 'top');
            add_rewrite_rule('^expressly/api/batch/customer?', 'index.php?__xly=batch/customer', 'top');
            add_rewrite_rule('^expressly/api/user/(.*)/?', 'index.php?__xly=customer/show&email=$matches[1]', 'top');
            add_rewrite_rule('^expressly/api/(.*)/migrate/?', 'index.php?__xly=customer/migrate&uuid=$matches[1]',
                'top');
            add_rewrite_rule('^expressly/api/(.*)/?', 'index.php?__xly=customer/popup&uuid=$matches[1]', 'top');

            flush_rewrite_rules();
        }

        /**
         * Initialize the plugin.
         */
        public function plugins_loaded()
        {
            add_filter('woocommerce_settings_tabs_array', array($this, 'woocommerce_settings_tabs_array'), 50);
            add_action('woocommerce_settings_tabs_expressly', array($this, 'woocommerce_settings_tabs_expressly'));
            add_action('woocommerce_update_options_expressly', array($this, 'woocommerce_update_options_expressly'));
        }

        /**
         * Add a new settings tab to the WooCommerce settings tabs array.
         *
         * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
         * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
         */
        public static function woocommerce_settings_tabs_array($settings_tabs)
        {
            $settings_tabs['expressly'] = __('Expressly', 'woocommerce');

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
            woocommerce_admin_fields(self::get_settings());
        }

        /**
         * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
         *
         * @uses woocommerce_update_options()
         * @uses self::get_settings()
         */
        public static function woocommerce_update_options_expressly()
        {
            $settings = self::get_settings();
            unset($settings['password']);
            woocommerce_update_options($settings);
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
                    'name' => __('Expressly', 'wc_expressly'),
                    'type' => 'title',
                    'desc' => __('Provide settings for Expressly integration', 'wc_expressly'),
                    'id' => 'wc_expressly_section_title',
                ),
//                'destination' => array(
//                    'name' => __('Destination', 'wc_expressly'),
//                    'type' => 'text',
//                    'desc' => __('Redirect destination after checkout', 'wc_expressly'),
//                    'id' => 'wc_expressly_destination',
//                ),
//                'offer' => array(
//                    'name' => __('Offer', 'wc_expressly'),
//                    'type' => 'checkbox',
//                    'desc' => __('Show offers after checkout', 'wc_expressly'),
//                    'id' => 'wc_expressly_offer',
//                ),
                'image' => array(
                    'name' => __('Site Image URL', 'wc_expressly'),
                    'type' => 'text',
                    'desc' => __('URL for the Logo of your store.', 'wc_expressly'),
                    'id' => 'wc_expressly_image',
                    'css' => 'width:100%;'
                ),
                'terms' => array(
                    'name' => __('Site Terms URL', 'wc_expressly'),
                    'type' => 'text',
                    'desc' => __('Terms & Conditions URL for your store.', 'wc_expressly'),
                    'id' => 'wc_expressly_terms',
                    'css' => 'width:100%;'
                ),
                'policy' => array(
                    'name' => __('Site Privacy Policy URL', 'wc_expressly'),
                    'type' => 'text',
                    'desc' => __('Privacy Policy URL for your store.', 'wc_expressly'),
                    'id' => 'wc_expressly_policy',
                    'css' => 'width:100%;'
                ),
                'password' => array(
                    'name' => __('Password', 'wc_expressly'),
                    'type' => 'text',
                    'desc' => __('Password for your store', 'wc_expressly'),
                    'id' => 'wc_expressly_password',
                    'css' => 'width:100%;'
                ),
                'section_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_expressly_section_end',
                ),
            );

            return $settings;
        }

        public function query_vars($vars)
        {
            $vars[] = "__xly";
            $vars[] = "email";
            $vars[] = "uuid";

            return $vars;
        }

        public function template_redirect()
        {
            // Get Expressly API call
            $__xly = get_query_var('__xly');

            switch ($__xly) {
                case 'utility/ping':
                    $this->ping();
                    break;
                case 'batch/invoice':
                    $this->batchInvoice();
                    break;
                case 'batch/customer':
                    $this->batchCustomer();
                    break;
                case 'customer/show':
                    $this->retrieveUserByEmail(get_query_var('email'));
                    break;
                case 'customer/migrate':
                    $this->migratecomplete(get_query_var('uuid'));
                    break;
                case 'customer/popup':
                    $this->migratestart(get_query_var('uuid'));
                    break;
            }
        }

        private function batchInvoice()
        {
            $json = file_get_contents('php://input');
            $json = json_decode($json);

            $invoices = array();
            try {
                if (!property_exists($json, 'customers')) {
                    throw new GenericException('Invalid JSON request');
                }

                foreach ($json->customers as $customer) {
                    if (!property_exists($customer, 'email')) {
                        continue;
                    }

                    if (email_exists($customer->email)) {
                        $invoice = new Invoice();
                        $invoice->setEmail($customer->email);

                        $orderPosts = get_posts(array(
                            'meta_key' => '_billing_email',
                            'meta_value' => $customer->email,
                            'post_type' => 'shop_order',
                            'numberposts' => -1
                        ));

                        foreach ($orderPosts as $post) {
                            $wpOrder = new WC_Order($post->ID);

                            if ($wpOrder->order_date > $customer->from && $wpOrder->order_date < $customer->to) {
                                $total = 0.0;
                                $tax = 0.0;
                                $count = 0;
                                $order = new Order();

                                foreach ($wpOrder->get_items('line_item') as $lineItem) {
                                    $tax += (double)$lineItem['line_tax'];
                                    $total += (double)$lineItem['line_total'] - (double)$lineItem['line_tax'];
                                    $count++;

                                    if ($lineItem->tax_class) {
                                        $order->setCurrency($lineItem['tax_class']);
                                    }
                                }

                                $order
                                    ->setId($wpOrder->id)
                                    ->setDate(new \DateTime($wpOrder->order_date))
                                    ->setItemCount($count)
                                    ->setTotal($total, $tax);

                                $coupons = $wpOrder->get_used_coupons();
                                if (!empty($coupons)) {
                                    $order->setCoupon($coupons[0]);
                                }

                                $invoice->addOrder($order);
                            }
                        }

                        $invoices[] = $invoice;
                    }
                }
            } catch (GenericException $e) {
                $this->app['logger']->error($e);
            }

            $presenter = new BatchInvoicePresenter($invoices);
            wp_send_json($presenter->toArray());
        }

        private function batchCustomer()
        {
            $json = file_get_contents('php://input');
            $json = json_decode($json);

            $users = array();

            try {
                if (!property_exists($json, 'emails')) {
                    throw new GenericException('Invalid JSON request');
                }

                foreach ($json->emails as $email) {
                    // user_status is a deprecated column and cannot be depended upon
                    if (email_exists($email)) {
                        $users['existing'][] = $email;
                    }
                }
            } catch (GenericException $e) {
                $this->app['logger']->error($e);
            }

            $presenter = new BatchCustomerPresenter($users);
            wp_send_json($presenter->toArray());
        }

        private function migratecomplete($uuid)
        {
            // get key from url
            if (empty($uuid)) {
                wp_redirect('/');
            }

            $exists = false;
            $merchant = $this->app['merchant.provider']->getMerchant();
            $event = new Expressly\Event\CustomerMigrateEvent($merchant, $uuid);

            try {
                $this->dispatcher->dispatch('customer.migrate.data', $event);

                $json = $event->getContent();
                if (!$event->isSuccessful()) {
                    if (!empty($json['code']) && $json['code'] == 'USER_ALREADY_MIGRATED') {
                        $exists = true;
                    }

                    throw new GenericException($this->error_formatter($event));
                }

                $email = $json['migration']['data']['email'];
                $user_id = email_exists($email);

                if (!$user_id) {
                    $customer = $json['migration']['data']['customerData'];

                    // Generate the password and create the user
                    $password = wp_generate_password(12, false);
                    $user_id = wp_create_user($email, $password, $email);

                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $customer['firstName'],
                        'last_name' => $customer['lastName'],
                        'display_name' => $customer['firstName'] . ' ' . $customer['lastName'],
                    ));

                    // Set the role
                    $user = new WP_User($user_id);
                    $user->set_role('customer');

                    $countryCodeProvider = $this->app['country_code.provider'];
                    $addAddress = function ($address, $prefix) use ($customer, $user_id, $countryCodeProvider) {
                        $phone = isset($address['phone']) ?
                            (!empty($customer['phones'][$address['phone']]) ? $customer['phones'][$address['phone']] : null) : null;

                        update_user_meta($user_id, $prefix . '_first_name', $address['firstName']);
                        update_user_meta($user_id, $prefix . '_last_name', $address['lastName']);

                        if (!empty($address['address1'])) {
                            update_user_meta($user_id, $prefix . '_address_1', $address['address1']);
                        }
                        if (!empty($address['address2'])) {
                            update_user_meta($user_id, $prefix . '_address_2', $address['address2']);
                        }

                        update_user_meta($user_id, $prefix . '_city', $address['city']);
                        update_user_meta($user_id, $prefix . '_postcode', $address['zip']);

                        if (!empty($phone)) {
                            update_user_meta($user_id, $prefix . '_phone', $phone['number']);
                        }

                        $iso2 = $countryCodeProvider->getIso2($address['country']);

                        update_user_meta($user_id, $prefix . '_state', $address['stateProvince']);
                        update_user_meta($user_id, $prefix . '_country', $iso2);
                    };

                    if (isset($customer['billingAddress'])) {
                        $addAddress($customer['addresses'][$customer['billingAddress']], 'billing');
                    }

                    if (isset($customer['shippingAddress'])) {
                        $addAddress($customer['addresses'][$customer['shippingAddress']], 'shipping');
                    }

                    // Dispatch password creation email
                    wp_mail($email, 'Welcome!', 'Your Password: ' . $password);

                    // Forcefully log user in
                    wp_set_auth_cookie($user_id);
                } else {
                    $exists = true;
                    $event = new CustomerMigrateEvent($merchant, $uuid, CustomerMigrateEvent::EXISTING_CUSTOMER);
                }

                // Add items (product/coupon) to cart
                if (!empty($json['cart'])) {
                    WC()->cart->empty_cart();
                    if (!empty($json['cart']['productId'])) {
                        WC()->cart->add_to_cart($json['cart']['productId'], 1);
                    }

                    if (!empty($json['cart']['couponCode'])) {
                        WC()->cart->add_discount(sanitize_text_field($json['cart']['couponCode']));
                    }
                }

                $this->dispatcher->dispatch('customer.migrate.success', $event);
            } catch (\Exception $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));
            }

            if ($exists) {
                wp_enqueue_script('woocommerce_expressly', plugins_url('assets/js/expressly.exists.js', __FILE__));

                return;
            }

            wp_redirect("/");
        }

        private function migratestart($uuid)
        {
            $merchant = $this->app['merchant.provider']->getMerchant();
            $event = new CustomerMigrateEvent($merchant, $uuid);

            try {
                $this->dispatcher->dispatch('customer.migrate.popup', $event);

                if (!$event->isSuccessful()) {
                    throw new GenericException($this->error_formatter($event));
                }
            } catch (\Exception $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));
                wp_redirect('/');
            }

            wp_enqueue_script('woocommerce_expressly', plugins_url('assets/js/expressly.popup.js', __FILE__));
            wp_localize_script('woocommerce_expressly', 'XLY', array('uuid' => $uuid));

            $content = $event->getContent();
            add_action('wp_footer', function () use ($content) {
                echo $content;
            });
        }

        private function ping()
        {
            $presenter = new PingPresenter();
            wp_send_json($presenter->toArray());
        }

        private function retrieveUserByEmail($emailAddr)
        {
            try {
                $user = get_user_by('email', $emailAddr);

                if ($user) {
                    $customer = new Customer();
                    $customer
                        ->setFirstName($user->first_name)
                        ->setLastName($user->last_name);

                    $email = new Email();
                    $email
                        ->setEmail($emailAddr)
                        ->setAlias('primary');

                    $customer->addEmail($email);

                    $user_id =& $user->ID;
                    $countryCodeProvider = $this->app['country_code.provider'];

                    $createAddress = function ($prefix) use ($user_id, $countryCodeProvider, $customer) {
                        $address = new Address();
                        $address
                            ->setFirstName(get_user_meta($user_id, $prefix . '_first_name', true))
                            ->setLastName(get_user_meta($user_id, $prefix . '_last_name', true))
                            ->setAddress1(get_user_meta($user_id, $prefix . '_address_1', true))
                            ->setAddress2(get_user_meta($user_id, $prefix . '_address_2', true))
                            ->setCity(get_user_meta($user_id, $prefix . '_city', true))
                            ->setZip(get_user_meta($user_id, $prefix . '_postcode', true));

                        $iso3 = $countryCodeProvider->getIso3(get_user_meta($user_id, $prefix . '_country', true));
                        $address->setCountry($iso3);
                        $address->setStateProvince(get_user_meta($user_id, $prefix . '_state', true));

                        $phoneNumber = get_user_meta($user_id, $prefix . '_phone', true);
                        if (!empty($phoneNumber)) {
                            $phone = new Phone();
                            $phone
                                ->setType(Phone::PHONE_TYPE_HOME)
                                ->setNumber((string)$phoneNumber);

                            $customer->addPhone($phone);
                            $address->setPhonePosition((int)$customer->getPhoneIndex($phone));
                        }

                        return $address;
                    };

                    $billingAddress = $createAddress('billing');
                    $shippingAddress = $createAddress('shipping');

                    if (Address::compare($billingAddress, $shippingAddress)) {
                        $customer->addAddress($billingAddress, true, Address::ADDRESS_BOTH);
                    } else {
                        $customer->addAddress($billingAddress, true, Address::ADDRESS_BILLING);
                        $customer->addAddress($shippingAddress, true, Address::ADDRESS_SHIPPING);
                    }

                    $merchant = $this->app['merchant.provider']->getMerchant();
                    $response = new CustomerMigratePresenter($merchant, $customer, $emailAddr, $user->ID);

                    wp_send_json($response->toArray());
                }
            } catch (\Exception $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));
                wp_send_json(array());
            }
        }

        public function error_formatter($event)
        {
            $content = $event->getContent();
            $message = array(
                $content['description']
            );

            $addBulletpoints = function ($key, $title) use ($content, &$message) {
                if (!empty($content[$key])) {
                    $message[] = '<br>';
                    $message[] = $title;
                    $message[] = '<ul>';

                    foreach ($content[$key] as $point) {
                        $message[] = "<li>{$point}</li>";
                    }

                    $message[] = '</ul>';
                }
            };

            // TODO: translatable
            $addBulletpoints('causes', 'Possible causes:');
            $addBulletpoints('actions', 'Possible resolutions:');

            return implode('', $message);
        }

        public function register_activation_hook()
        {
            if (!current_user_can('activate_plugins')) {
                return;
            }

            $url = get_option('siteurl');
            update_option(WC_Expressly_MerchantProvider::HOST, $url);
            update_option(WC_Expressly_MerchantProvider::PATH, '/index.php');
            update_option(WC_Expressly_MerchantProvider::DESTINATION, '/');
            update_option(WC_Expressly_MerchantProvider::OFFER, true);
            update_option(WC_Expressly_MerchantProvider::PASSWORD, '');
            update_option(WC_Expressly_MerchantProvider::UUID, '');
            update_option(WC_Expressly_MerchantProvider::IMAGE, 'http://buyexpressly.com/img/logo4.png');
            update_option(WC_Expressly_MerchantProvider::POLICY, $url);
            update_option(WC_Expressly_MerchantProvider::TERMS, $url);

            $merchant = $this->merchantProvider->getMerchant(true);
            $event = new PasswordedEvent($merchant);
            $this->dispatcher->dispatch('merchant.register', $event);

            try {
                if (!$event->isSuccessful()) {
                    throw new GenericException($this->error_formatter($event));
                }

                $content = $event->getContent();
                $merchant
                    ->setUuid($content['merchantUuid'])
                    ->setPassword($content['secretKey']);

                $this->merchantProvider->setMerchant($merchant);
            } catch (GenericException $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));

                echo (string)$e->getMessage();
                exit;
            }
        }

        public function register_deactivation_hook()
        {
            $merchant = $this->merchantProvider->getMerchant();

            try {
                $event = new PasswordedEvent($merchant);
                $this->dispatcher->dispatch('merchant.delete', $event);
            } catch (\Exception $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));
            }
        }

        public function register_uninstall_hook()
        {
            $merchant = $this->merchantProvider->getMerchant();

            try {
                $event = new PasswordedEvent($merchant);
                $this->dispatcher->dispatch('merchant.delete', $event);
            } catch (\Exception $e) {
                $this->app['logger']->error(ExceptionFormatter::format($e));
            }

            delete_option(WC_Expressly_MerchantProvider::HOST);
            delete_option(WC_Expressly_MerchantProvider::PATH);
            delete_option(WC_Expressly_MerchantProvider::DESTINATION);
            delete_option(WC_Expressly_MerchantProvider::OFFER);
            delete_option(WC_Expressly_MerchantProvider::PASSWORD);
            delete_option(WC_Expressly_MerchantProvider::UUID);
            delete_option(WC_Expressly_MerchantProvider::IMAGE);
            delete_option(WC_Expressly_MerchantProvider::TERMS);
            delete_option(WC_Expressly_MerchantProvider::POLICY);
        }
    }

    $WC_Expressly = new WC_Expressly();
}
