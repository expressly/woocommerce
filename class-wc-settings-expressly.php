<?php

/**
 * WC_Settings_Expressly
 */
class WC_Settings_Expressly {

    /**
     * Bootstraps the class and hooks required actions & filters.
     *
     */
    public static function init()
    {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_expressly', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_expressly', __CLASS__ . '::update_settings' );
    }


    /**
     * Add a new settings tab to the WooCommerce settings tabs array.
     *
     * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
     * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
     */
    public static function add_settings_tab( $settings_tabs )
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
    public static function settings_tab()
    {
        woocommerce_admin_fields( self::get_settings() );
    }


    /**
     * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
     *
     * @uses woocommerce_update_options()
     * @uses self::get_settings()
     */
    public static function update_settings()
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
                'name'     => __( 'Expressly', 'woocommerce' ),
                'type'     => 'title',
                'desc'     => 'Provide settings for Expressly integration',
                'id'       => 'wc_expressly_section_title'
            ),

            'host' => array(
                'name' => __( 'Host', 'woocommerce' ),
                'type' => 'text',
                'desc' => __( 'will be hidden', 'woocommerce' ),
                'id'   => 'wc_expressly_host',
                'default' => sprintf('://%s', $_SERVER['HTTP_HOST']),
            ),
            'destination' => array(
                'name' => __( 'Destination', 'woocommerce' ),
                'type' => 'text',
                'desc' => __( 'Redirect destination after checkout', 'woocommerce' ),
                'id'   => 'wc_expressly_destination',
                'default' => '/',
            ),
            'offer' => array(
                'name' => __( 'Offer', 'woocommerce' ),
                'type' => 'checkbox',
                'desc' => __( 'Show offers after checkout', 'woocommerce' ),
                'id'   => 'wc_expressly_offer',
                'default' => 'yes',
            ),
            'password' => array(
                'name' => __( 'Password', 'woocommerce' ),
                'type' => 'text',
                'desc' => __( 'Expressly password for your store', 'woocommerce' ),
                'id'   => 'wc_expressly_password',
                'default' => '',
            ),
            'path' => array(
                'name' => __( 'Path', 'woocommerce' ),
                'type' => 'text',
                'desc' => __( 'will be hidden', 'woocommerce' ),
                'id'   => 'wc_expressly_path',
                'default' => '?controller=dispatcher&fc=module&module=expressly&xly=',
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_expressly_section_end',
            )
        );

        return apply_filters( 'wc_expressly_settings', $settings );
    }

}

WC_Settings_Expressly::init();