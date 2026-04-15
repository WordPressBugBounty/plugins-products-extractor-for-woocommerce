<?php

declare(strict_types=1);

namespace Torob\Admin;

use Torob\OrderStatusTracking\OrderHandler;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Class AdminPage
 *
 * Handles the plugin-wide admin settings page.
 */
class AdminPage
{
    const MENU_SLUG = 'torob-settings';
    const NONCE_ACTION = 'torob_settings_save';
    const NONCE_FIELD = 'torob_settings_nonce';

    /**
     * Register admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Add top-level Torob menu page.
     */
    public function add_menu(): void
    {
        $icon_url = plugins_url('assets/torob.png', dirname(__FILE__, 2));

        add_menu_page(
            'تنظیمات ترب',
            'تنظیمات ترب',
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page'],
            $icon_url,
            56
        );
    }

    /**
     * Enqueue admin styles.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_styles(string $hook_suffix): void
    {
        wp_add_inline_style('wp-admin', $this->get_menu_icon_css());

        if ('toplevel_page_' . self::MENU_SLUG !== $hook_suffix) {
            return;
        }

        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    /**
     * Handle form submission and render the settings page.
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $notices = [];

        if (($_POST['torob_settings_submit'] ?? null) !== null) {
            check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
            $notices = $this->handle_save();
        }

        $this->render_notices($notices);
        $this->render_page_html();
    }

    /**
     * Process form submission and save settings.
     *
     * @return array Notices to display.
     */
    private function handle_save(): array
    {
        $enabled = ($_POST[OrderHandler::OPTION_ORDER_STATUS_ENABLED] ?? null) !== null
            ? OrderHandler::OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE
            : OrderHandler::OPTION_ORDER_STATUS_ENABLED_FALSE_VALUE;
        update_option(OrderHandler::OPTION_ORDER_STATUS_ENABLED, $enabled, true);

        return [
            ['type' => 'success', 'message' => 'تنظیمات با موفقیت ذخیره شد.']
        ];
    }

    /**
     * Render admin notices.
     *
     * @param array $notices Array of notices with 'type' and 'message' keys.
     */
    private function render_notices(array $notices): void
    {
        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }
    }

    /**
     * Render the settings page HTML.
     */
    private function render_page_html(): void
    { ?>
		<div class="wrap">
			<h1>تنظیمات ترب</h1>

			<form method="post" action="">
				<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

				<table class="form-table">
					<?php $this->render_order_status_row(); ?>
				</table>

				<?php submit_button('ذخیره تنظیمات', 'primary', 'torob_settings_submit'); ?>
			</form>
		</div>
		<?php }

    /**
     * Render the order status toggle row.
     */
    private function render_order_status_row(): void
    {
        $enabled =
            get_option(OrderHandler::OPTION_ORDER_STATUS_ENABLED, OrderHandler::OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE)
            === OrderHandler::OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE;
        ?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr(OrderHandler::OPTION_ORDER_STATUS_ENABLED); ?>">درگاه وضعیت سفارش‌ها</label>
			</th>
			<td>
				<label class="torob-toggle-switch">
					<input type="checkbox"
					       id="<?php echo esc_attr(OrderHandler::OPTION_ORDER_STATUS_ENABLED); ?>"
					       name="<?php echo esc_attr(OrderHandler::OPTION_ORDER_STATUS_ENABLED); ?>"
					       value="1"
						<?php checked($enabled, true); ?> />
					<span class="torob-slider"></span>
				</label>
				<p class="description">
					با فعال‌سازی این گزینه، شکایات مشتریان در ترب به‌صورت خودکار با اطلاعات وضعیت سفارش (در صورت تکمیل) پاسخ داده می‌شود.
					<br>
					<strong>پیش‌فرض:</strong> فعال
				</p>
			</td>
		</tr>
		<?php
    }

    /**
     * Get CSS for menu icon styling.
     *
     * @return string CSS string.
     */
    private function get_menu_icon_css(): string
    {
        return '
			#adminmenu .toplevel_page_torob-settings .wp-menu-image img {
				width: 20px;
				height: 20px;
				padding: 6px 0;
			}
		';
    }

    /**
     * Get CSS for the settings page form controls.
     *
     * @return string CSS string.
     */
    private function get_admin_css(): string
    {
        return '

			.torob-toggle-switch {
				position: relative;
				display: inline-block;
				width: 50px;
				height: 24px;
				vertical-align: middle;
			}

			.torob-toggle-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}

			.torob-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .4s;
				border-radius: 24px;
			}

			.torob-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .4s;
				border-radius: 50%;
			}

			.torob-toggle-switch input:checked + .torob-slider {
				background-color: #2271b1;
			}

			.torob-toggle-switch input:focus + .torob-slider {
				box-shadow: 0 0 1px #2271b1;
			}

			.torob-toggle-switch input:checked + .torob-slider:before {
				transform: translateX(26px);
			}

			.torob-toggle-switch + .description {
				margin-top: 10px;
			}
		';
    }
}
