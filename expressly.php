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

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    require_once('class-wc-settings-expressly.php');

}