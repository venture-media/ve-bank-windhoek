<?php
/**
 * Plugin Name:       VE Bank Windhoek Gateway
 * Plugin URI:        https://www.venture.com.na/
 * Description:       Bank Windhoek (Adumo Virtual) payment gateway integration for Venture Events.
 * Version:           0.9.3
 * Author:            Leon de Klerk
 * Author URI:        https://github.com/Leon2332
 * Requires Plugins:  venture-events
 */

if (!defined('ABSPATH')) exit;

define('VE_BANKWINDHOEK_VERSION', '0.9.3');
define('VE_BANKWINDHOEK_PATH', plugin_dir_path(__FILE__));
define('VE_BANKWINDHOEK_URL', plugin_dir_url(__FILE__));

require_once VE_BANKWINDHOEK_PATH . 'includes/class-ve-bankwindhoek-gateway.php';

// Initialize the gateway
add_action('init', function() {
    if (class_exists('VE_BankWindhoek_Gateway')) {
        new VE_BankWindhoek_Gateway();
    }
});
