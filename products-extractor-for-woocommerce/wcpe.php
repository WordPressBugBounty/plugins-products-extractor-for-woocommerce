<?php

declare(strict_types=1);

/**
 * Plugin Name: افزونه رسمی ترب
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس
 * Version: 2.1.2
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Torob
 * Author URI: https://torob.com/
 * Developer: Torob
 * Developer URI: https://torob.com/
 * WC requires at least: 6.9
 * WC tested up to: 10.7
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Torob\TorobPlugin;
use Torob\Utils\Options;

defined('ABSPATH') || exit();

const TOROB_PLUGIN_VERSION = '2.1.2';

require_once plugin_dir_path(__FILE__) . 'deps/autoload.php';

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__);
    }
});

function torob_wcpe_render_missing_woocommerce_notice(): void
{
    $message = 'افزونه رسمی ترب برای اجرا به ووکامرس نیاز دارد، اما ووکامرس شناسایی نشد. لطفا فعال بودن ووکامرس و مسیر نصب آن را بررسی کنید.';
    ?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php echo esc_html($message); ?>
		</p>
	</div>
	<?php
}

register_activation_hook(__FILE__, [
    TorobPlugin::class,
    'activate'
]);
register_deactivation_hook(__FILE__, [TorobPlugin::class, 'deactivate']);
register_uninstall_hook(__FILE__, [TorobPlugin::class, 'uninstall']);

add_action(
    'plugins_loaded',
    static function (): void {
        if (!Options::isWooCommerceActive()) {
            add_action('admin_notices', 'torob_wcpe_render_missing_woocommerce_notice');

            return;
        }

        $torob_instance = TorobPlugin::instance();
        $torob_instance->register_hooks();
    },
    20
);
