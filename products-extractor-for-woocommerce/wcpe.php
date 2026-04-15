<?php

declare(strict_types=1);

/**
 * Plugin Name: افزونه رسمی ترب
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس
 * Version: 2.0.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Torob
 * Author URI: https://torob.com/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: products-extractor-for-woocommerce
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit();

const TOROB_PLUGIN_VERSION = '2.0.0';

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true)) {
    $torob_instance = Torob\TorobPlugin::instance();
    $torob_instance->register_hooks();
}
