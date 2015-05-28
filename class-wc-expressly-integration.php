<?php

if ( ! class_exists( 'WC_Expressly_Integration' ) ) :

    class WC_Expressly_Integration extends WC_Integration
    {

        /**
         * Init and hook in the integration.
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id                 = 'expressly';
            $this->method_title       = __( 'Expressly', 'woocommerce-expressly' );
            $this->method_description = __( 'Provide settings for Expressly integration', 'woocommerce-expressly' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->host        = $this->get_option( 'host' );
            $this->destination = $this->get_option( 'destination' );
            $this->offer       = $this->get_option( 'offer' );
            $this->password    = $this->get_option( 'password' );
            $this->path        = $this->get_option( 'path' );

            // Actions.
            add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );

        }

        /**
         * Initialize integration settings form fields.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'host' => array(
                    'title' => __( 'Host', 'woocommerce-expressly' ),
                    'type' => 'text',
                    'description' => __( 'will be hidden', 'woocommerce-expressly' ),
                    'default' => sprintf('://%s', $_SERVER['HTTP_HOST']),
                ),
                'destination' => array(
                    'title' => __( 'Destination', 'woocommerce-expressly' ),
                    'type' => 'text',
                    'description' => __( 'Redirect destination after checkout', 'woocommerce-expressly' ),
                    'default' => '/',
                ),
                'offer' => array(
                    'title' => __( 'Offer', 'woocommerce-expressly' ),
                    'type' => 'checkbox',
                    'description' => __( 'Show offers after checkout', 'woocommerce-expressly' ),
                    'default' => 'yes',
                ),
                'password' => array(
                    'title' => __( 'Password', 'woocommerce-expressly' ),
                    'type' => 'text',
                    'description' => __( 'Expressly password for your store', 'woocommerce-expressly' ),
                    'default' => Expressly\Entity\Merchant::createPassword(),
                ),
                'path' => array(
                    'title' => __( 'Path', 'woocommerce-expressly' ),
                    'type' => 'text',
                    'description' => __( 'will be hidden', 'woocommerce-expressly' ),
                    'default' => '?__xly=',
                ),
            );
        }

    }

endif;