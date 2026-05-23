<?php

declare(strict_types=1);

/**
 * Plugin Name: افزونه رسمی ترب
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس
 * Version: 2.1.1
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Torob
 * Author URI: https://torob.com/
 * Developer: Torob
 * Developer URI: https://torob.com/
 * WC requires at least: 6.9
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

use Torob\TorobPlugin;
use Torob\Utils\Options;

defined('ABSPATH') || exit();

const TOROB_PLUGIN_VERSION = '2.1.1';

require_once plugin_dir_path(__FILE__) . 'deps/autoload.php';

register_activation_hook(__FILE__, [
    TorobPlugin::class,
    'activate'
]);
register_deactivation_hook(__FILE__, [TorobPlugin::class, 'deactivate']);
register_uninstall_hook(__FILE__, [TorobPlugin::class, 'uninstall']);

add_action(
    'plugins_loaded',
    static function (): void {
        if (!Options::isWooCommerceActive() || !class_exists('WooCommerce')) {
            return;
        }

        $torob_instance = TorobPlugin::instance();
        $torob_instance->register_hooks();
    },
    20
);
