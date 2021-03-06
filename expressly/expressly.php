<?php

/**
 * Plugin Name: Expressly for WooCommerce
 * Description: Connect your shop to the Expressly Network. To get started 1) Click the "Activate" link to the left of this description, 2) <a href="http://portal.buyexpressly.com/">Sign up to Expressly</a> to get an API key, and 3) Click on the "Settings" link to the left of this description, and save your API key.
 * Version: 2.7.3
 * Author: Expressly
 * Author URI: https://buyexpressly.com/
 */

use Expressly\Entity\Address;
use Expressly\Entity\Customer;
use Expressly\Entity\Email;
use Expressly\Entity\Invoice;
use Expressly\Entity\MerchantType;
use Expressly\Entity\Order;
use Expressly\Entity\Phone;
use Expressly\Entity\Route;
use Expressly\Entity\Social;
use Expressly\Event\BannerEvent;
use Expressly\Event\CustomerMigrateEvent;
use Expressly\Event\PasswordedEvent;
use Expressly\Exception\ExceptionFormatter;
use Expressly\Exception\GenericException;
use Expressly\Exception\InvalidAPIKeyException;
use Expressly\Exception\UserExistsException;
use Expressly\Helper\BannerHelper;
use Expressly\Presenter\BatchCustomerPresenter;
use Expressly\Presenter\BatchInvoicePresenter;
use Expressly\Presenter\CustomerMigratePresenter;
use Expressly\Presenter\PingPresenter;
use Expressly\Presenter\RegisteredPresenter;
use Expressly\Route\BatchCustomer;
use Expressly\Route\BatchInvoice;
use Expressly\Route\CampaignMigration;
use Expressly\Route\CampaignPopup;
use Expressly\Route\Ping;
use Expressly\Route\Registered;
use Expressly\Route\UserData;
use Expressly\Subscriber\BannerSubscriber;
use Expressly\Subscriber\CustomerMigrationSubscriber;
use Expressly\Subscriber\MerchantSubscriber;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if(!function_exists("xly_woocommerce_check")) {
    function xly_woocommerce_check() {
        return count(array_filter(apply_filters('active_plugins', get_option('active_plugins')), function($var) {
            return preg_match('/.+\/woocommerce.php/', $var);
        })) != 0;
    }
}

if (xly_woocommerce_check()) {
    if (!class_exists('WC_Expressly')) {
        require_once 'vendor/autoload.php';
        require_once 'class-wc-expressly-merchantprovider.php';

        class WC_Expressly
        {
            const EMAIL_TEMPLATE = 'wc_expressly_email_template';
            const EMAIL_SUBJECT = 'wc_expressly_email_subject';

            public $app;
            public $dispatcher;
            public $merchantProvider;

            public function __construct()
            {
                register_activation_hook(__FILE__, array('WC_Expressly', 'register_activation_hook'));
                register_deactivation_hook(__FILE__, array($this, 'register_deactivation_hook'));
                register_uninstall_hook(__FILE__, array('WC_Expressly', 'register_uninstall_hook'));

                add_action('plugins_loaded', array($this, 'plugins_loaded'));
                add_action('template_redirect', array($this, 'template_redirect'));
                add_action( 'wp_enqueue_scripts', array($this, 'lightbox_trigger') );

                add_filter('query_vars', array($this, 'query_vars'));
                add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));

                $client = new Expressly\Client(MerchantType::WOOCOMMERCE);
                $app = $client->getApp();

                $app['merchant.provider'] = function () {
                    return new WC_Expressly_MerchantProvider();
                };

                $this->app = $app;
                $this->dispatcher = $this->app['dispatcher'];
                $this->merchantProvider = $this->app['merchant.provider'];
            }


        public function lightbox_trigger() {
            wp_register_script( 'expressly-trigger', 'https://assets01.buyexpressly.com/lightbox/trigger-v2.js', null, null, true );
            wp_enqueue_script( 'expressly-trigger');
        }

        public function plugin_action_links( $links ) {
                $links[] = '<a href="'. esc_url( get_admin_url(null, 'admin.php?page=wc-settings&tab=expressly') ) .'">Settings</a>';
                $links[] = '<a href="https://portal.buyexpressly.com/my-account/profile/api" target="_blank">Get API Key</a>';
                return $links;
            }

            public function plugins_loaded()
            {
                add_filter(
                    'woocommerce_settings_tabs_array',
                    array($this, 'woocommerce_settings_tabs_array'),
                    50
                );
                add_action(
                    'woocommerce_settings_tabs_expressly',
                    array($this, 'woocommerce_settings_tabs_expressly')
                );
                add_action(
                    'woocommerce_update_options_expressly',
                    array($this, 'woocommerce_update_options_expressly'),
                    10
                );
                $self = $this;
//                add_action('woocommerce_thankyou', function ($orderId) use ($self) {
//                    woocommerce_order_details_table($orderId);
//                    $self->banner();
//                }, 10);
//                add_action(
//                    'expressly_banner',
//                    array($this, 'banner'),
//                    10
//                );
            }

            /**
             * Add a new settings tab to the WooCommerce settings tabs array.
             *
             * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
             * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
             */
            public function woocommerce_settings_tabs_array($settings_tabs)
            {
                $settings_tabs['expressly'] = 'Expressly';

                return $settings_tabs;
            }

            /**
             * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
             *
             * @uses woocommerce_admin_fields()
             * @uses self::get_settings()
             */
            public function woocommerce_settings_tabs_expressly()
            {
                woocommerce_admin_fields($this->get_settings());
            }

            /**
             * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
             *
             * @uses woocommerce_update_options()
             * @uses self::get_settings()
             */
            public function woocommerce_update_options_expressly()
            {
                $settings = $this->get_settings();
                woocommerce_update_options($settings);

                $merchant = $this->merchantProvider->getMerchant(true);
                $event = new PasswordedEvent($merchant);

                try {
                    $this->app['dispatcher']->dispatch(MerchantSubscriber::MERCHANT_REGISTER, $event);

                    if (!$event->isSuccessful()) {
                        throw new InvalidAPIKeyException($this->error_formatter($event));
                    }
                } catch (\Exception $e) {
                    $this->app['logger']->error(ExceptionFormatter::format($e));

                    /*
                     * Duplicated core code from formatting.php
                     * Cannot use  WC_Admin_Settings::add_error($e->getMessage()); as it escapes the HTML surrounding the error message
                     */
                    echo sprintf(
                        '<div id="message" class="error fade"><p><strong>%s</strong></p></div>',
                        $e->getMessage()
                    );
                }
            }

            public function get_settings()
            {
                $settings = array(
                    'section_title' => array(
                        'name' => __('Expressly', 'wc_expressly'),
                        'type' => 'title',
                        'desc' => __('Provide settings for Expressly integration', 'wc_expressly'),
                        'id' => 'wc_expressly_section_title',
                    ),
                    'password' => array(
                        'name' => __('API Key', 'wc_expressly'),
                        'type' => 'text',
                        'desc' => __(
                            'API Key provided from our <a href="https://portal.buyexpressly.com">portal</a>. If you do not have an API Key, please follow the previous link for instructions on how to create one.',
                            'wc_expressly'
                        ),
                        'id' => 'wc_expressly_apikey',
                        'css' => 'width:100%;'
                    ),
                    'email_subject' => array(
                        'name' => __('Welcome Email Subject', 'wc_expressly'),
                        'type' => 'text',
                        'desc' => __(
                            'Welcome email subject line. You may include the macros {$firstName} and {$lastName}',
                            'wc_expressly'
                        ),
                        'id' => 'wc_expressly_email_subject',
                        'css' => 'width:100%;'
                    ),
                    'email_template' => array(
                        'name' => __('Welcome Email Template', 'wc_expressly'),
                        'type' => 'textarea',
                        'desc' => __(
                            'Enter the html code for the Welcome email for migrated users. This should include either their password using the macro {$password} or a link to the password reset page.',
                            'wc_expressly'
                        ),
                        'id' => 'wc_expressly_email_template',
                        'css' => 'width:100%;height:10em;'
                    ),
                    'section_end' => array(
                        'type' => 'sectionend',
                        'id' => 'wc_expressly_section_end'
                    )
                );

                return $settings;
            }

            public function query_vars($vars)
            {
                $vars[] = 'expressly';
                $vars[] = 'email';
                $vars[] = 'uuid';

                return $vars;
            }

            public function template_redirect()
            {
                $query = get_query_var('expressly');
                $route = $this->app['route.resolver']->process(preg_replace('/.*(expressly\/.*)/i', '/${1}', $_SERVER['REQUEST_URI']));

                if ($route instanceof Route) {
                    status_header(200);
                    switch ($route->getName()) {
                        case Ping::getName():
                            $this->ping();
                            break;
                        case Registered::getName():
                            $this->registered();
                            break;
                        case UserData::getName():
                            $data = $route->getData();
                            $this->retrieveUserByEmail($data['email']);
                            break;
                        case CampaignPopup::getName():
                            $data = $route->getData();
                            $this->migratestart($data['uuid']);
                            break;
                        case CampaignMigration::getName():
                            $data = $route->getData();
                            $this->migratecomplete($data['uuid']);
                            break;
                        case BatchCustomer::getName():
                            $this->batchCustomer();
                            break;
                        case BatchInvoice::getName():
                            $this->batchInvoice();
                            break;
                    }
                }
            }

            public function banner()
            {
                $merchant = $this->app['merchant.provider']->getMerchant();
                $user = wp_get_current_user();
                $email = $user->user_email;
                $event = new BannerEvent($merchant, $email);

                try {
                    $this->dispatcher->dispatch(BannerSubscriber::BANNER_REQUEST, $event);

                    if (!$event->isSuccessful()) {
                        throw new GenericException($this->error_formatter($event));
                    }
                } catch (GenericException $e) {
                    $this->app['logger']->error($e);
                }

                echo BannerHelper::toHtml($event);
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

                    $merchant = $this->app['merchant.provider']->getMerchant();

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

                            $from = \DateTime::createFromFormat('Y-m-d', $customer->from, new \DateTimeZone('UTC'));
                            $to = \DateTime::createFromFormat('Y-m-d', $customer->to, new \DateTimeZone('UTC'));

                            foreach ($orderPosts as $post) {
                                $wpOrder = new WC_Order($post->ID);

                                $orderDate = new \DateTime($wpOrder->order_date, new \DateTimeZone('UTC'));
                                $orderDate = \DateTime::createFromFormat('Y-m-d', $orderDate->format('Y-m-d'), new \DateTimeZone('UTC'));
                                if ($orderDate >= $from && $orderDate < $to) {
                                    $total = 0.0;
                                    $tax = 0.0;
                                    $count = 0;
                                    $order = new Order();

                                    foreach ($wpOrder->get_items('line_item') as $lineItem) {
                                        $tax += (double)$lineItem['line_tax'];
                                        $total += (double)$lineItem['line_total'];
                                        $count++;

                                        if (get_woocommerce_currency()) {
                                            $order->setCurrency(get_woocommerce_currency());
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

                    $presenter = new BatchInvoicePresenter($invoices);
                    wp_send_json($presenter->toArray());
                } catch (GenericException $e) {
                    $this->app['logger']->error($e);
                    wp_send_json(array());
                }
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
                        if (email_exists($email)) {
                            $users[] = $email;
                        }
                    }

                    $presenter = new BatchCustomerPresenter($users);
                    wp_send_json($presenter->toArray());
                } catch (GenericException $e) {
                    $this->app['logger']->error($e);
                    wp_send_json(array());
                }
            }

            private function migratecomplete($uuid)
            {
                // get key from url
                if (empty($uuid)) {
                    wp_redirect(home_url());
                }

                $exists = false;
                $merchant = $this->app['merchant.provider']->getMerchant();
                $event = new Expressly\Event\CustomerMigrateEvent($merchant, $uuid);
                $xlyerror = null;

                try {
                    $this->dispatcher->dispatch(CustomerMigrationSubscriber::CUSTOMER_MIGRATE_DATA, $event);

                    $json = $event->getContent();
                    if (!$event->isSuccessful()) {
                        if (!empty($json['code']) && $json['code'] == 'USER_ALREADY_MIGRATED') {
                            $exists = true;
                        }

                        throw new UserExistsException($this->error_formatter($event));
                    }

                    $email = $json['migration']['data']['email'];
                    $user_id = email_exists($email);

                    if (!$user_id) {
                        $customer = $json['migration']['data']['customerData'];

                        // Generate the password and create the user
                        $password = wp_generate_password(12, false);
                        $user_id = wp_create_user($email, $password, $email);
                        $website_url = '';
                        if (isset($customer['onlinePresence'])) {
                            foreach ($customer['onlinePresence'] as $item) {
                                if ($item['field'] == 'website') {
                                    $website_url = $item['value'];
                                }
                            }
                        }

                        wp_update_user(array(
                            'ID' => $user_id,
                            'first_name' => $customer['firstName'],
                            'last_name' => $customer['lastName'],
                            'display_name' => $customer['firstName'] . ' ' . $customer['lastName'],
                            'user_url' => $website_url,
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

                            if (!empty($address['companyName'])) {
                                update_user_meta($user_id, $prefix . '_company', $address['companyName']);
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
                        //$emailBody = file_get_contents(TEMPLATEPATH . 'includes/email-welcome.php');
                        $emailVars = array(
                            '{$password}'   => $password,
                            '{$firstName}'  => $customer['firstName'],
                            '{$lastName}'   => $customer['lastName']);

                        wp_mail(
                            $email,
                            strtr(get_option(WC_Expressly::EMAIL_SUBJECT), $emailVars),
                            strtr(get_option(WC_Expressly::EMAIL_TEMPLATE), $emailVars),
                            array('Content-Type: text/html; charset=UTF-8'));

                        // Forcefully log user in
                        wp_set_auth_cookie($user_id);
                    } else {
                        $exists = true;
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

                    if (!$exists) {
                        wp_redirect('https://prod.expresslyapp.com/api/redirect/migration/' . $uuid . '/success');
                        return;
                    }
                } catch (\Exception $e) {
                    $this->app['logger']->error(ExceptionFormatter::format($e));
                    $xlyerror = $e->getMessage();
                }

                if ($exists) {
                    wp_redirect('https://prod.expresslyapp.com/api/redirect/migration/' . $uuid . '/exists?loginUrl=' . urlencode(site_url() . '/index.php/my-account/') );
                    return;
                }

                wp_redirect('https://prod.expresslyapp.com/api/redirect/migration/' . $uuid . '/failed?e=' . urlencode($xlyerror));
            }

            private function migratestart($uuid)
            {
                $merchant = $this->app['merchant.provider']->getMerchant();
                $event = new CustomerMigrateEvent($merchant, $uuid);

                try {
                    $this->dispatcher->dispatch(CustomerMigrationSubscriber::CUSTOMER_MIGRATE_POPUP, $event);

                    if (!$event->isSuccessful()) {
                        throw new GenericException($this->error_formatter($event));
                    }
                } catch (\Exception $e) {
                    $this->app['logger']->error(ExceptionFormatter::format($e));
                    wp_redirect('https://prod.expresslyapp.com/api/redirect/migration/' . $uuid . '/failed');
                }

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

            private function registered()
            {
                $presenter = new RegisteredPresenter("WooCommerce", WC()->version);
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
                                ->setCompanyName(get_user_meta($user_id, $prefix . '_company', true))
                                ->setCity(get_user_meta($user_id, $prefix . '_city', true))
                                ->setZip(get_user_meta($user_id, $prefix . '_postcode', true));

                            $iso3 = $countryCodeProvider->getIso3(get_user_meta($user_id, $prefix . '_country', true));
                            if($iso3 != false) {
                                $address->setCountry($iso3);
                            }
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
                            if ($billingAddress && $billingAddress->getCity()) {
                                $customer->addAddress($billingAddress, true, Address::ADDRESS_BOTH);
                            }
                        } else {
                            if ($billingAddress && $billingAddress->getCity()) {
                                $customer->addAddress($billingAddress, true, Address::ADDRESS_BILLING);
                            }
                            if ($billingAddress && $shippingAddress->getCity()) {
                                $customer->addAddress($shippingAddress, true, Address::ADDRESS_SHIPPING);
                            }
                        }

                        if ($user->user_url) {
                            $website = new Social();
                            $website->setField(Social::SOCIAL_WEBSITE);
                            $website->setValue($user->user_url);
                            $customer->addSocial($website);
                        }

                        $merchant = $this->app['merchant.provider']->getMerchant();
                        $response = new CustomerMigratePresenter($merchant, $customer, $emailAddr, $user->ID);
                        wp_send_json($response->toArray());
                    } else {
                        status_header(404);
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

            public static function register_activation_hook()
            {
                if (!current_user_can('activate_plugins')) {
                    return;
                }

                $apikey = get_option(WC_Expressly_MerchantProvider::APIKEY);
                if (empty($apikey))
                {
                    update_option(WC_Expressly_MerchantProvider::APIKEY, '');
                }
                update_option(WC_Expressly_MerchantProvider::HOST, get_option('siteurl'));
                update_option(WC_Expressly_MerchantProvider::PATH, '?expressly=');

                $emailTemplate = get_option(WC_Expressly::EMAIL_TEMPLATE);
                if (empty($emailTemplate)) {
                    update_option(WC_Expressly::EMAIL_TEMPLATE, '<body><span>Your Password is</span> <b>{$password}</b></body>');
                }

                $emailSubject = get_option(WC_Expressly::EMAIL_SUBJECT);
                if (empty($emailSubject)) {
                    update_option(WC_Expressly::EMAIL_SUBJECT, 'Welcome {$firstName}!');
                }
            }

            public function register_deactivation_hook()
            {
                $merchant = $this->merchantProvider->getMerchant();

                try {
                    $event = new PasswordedEvent($merchant);
                    $this->dispatcher->dispatch(MerchantSubscriber::MERCHANT_DELETE, $event);
                } catch (\Exception $e) {
                    $this->app['logger']->error(ExceptionFormatter::format($e));
                }
            }

            public static function register_uninstall_hook()
            {
            }
        }

        $WC_Expressly = new WC_Expressly();
    }
}