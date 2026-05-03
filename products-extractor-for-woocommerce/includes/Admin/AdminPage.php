<?php declare(strict_types=1);

namespace Torob\Admin;

use Torob\ProductExtraction\ProductExtractor;
use Torob\ProductWebhook\WebhookHandler;
use Torob\Utils\Options;
use Torob\Utils\TorobHttpClient;

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
    const CONNECTIVITY_CHECK_NONCE_ACTION = 'torob_connectivity_check';
    const CONNECTIVITY_CHECK_NONCE_FIELD = 'torob_connectivity_check_nonce';
    const CONNECTIVITY_CHECK_FORM_ID = 'torob-connectivity-check-form';
    const CONNECTIVITY_CHECK_SUBMIT = 'torob_connectivity_check_submit';
    const PENDING_WEBHOOK_PAGINATION_PAGE_SIZE = 20;
    const PRODUCTS_PREVIEW_LIMIT = 10;
    const PRODUCTS_PREVIEW_QUERY_PARAM = 'torob_products_preview';

    private ProductExtractor $product_extractor;
    private WebhookHandler $product_page_webhook_handler;

    public function __construct(ProductExtractor $product_extractor, WebhookHandler $product_page_webhook_handler)
    {
        $this->product_extractor = $product_extractor;
        $this->product_page_webhook_handler = $product_page_webhook_handler;
    }

    /**
     * Register admin hooks.
     */
    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Register settings shown on the shared Torob admin page.
     */
    public function register_settings(): void
    {
        register_setting('torob_settings_group', Options::ORDER_STATUS_ENABLED_OPTION);
        register_setting('torob_settings_group', Options::ORDERS_LIST_API_ENABLED_OPTION);
        register_setting('torob_settings_group', Options::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION);
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
        } elseif (($_POST[self::CONNECTIVITY_CHECK_SUBMIT] ?? null) !== null) {
            check_admin_referer(self::CONNECTIVITY_CHECK_NONCE_ACTION, self::CONNECTIVITY_CHECK_NONCE_FIELD);
            $notices = $this->handle_connectivity_check();
        }

        $this->render_notices($notices);
        if ($this->is_products_preview_page()) {
            $this->render_products_preview_page_html();

            return;
        }

        $this->render_page_html();
    }

    /**
     * Process form submission and save settings.
     *
     * @return array Notices to display.
     */
    private function handle_save(): array
    {
        Options::setOrderStatusEnabled(($_POST[Options::ORDER_STATUS_ENABLED_OPTION] ?? null) !== null);
        Options::setOrdersListApiEnabled(($_POST[Options::ORDERS_LIST_API_ENABLED_OPTION] ?? null) !== null);

        $webhook_enabled = ($_POST[Options::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION] ?? null) !== null;
        $this->product_page_webhook_handler->set_webhook_enabled($webhook_enabled);

        return [['type' => 'success', 'message' => 'تنظیمات با موفقیت ذخیره شد.']];
    }

    /**
     * Run Torob connectivity diagnostics.
     *
     * @return array Notices to display.
     */
    private function handle_connectivity_check(): array
    {
        if (!Options::isProductPageWebhookEnabled()) {
            return [[
                'type' => 'error',
                'message' => 'برای بررسی ارتباط با ترب، ابتدا وب‌هوک را فعال کنید.'
            ]];
        }

        $result = TorobHttpClient::check_health();
        $details = $result->get_details_array();

        if ($result->is_successful()) {
            return [[
                'type' => 'success',
                'message' => 'ارتباط با ترب برقرار است.',
                'details' => $details
            ]];
        }

        return [[
            'type' => 'error',
            'message' => 'ارتباط با ترب برقرار نشد. برای رفع مشکل، از توسعه‌دهنده یا تیم فنی سایت خود کمک بگیرید.',
            'details' => $details
        ]];
    }

    /**
     * Render admin notices.
     *
     * @param array $notices Array of notices with 'type' and 'message' keys.
     */
    private function render_notices(array $notices): void
    {
        foreach ($notices as $notice) {
            $details = $notice['details'] ?? [];
            ?>
			<div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
				<p><?php echo esc_html($notice['message']); ?></p>
				<?php if (!empty($details) && is_array($details)): ?>
					<ul class="torob-connectivity-details">
						<?php foreach ($details as $detail): ?>
							<li>
								<strong><?php echo esc_html($detail['label']); ?>:</strong>
								<?php echo esc_html($detail['value']); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php
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

				<div class="torob-admin-panel">
					<h2>تنظیمات عمومی</h2>
					<table class="form-table torob-form-table">
						<?php $this->render_order_status_row(); ?>
						<?php $this->render_order_tracking_row(); ?>
					</table>
				</div>
                <div class="torob-admin-panel">
                    <h2>محصولات</h2>
                    <table class="form-table torob-form-table">
                        <?php $this->render_products_preview_row(); ?>
                        <?php $this->render_webhook_enabled_row(); ?>
                    </table>
                </div>
				<div class="torob-actions">
					<?php submit_button('ذخیره تنظیمات', 'primary', 'torob_settings_submit', false); ?>
				</div>
			</form>
			<?php if (Options::isProductPageWebhookEnabled()): ?>
				<form method="post" action="" id="<?php echo esc_attr(self::CONNECTIVITY_CHECK_FORM_ID); ?>">
					<?php wp_nonce_field(self::CONNECTIVITY_CHECK_NONCE_ACTION, self::CONNECTIVITY_CHECK_NONCE_FIELD); ?>
					<input type="hidden"
					       name="<?php echo esc_attr(self::CONNECTIVITY_CHECK_SUBMIT); ?>"
					       value="1" />
				</form>
			<?php endif; ?>

			<?php if (Options::isProductPageWebhookReady()): ?>
				<?php $this->render_pending_webhook_queue(); ?>
			<?php endif; ?>
		</div>
		<?php }

    /**
     * Render a products preview link using the same layout as other options.
     */
    private function render_products_preview_row(): void
    {
        $preview_url = $this->get_products_preview_url();
        ?>
		<tr>
			<th scope="row">پیش‌نمایش اطلاعات محصولات</th>
			<td>
				<p class="description">
					این بخش نشان می‌دهد ترب هنگام خواندن اطلاعات محصولات، چه داده‌ای از سایت شما دریافت می‌کند.
					در این قسمت فقط ۱۰ محصول آخر نمایش داده می‌شود و این کار هیچ اطلاعاتی را برای ترب ارسال نمی‌کند.
				</p>
				<p class="torob-products-preview-actions">
					<a class="button button-secondary" href="<?php echo esc_url($preview_url); ?>">مشاهده پیش‌نمایش محصولات</a>
				</p>
			</td>
		</tr>
		<?php
    }

    /**
     * Render the standalone products preview page.
     */
    private function render_products_preview_page_html(): void
    {
        $settings_url = $this->get_settings_url();
        ?>
		<div class="wrap torob-products-preview-page">
			<h1>پیش‌نمایش اطلاعات محصولات</h1>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url($settings_url); ?>">بازگشت به تنظیمات ترب</a>
			</p>

			<div class="torob-admin-panel">
				<p>
					این پیش‌نمایش نمونه‌ای از داده محصولاتی است که افزونه در اختیار ترب قرار می‌دهد.
					برای بررسی عنوان، قیمت، موجودی، تصویر، لینک و مشخصات محصول قبل از هماهنگی با پشتیبانی ترب یا هنگام پیدا کردن علت تفاوت اطلاعات سایت و ترب از آن استفاده کنید.
				</p>
				<p>
					در این صفحه فقط ۱۰ محصول آخر نمایش داده می‌شود و هیچ داده‌ای را برای ترب ارسال نمی‌کند.
				</p>
				<?php $this->render_products_preview_data(); ?>
			</div>
		</div>
		<?php
    }

    /**
     * Render pretty-printed product data from ProductExtractor::get_all_products().
     */
    private function render_products_preview_data(): void
    {
        $response_data = $this->product_extractor->get_all_products(false, self::PRODUCTS_PREVIEW_LIMIT, 1, false);
        $preview_data = [
            'products' => $response_data['products'] ?? []
        ];

        $json = wp_json_encode($preview_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        ?>
		<div class="torob-products-preview-data">
			<div class="torob-products-preview-data-header">
				<strong>JSON</strong>
				<span>products</span>
			</div>
			<pre dir="ltr"><code><?php echo esc_html($json); ?></code></pre>
		</div>
		<?php
    }

    /**
     * Determine whether the standalone products preview page is requested.
     */
    private function is_products_preview_page(): bool
    {
        return (int) ($_GET[self::PRODUCTS_PREVIEW_QUERY_PARAM] ?? 0) === 1;
    }

    /**
     * Get the main Torob settings URL.
     */
    private function get_settings_url(): string
    {
        return add_query_arg([
            'page' => self::MENU_SLUG
        ], admin_url('admin.php'));
    }

    /**
     * Get the standalone products preview URL.
     */
    private function get_products_preview_url(): string
    {
        return add_query_arg([
            'page' => self::MENU_SLUG,
            self::PRODUCTS_PREVIEW_QUERY_PARAM => '1'
        ], admin_url('admin.php'));
    }

    /**
     * Render the order status toggle row.
     */
    private function render_order_status_row(): void
    {
        $option_name = Options::ORDER_STATUS_ENABLED_OPTION;
        $enabled = Options::isOrderStatusEnabled();
        ?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr($option_name); ?>">پاسخ‌گویی خودکار به شکایات</label>
			</th>
			<td>
				<label class="torob-toggle-switch">
					<input type="checkbox"
					       id="<?php echo esc_attr($option_name); ?>"
					       name="<?php echo esc_attr($option_name); ?>"
					       value="1"
						<?php checked($enabled); ?> />
					<span class="torob-slider"></span>
				</label>
				<p class="description">
					شکایات مشتریان در ترب به‌صورت خودکار با اطلاعات وضعیت سفارش (در صورت تکمیل) پاسخ داده می‌شود.
				</p>
			</td>
		</tr>
		<?php
    }

    /**
     * Render the product page webhook toggle row.
     */
    private function render_webhook_enabled_row(): void
    {
        $enabled = Options::isProductPageWebhookEnabled();
        $is_token_set = Options::getToken() !== '';
        ?>
		<tr>
			<th scope="row">
				<label class="torob-webhook-label" for="<?php echo esc_attr(Options::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION); ?>">
					وب‌هوک
					<span class="torob-experimental-badge">آزمایشی</span>
				</label>
			</th>
			<td>
				<label class="torob-toggle-switch">
					<input type="checkbox"
					       id="<?php echo esc_attr(Options::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION); ?>"
					       name="<?php echo esc_attr(Options::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION); ?>"
					       value="1"
						<?php checked($enabled); ?> />
					<span class="torob-slider"></span>
				</label>
				<p class="description">
					 افزونه تغییر اطلاعات محصولات را رصد کرده و آن‌ها را در صف ارسال قرار می‌دهد و بعد از چند دقیقه به ترب ارسال می‌کند.
                    به این ترتیب تغییرات شما برروی محصولات سریع‌تر در ترب منعکس می‌شود.
				</p>
				<?php if ($enabled): ?>
					<?php if ($is_token_set): ?>
						<strong class="description">وب‌هوک فعال است.</strong>
					<?php else: ?>
						<div class="torob-webhook-status torob-webhook-status-pending">
							<strong>در انتظار فعال‌سازی وب‌هوک از سمت ترب</strong>
							<p>وب‌هوک سایت شما حداکثر تا ۴۸ ساعت آینده از سمت ترب فعال می‌شود. پس از فعال‌سازی، تغییرات محصولات به‌صورت خودکار برای ترب ارسال خواهد شد.</p>
						</div>
					<?php endif; ?>
					<div class="torob-connectivity-check">
						<p class="description">
							وب‌هوک تغییرات محصول را از سایت شما به ترب می‌فرستد و برای کارکرد درست، این مسیر باید برقرار باشد.
							با این دکمه ارسال درخواست از سایت به ترب را بررسی کنید.
						</p>
						<button type="submit"
						        class="button button-secondary"
						        form="<?php echo esc_attr(self::CONNECTIVITY_CHECK_FORM_ID); ?>">
							بررسی ارتباط با ترب
						</button>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
    }

    /**
     * Render the order tracking toggle row.
     */
    private function render_order_tracking_row(): void
    {
        $option_name = Options::ORDERS_LIST_API_ENABLED_OPTION;
        $enabled = Options::isOrdersListApiEnabled();
        ?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr($option_name); ?>">همگام‌سازی سفارش‌های ترب</label>
			</th>
			<td>
				<label class="torob-toggle-switch">
					<input type="checkbox"
					       id="<?php echo esc_attr($option_name); ?>"
					       name="<?php echo esc_attr($option_name); ?>"
					       value="1"
						<?php checked($enabled); ?> />
					<span class="torob-slider"></span>
				</label>
				<p class="description">
					اطلاعات سفارش‌هایی که منشأ خرید آن‌ها ترب بوده‌است همگام‌سازی شده و در پنل ترب قابل مشاهده خواهند بود.
					<br>
					برای مطالعه توضیحات کامل، به <a href="https://panel.torob.com/s/orders" target="_blank" rel="noopener noreferrer">راهنمای سفارش‌ها در پنل ترب</a> مراجعه کنید.
				</p>
			</td>
		</tr>
		<?php
    }

    /**
     * Render a compact preview of products waiting in the webhook queue.
     */
    private function render_pending_webhook_queue(): void
    {
        $total_pending = $this->product_page_webhook_handler->get_pending_queue_product_count();
        $total_pages = max(1, (int) ceil($total_pending / self::PENDING_WEBHOOK_PAGINATION_PAGE_SIZE));
        $current_page = min(max(1, absint($_GET['paged'] ?? 1)), $total_pages);
        $offset = ($current_page - 1) * self::PENDING_WEBHOOK_PAGINATION_PAGE_SIZE;

        $rows = $this->product_page_webhook_handler->get_pending_queue_products(
            self::PENDING_WEBHOOK_PAGINATION_PAGE_SIZE,
            $offset
        );
        ?>
		<div class="torob-admin-panel">
			<h2>محصولات در صف ارسال از طریق وب‌هوک</h2>
			<p class="description">
				<?php printf('در حال حاضر %d محصول در صف ارسال قرار دارد.', $total_pending); ?>
			</p>

			<?php if (!empty($rows)): ?>
				<table class="widefat striped torob-queue-table">
					<thead>
						<tr>
							<th scope="col">محصول</th>
							<th scope="col">شناسه</th>
							<th scope="col">زمان ثبت در صف</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $row): ?>
							<?php

							$product_title = !empty($row->post_title) ? $row->post_title : 'محصول حذف‌شده یا در دسترس نیست';
							$edit_link = get_edit_post_link((int) $row->product_id);
							?>
							<tr>
								<td>
									<?php if (!empty($edit_link)): ?>
										<a href="<?php echo esc_url($edit_link); ?>">
											<?php echo esc_html($product_title); ?>
										</a>
									<?php else: ?>
										<?php echo esc_html($product_title); ?>
									<?php endif; ?>
								</td>
									<td><?php echo esc_html((string) $row->product_id); ?></td>
								<td><?php echo esc_html(get_date_from_gmt($row->date_modified, 'Y-m-d H:i:s')); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php $this->render_pending_webhook_pagination($current_page, $total_pages); ?>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Render pagination for the pending webhook queue table.
     */
    private function render_pending_webhook_pagination(int $current_page, int $total_pages): void
    {
        if ($total_pages <= 1) {
            return;
        }

        $pagination = paginate_links([
            'base' => add_query_arg([
                'page' => self::MENU_SLUG,
                'paged' => '%#%'
            ], admin_url('admin.php')),
            'format' => '',
            'current' => $current_page,
            'total' => $total_pages,
            'prev_text' => 'قبلی',
            'next_text' => 'بعدی',
            'type' => 'list'
        ]);

        if (empty($pagination)) {
            return;
        }
        ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php echo wp_kses_post($pagination); ?>
			</div>
		</div>
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
			.torob-admin-panel {
				margin-top: 24px;
				background: #fff;
				border: 1px solid #dcdcde;
				padding: 16px 20px;
			}

			.torob-admin-panel h2 {
				margin-top: 0;
				margin-bottom: 8px;
			}

			.torob-form-table {
				margin-top: 0;
			}

			.torob-products-preview-actions {
				margin: 12px 0 0;
			}

			.torob-products-preview-page .torob-admin-panel {
				max-width: 1360px;
			}

			.torob-products-preview-page .description {
				max-width: 840px;
				line-height: 1.8;
			}

			.torob-products-preview-data {
				margin-top: 18px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				overflow: hidden;
				box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
			}

			.torob-products-preview-data-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				padding: 10px 14px;
				background: #f6f7f7;
				border-bottom: 1px solid #dcdcde;
				color: #1d2327;
			}

			.torob-products-preview-data-header span {
				color: #646970;
				font-family: Consolas, Monaco, monospace;
				font-size: 12px;
			}

			.torob-products-preview-data pre {
				max-height: 520px;
				margin: 0;
				padding: 16px;
				overflow: auto;
				background: #fbfbfc;
				color: #1d2327;
				font-size: 12px;
				line-height: 1.6;
				white-space: pre-wrap;
				overflow-wrap: anywhere;
				text-align: left;
			}

			.torob-products-preview-data code {
				font-family: Consolas, Monaco, monospace;
			}

			.torob-actions {
				margin-top: 16px;
			}

			.torob-admin-panel .tablenav {
				margin-top: 12px;
			}

			.torob-admin-panel .tablenav-pages {
				float: none;
				margin: 0;
			}

			.torob-admin-panel .tablenav-pages .page-numbers {
				margin: 0;
			}

			.torob-queue-table td,
			.torob-queue-table th {
				vertical-align: middle;
			}

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

			.torob-webhook-label {
				display: inline-flex;
				align-items: center;
				gap: 8px;
			}

			.torob-experimental-badge {
				display: inline-flex;
				align-items: center;
				padding: 3px 10px;
				border-radius: 999px;
				border: 1px solid #b8d3ea;
				background: #f0f6fc;
				box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
				color: #135e96;
				font-size: 10px;
				font-weight: 600;
				line-height: 1.6;
				vertical-align: middle;
				white-space: nowrap;
			}

			.torob-webhook-status {
				max-width: 680px;
				margin-top: 12px;
				padding: 12px 14px;
				border-right: 4px solid;
				border-radius: 3px;
			}

			.torob-webhook-status p {
				margin: 6px 0 0;
			}

			.torob-webhook-status-pending {
				color: #713f12;
				background: #fffbeb;
				border-color: #f59e0b;
			}

			.torob-connectivity-check {
				margin-top: 12px;
			}

			.torob-connectivity-check .description {
				margin-top: 8px;
			}

			.torob-connectivity-details {
				margin: 8px 0;
			}

			.torob-connectivity-details li {
				margin-bottom: 4px;
			}
		';
    }
}
