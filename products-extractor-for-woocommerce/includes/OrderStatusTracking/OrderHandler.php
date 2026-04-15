<?php

declare(strict_types=1);

namespace Torob\OrderStatusTracking;

use Torob\Utils\TorobTokenValidator;
use WC_Order;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Class Order_Handler
 *
 * Handles all order-related functionality extracted from WC_Products_Extractor
 */
class OrderHandler
{
    /**
     * Option name for order status endpoint setting
     */
    const OPTION_ORDER_STATUS_ENABLED = 'torob_order_status_enabled';

    /**
     * Default value for order status enabled option (enabled by default)
     */
    const OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE = '1';

    /**
     * Value for order status enabled option when disabled
     */
    const OPTION_ORDER_STATUS_ENABLED_FALSE_VALUE = '0';

    /**
     * Meta field keys for order tracking information
     */
    const META_TRACKING_CODE = '_torob_tracking_code';
    const META_CARRIER = '_torob_carrier';
    const META_SHIPPING_DATE = '_torob_shipping_date';
    const META_TRACKING_URL = '_torob_tracking_url';
    const META_PROCESSING_STAGE = '_torob_processing_stage';
    const META_ESTIMATED_SHIPPING_DATE = '_torob_estimated_shipping_date';
    const META_PAYMENT_DEADLINE = '_torob_payment_deadline';
    const META_REVIEW_STAGE = '_torob_review_stage';
    const META_CANCEL_REASON = '_torob_cancel_reason';
    const META_PAYMENT_NOTE = '_torob_payment_note';
    const META_REFUND_STATUS = '_torob_refund_status';
    const META_STATUS_EXPLANATION = '_torob_status_explanation';
    const META_CUSTOM_EXPLANATION = '_torob_custom_explanation';

    /**
     * Constructor
     */
    public function __construct() {}

    /**
     * Register order status REST API route
     *
     * @return void
     */
    public function register_order_status_route(TorobTokenValidator $validator): void
    {
        if (!$this->is_order_status_enabled()) {
            return;
        }

        register_rest_route('torob-api/v1', '/order-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_order_status'],
            'permission_callback' => [$validator, 'validate_token']
        ]);
    }

    /**
     * Register order meta box hooks
     * Always register hooks; option check happens lazily in callbacks
     *
     * @return void
     */
    public function register_meta_box_hooks(): void
    {
        add_action('add_meta_boxes', [$this, 'add_torob_tracking_meta_box']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_torob_tracking_meta_box'], 10, 2);
    }

    /**
     * Get order status by customer phone number
     * Endpoint: GET /wp-json/torob-api/v1/order-status?customer_phone=09123456789
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_REST_Response
     */
    public function get_order_status(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->is_order_status_enabled()) {
            return new WP_REST_Response(['error' => 'Order status endpoint is disabled'], 403);
        }

        $phone_input = sanitize_text_field($request->get_param('customer_phone'));

        if (empty($phone_input)) {
            return new WP_REST_Response(['error' => 'customer_phone parameter is required'], 400);
        }

        // Validate phone number format without normalization
        $phone = $phone_input;

        // Basic validation: must be exactly 11 digits starting with 09
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return new WP_REST_Response([
                'error' => 'customer_phone must be a valid 11-digit Iranian mobile number starting with 09'
            ], 400);
        }

        $orders = $this->get_orders_by_phone($phone);

        $response_orders = [];
        foreach ($orders as $order) {
            $torob_status = $this->map_order_status($order);
            $explanation = $this->generate_order_explanation($order, $torob_status);

            $order_data = [
                'order_number' => (string) $order->get_order_number(),
                'order_date' => $order->get_date_created() ? $order->get_date_created()->format('c') : null,
                'order_status' => $torob_status,
                'customer_phone' => $phone,
                'total_amount' => (int) $order->get_total()
            ];

            $order_url = $order->get_view_order_url();
            if (!empty($order_url)) {
                $order_data['order_url'] = $order_url;
            }

            $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if (!empty($customer_name)) {
                $order_data['customer_name'] = $customer_name;
            }

            if (!empty($explanation)) {
                $order_data['explanation'] = $explanation;
            }

            $response_orders[] = $order_data;
        }

        return new WP_REST_Response([
            'api_version' => 'torob_order_status_api_v1',
            'total_orders' => count($response_orders),
            'orders' => $response_orders
        ], 200);
    }

    /**
     * Get orders by customer billing phone number
     *
     * Uses WooCommerce's wc_get_orders() API for HPOS.
     * Falls back to $wpdb for legacy CPT datastore (which lacks metadata filtering APIs).
     *
     * @param string $phone Customer phone number (normalized, 11 digits starting with 09).
     * @return array Array of WC_Order objects.
     */
    private function get_orders_by_phone(string $phone): array
    {
        $six_months_ago = wp_date('Y-m-d H:i:s', time() - (6 * MONTH_IN_SECONDS) - 1);
        $phone_pattern = substr($phone, 1);

        $hpos_enabled =
            class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos_enabled) {
            $args = [
                'limit' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
                'type' => 'shop_order',
                'date_created' => '>=' . $six_months_ago,
                'field_query' => [
                    [
                        'field' => 'billing_phone',
                        'value' => $phone_pattern,
                        'compare' => 'LIKE'
                    ]
                ],
                'return' => 'objects'
            ];

            $orders = wc_get_orders($args);

            $filtered_orders = [];
            foreach ($orders as $order) {
                if ($order->get_type() === 'shop_order_refund') {
                    continue;
                }

                $filtered_orders[] = $order;
            }

            return $filtered_orders;
        } else {
            global $wpdb;

            $posts_table = $wpdb->posts;
            $meta_table = $wpdb->postmeta;

            $query = $wpdb->prepare(
                "SELECT DISTINCT p.ID 
				FROM {$posts_table} AS p
				INNER JOIN {$meta_table} AS m ON p.ID = m.post_id
				WHERE m.meta_key = '_billing_phone'
				AND m.meta_value LIKE %s
				AND p.post_type = 'shop_order'
				AND p.post_date >= %s
				ORDER BY p.post_date DESC
				LIMIT 100",
                '%' . $wpdb->esc_like($phone_pattern) . '%',
                $six_months_ago
            );

            $order_ids = $wpdb->get_col($query);

            if (empty($order_ids)) {
                return [];
            }

            // Prime post and meta caches in bulk to avoid N+1 queries
            // The third parameter (true) enables meta cache priming
            _prime_post_caches($order_ids, false, true);

            $filtered_orders = [];
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_type() !== 'shop_order_refund') {
                    $filtered_orders[] = $order;
                }
            }

            return $filtered_orders;
        }
    }

    /**
     * Map WooCommerce order status to Torob status enum
     * Returns custom status string for non-standard WooCommerce statuses
     *
     * @param WC_Order $order Order object.
     * @return string Torob status enum or custom status string.
     */
    private function map_order_status(WC_Order $order): string
    {
        $wc_status = $order->get_status();
        $is_paid = $order->is_paid();

        // For cancelled/refunded orders, check if payment was actually made
        $cancelled_statuses = [
            OrderStatusEnum::CANCELLED,
            OrderStatusEnum::REFUNDED
        ];
        if (in_array($wc_status, $cancelled_statuses, true)) {
            $date_paid = $order->get_date_paid();
            $is_paid = $date_paid !== null;
        }

        switch ($wc_status) {
            case OrderStatusEnum::PENDING:
                return TorobStatusEnum::WAITING_FOR_USER_PAYMENT;

            case OrderStatusEnum::ON_HOLD:
                return TorobStatusEnum::WAITING_FOR_SHOP;

            case OrderStatusEnum::PROCESSING:
                return TorobStatusEnum::PROCESSING;

            case OrderStatusEnum::COMPLETED:
                return TorobStatusEnum::SHIPPED;

            case OrderStatusEnum::CANCELLED:
                return $is_paid ? TorobStatusEnum::CANCELED_PAID : TorobStatusEnum::CANCELED_UNPAID;

            case OrderStatusEnum::REFUNDED:
                return TorobStatusEnum::CANCELED_PAID;

            case OrderStatusEnum::FAILED:
                return TorobStatusEnum::CANCELED_UNPAID;

            default:
                // Return custom status as-is for non-standard WooCommerce statuses
                return $wc_status;
        }
    }

    /**
     * Generate explanation text for order status
     * Uses status-specific fields from meta box
     * Appends custom explanation if provided
     *
     * @param WC_Order $order Order object.
     * @param string $torob_status Torob status enum.
     * @return string Explanation text in Persian.
     */
    private function generate_order_explanation(WC_Order $order, string $torob_status): string
    {
        $text = '';

        switch ($torob_status) {
            case TorobStatusEnum::SHIPPED:
                $text = 'سفارش شما با موفقیت ارسال شده است.';
                $tracking_code = $order->get_meta(self::META_TRACKING_CODE);
                $carrier = $order->get_meta(self::META_CARRIER);
                $shipping_date = $order->get_meta(self::META_SHIPPING_DATE);
                $tracking_url = $order->get_meta(self::META_TRACKING_URL);

                if (!empty($tracking_code)) {
                    $text .= ' کد رهگیری: ' . $tracking_code;
                }
                if (!empty($carrier)) {
                    $text .= '، کالارسان: ' . $carrier;
                }
                if (!empty($shipping_date)) {
                    $text .= '. تاریخ ارسال: ' . $shipping_date;
                }
                if (!empty($tracking_url)) {
                    $text .= '. برای پیگیری مرسوله می‌توانید از لینک زیر استفاده کنید: ' . $tracking_url;
                }
                break;

            case TorobStatusEnum::PROCESSING:
                $text = 'سفارش شما در حال پردازش است.';
                $processing_stage = $order->get_meta(self::META_PROCESSING_STAGE);
                $estimated_shipping_date = $order->get_meta(self::META_ESTIMATED_SHIPPING_DATE);

                if (!empty($processing_stage)) {
                    $text .= ' وضعیت فعلی: ' . $processing_stage . '.';
                }
                if (!empty($estimated_shipping_date)) {
                    $text .= ' تاریخ تخمینی ارسال: ' . $estimated_shipping_date . '.';
                }
                break;

            case TorobStatusEnum::WAITING_FOR_USER_PAYMENT:
                $payment_deadline = $order->get_meta(self::META_PAYMENT_DEADLINE);
                if (!empty($payment_deadline)) {
                    $text = $payment_deadline;
                } else {
                    $text = 'در انتظار پرداخت شما. لطفا برای تکمیل سفارش، مبلغ را پرداخت کنید.';
                }
                break;

            case TorobStatusEnum::WAITING_FOR_SHOP:
                $review_stage = $order->get_meta(self::META_REVIEW_STAGE);
                if (!empty($review_stage)) {
                    $text = $review_stage;
                } else {
                    $text = 'سفارش شما در حال بررسی توسط فروشگاه است. پس از تایید، به مرحله پردازش می‌رود.';
                }
                break;

            case TorobStatusEnum::CANCELED_UNPAID:
                $cancel_reason = $order->get_meta(self::META_CANCEL_REASON);
                $payment_note = $order->get_meta(self::META_PAYMENT_NOTE);

                if (!empty($cancel_reason)) {
                    $text = $cancel_reason;
                }
                if (!empty($payment_note)) {
                    if (!empty($text)) {
                        $text .= ' ' . $payment_note;
                    } else {
                        $text = $payment_note;
                    }
                }
                if (empty($text)) {
                    $text = 'سفارش لغو شده است. پرداختی انجام نشده بود.';
                }
                break;

            case TorobStatusEnum::CANCELED_PAID:
                $cancel_reason = $order->get_meta(self::META_CANCEL_REASON);
                $refund_status = $order->get_meta(self::META_REFUND_STATUS);

                if (!empty($cancel_reason)) {
                    $text = $cancel_reason;
                }
                if (!empty($refund_status)) {
                    if (!empty($text)) {
                        $text .= ' وضعیت بازگشت وجه: ' . $refund_status;
                    } else {
                        $text = 'وضعیت بازگشت وجه: ' . $refund_status;
                    }
                }
                if (empty($text)) {
                    $text = 'سفارش لغو شده است. در صورت پرداخت، مبلغ به حساب شما برگردانده خواهد شد.';
                }
                break;

            default:
                $text = 'وضعیت سفارش در حال بررسی است.';
        }

        // Append status-specific explanation if provided (for default status group)
        $status_explanation = $order->get_meta(self::META_STATUS_EXPLANATION);
        if (!empty($status_explanation)) {
            $text .= ' ' . $status_explanation;
        }

        // Append custom/extra explanation if provided
        $custom = $order->get_meta(self::META_CUSTOM_EXPLANATION);
        if (!empty($custom)) {
            $text .= ' ' . $custom;
        }

        return $text;
    }

    /**
     * Add meta box to order edit screen
     * Appears in sidebar next to WooCommerce's native meta boxes
     * Only adds meta box if order status feature is enabled
     */
    public function add_torob_tracking_meta_box(): void
    {
        // Lazy check: only check option value when actually on order edit page
        if (!$this->is_order_status_enabled()) {
            return;
        }

        add_meta_box(
            'torob_tracking_info',
            'اطلاعات ارسال (ترب)',
            [$this, 'render_torob_tracking_meta_box'],
            'shop_order',
            'side',
            'default'
        );

        add_meta_box(
            'torob_tracking_info',
            'اطلاعات ارسال (ترب)',
            [$this, 'render_torob_tracking_meta_box'],
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }

    /**
     * Render meta box content using WooCommerce native styling
     * Shows different fields based on order status
     */
    public function render_torob_tracking_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;

        if (!$order) {
            return;
        }

        wp_nonce_field('torob_tracking_save', 'torob_tracking_nonce');

        $wc_status = $order->get_status();
        $is_paid = $order->is_paid();
        $has_tracking = !empty($order->get_meta(self::META_TRACKING_CODE));

        // Determine which status group UI to show based on order status
        $status_group = 'default';
        if ($wc_status === OrderStatusEnum::COMPLETED) {
            $status_group = TorobStatusEnum::SHIPPED;
        } elseif ($wc_status === OrderStatusEnum::PROCESSING) {
            $status_group = TorobStatusEnum::PROCESSING;
        } elseif ($wc_status === OrderStatusEnum::PENDING) {
            $status_group = TorobStatusEnum::WAITING_FOR_USER_PAYMENT;
        } elseif ($wc_status === OrderStatusEnum::ON_HOLD) {
            $status_group = TorobStatusEnum::WAITING_FOR_SHOP;
        } elseif ($wc_status === OrderStatusEnum::CANCELLED && !$is_paid) {
            $status_group = TorobStatusEnum::CANCELED_UNPAID;
        } elseif ($wc_status === OrderStatusEnum::CANCELLED && $is_paid) {
            $status_group = TorobStatusEnum::CANCELED_PAID;
        } elseif ($wc_status === OrderStatusEnum::REFUNDED) {
            $status_group = TorobStatusEnum::CANCELED_PAID;
        }

        $tracking_code = $order->get_meta(self::META_TRACKING_CODE);
        $carrier = $order->get_meta(self::META_CARRIER);
        $shipping_date = $order->get_meta(self::META_SHIPPING_DATE);
        $tracking_url = $order->get_meta(self::META_TRACKING_URL);
        $processing_stage = $order->get_meta(self::META_PROCESSING_STAGE);
        $estimated_shipping_date = $order->get_meta(self::META_ESTIMATED_SHIPPING_DATE);
        $payment_deadline = $order->get_meta(self::META_PAYMENT_DEADLINE);
        $review_stage = $order->get_meta(self::META_REVIEW_STAGE);
        $cancel_reason = $order->get_meta(self::META_CANCEL_REASON);
        $refund_status = $order->get_meta(self::META_REFUND_STATUS);
        $status_explanation = $order->get_meta(self::META_STATUS_EXPLANATION);
        $custom_explanation = $order->get_meta(self::META_CUSTOM_EXPLANATION);

        ?>
		<div class="order_data_column" style="padding: 0;">
			<?php if ($status_group === TorobStatusEnum::SHIPPED): ?>
				<p class="form-field form-field-wide">
					<label for="torob_tracking_code">کد رهگیری:</label>
					<input type="text"
						   id="torob_tracking_code"
						   name="torob_tracking_code"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($tracking_code); ?>"
						   placeholder="مثلا: 12345678901234" />
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_carrier">نام کالارسان:</label>
					<input type="text"
						   id="torob_carrier"
						   name="torob_carrier"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($carrier); ?>"
						   placeholder="مثلا: پست پیشتاز" />
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_shipping_date">تاریخ ارسال:</label>
					<input type="text"
						   id="torob_shipping_date"
						   name="torob_shipping_date"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($shipping_date); ?>"
						   placeholder="مثلا: ۲۶ آبان ۱۴۰۳" />
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_tracking_url">لینک پیگیری (در صورت امکان):</label>
					<input type="text"
						   id="torob_tracking_url"
						   name="torob_tracking_url"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($tracking_url); ?>"
						   placeholder="مثلا: https://tracking.post.ir/?id=12345678901234" />
				</p>

			<?php elseif ($status_group === TorobStatusEnum::PROCESSING): ?>
				<p class="form-field form-field-wide">
					<label for="torob_processing_stage">مرحله فعلی پردازش:</label>
					<input type="text"
						   id="torob_processing_stage"
						   name="torob_processing_stage"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($processing_stage); ?>"
						   placeholder="مثلا: در حال بسته‌بندی" />
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_estimated_shipping_date">تاریخ تخمینی ارسال:</label>
					<input type="text"
						   id="torob_estimated_shipping_date"
						   name="torob_estimated_shipping_date"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($estimated_shipping_date); ?>"
						   placeholder="مثلا: ۵ بهمن ۱۴۰۳" />
				</p>

			<?php elseif ($status_group === TorobStatusEnum::WAITING_FOR_USER_PAYMENT): ?>
				<p class="form-field form-field-wide">
					<label for="torob_payment_deadline">اعلام عدم پرداخت / مهلت یا راهنمای پرداخت:</label>
					<textarea id="torob_payment_deadline"
							  name="torob_payment_deadline"
							  rows="3"
							  style="width: 100%;"
							  placeholder="مثلا: لطفا تا تاریخ ۳۰ دی ۱۴۰۳ مبلغ سفارش را پرداخت کنید. برای پرداخت می‌توانید از طریق لینک زیر اقدام کنید"><?php

							  echo esc_textarea($payment_deadline);
							  ?></textarea>
				</p>

			<?php elseif ($status_group === TorobStatusEnum::WAITING_FOR_SHOP): ?>
				<p class="form-field form-field-wide">
					<label for="torob_review_stage">اعلام در انتظار تایید فروشگاه بودن سفارش / توضیح کوتاه درباره مرحله بررسی:</label>
					<textarea id="torob_review_stage"
							  name="torob_review_stage"
							  rows="3"
							  style="width: 100%;"
							  placeholder="مثلا: سفارش شما در حال بررسی موجودی محصول و تایید نهایی است. پس از تایید، به مرحله پردازش می‌رود."><?php

							  echo esc_textarea($review_stage);
							  ?></textarea>
				</p>

			<?php elseif ($status_group === TorobStatusEnum::CANCELED_UNPAID): ?>
				<p class="form-field form-field-wide">
					<label for="torob_cancel_reason">دلیل لغو:</label>
					<textarea id="torob_cancel_reason"
							  name="torob_cancel_reason"
							  rows="2"
							  style="width: 100%;"
							  placeholder="مثلا: سفارش به دلیل عدم پرداخت در مهلت مقرر لغو شد."><?php

							  echo esc_textarea($cancel_reason);
							  ?></textarea>
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_payment_note">ذکر عدم انجام پرداخت:</label>
					<input type="text"
						   id="torob_payment_note"
						   name="torob_payment_note"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($order->get_meta('_torob_payment_note')); ?>"
						   placeholder="مثلا: پرداختی انجام نشده بود" />
				</p>

			<?php elseif ($status_group === TorobStatusEnum::CANCELED_PAID): ?>
				<p class="form-field form-field-wide">
					<label for="torob_cancel_reason">دلیل لغو:</label>
					<textarea id="torob_cancel_reason"
							  name="torob_cancel_reason"
							  rows="2"
							  style="width: 100%;"
							  placeholder="مثلا: سفارش به درخواست مشتری لغو شد."><?php

							  echo esc_textarea($cancel_reason);
							  ?></textarea>
				</p>

				<p class="form-field form-field-wide">
					<label for="torob_refund_status">وضعیت بازگشت وجه:</label>
					<input type="text"
						   id="torob_refund_status"
						   name="torob_refund_status"
						   class="short"
						   style="width: 100%;"
						   value="<?php echo esc_attr($refund_status); ?>"
						   placeholder="مثلا: بازگشت وجه در حال انجام است" />
				</p>

			<?php else: ?>
				<p class="form-field form-field-wide">
					<label for="torob_status_explanation">توضیحات:</label>
					<textarea id="torob_status_explanation"
							  name="torob_status_explanation"
							  rows="4"
							  style="width: 100%;"
							  placeholder="توضیحات شما برای این سفارش"><?php

							  echo esc_textarea($status_explanation);
							  ?></textarea>
				</p>
			<?php endif; ?>

			<p class="form-field form-field-wide" style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">
				<label for="torob_custom_explanation">توضیحات اضافی اختیاری:</label>
				<textarea id="torob_custom_explanation"
						  name="torob_custom_explanation"
						  rows="4"
						  style="width: 100%;"
						  placeholder="مثلا: در صورت هرگونه سوال با پشتیبانی تماس بگیرید."><?php

						  echo esc_textarea($custom_explanation);
						  ?></textarea>
			</p>
		</div>
		<?php
    }

    /**
     * Save meta box data when order is updated
     * All fields saved as unrestricted strings
     *
     * @param int $post_id Order post ID.
     * @param WP_Post $post Post object.
     */
    public function save_torob_tracking_meta_box(int $post_id, $post): void
    {
        // Lazy check: only process save if feature is enabled
        if (!$this->is_order_status_enabled()) {
            return;
        }

        if (
            !array_key_exists('torob_tracking_nonce', $_POST)
            || !wp_verify_nonce($_POST['torob_tracking_nonce'], 'torob_tracking_save')
        ) {
            return;
        }

        if (!current_user_can('edit_shop_order', $post_id)) {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $fields = [
            'torob_tracking_code' => self::META_TRACKING_CODE,
            'torob_carrier' => self::META_CARRIER,
            'torob_shipping_date' => self::META_SHIPPING_DATE,
            'torob_tracking_url' => self::META_TRACKING_URL,
            'torob_processing_stage' => self::META_PROCESSING_STAGE,
            'torob_estimated_shipping_date' => self::META_ESTIMATED_SHIPPING_DATE,
            'torob_payment_deadline' => self::META_PAYMENT_DEADLINE,
            'torob_review_stage' => self::META_REVIEW_STAGE,
            'torob_cancel_reason' => self::META_CANCEL_REASON,
            'torob_payment_note' => self::META_PAYMENT_NOTE,
            'torob_refund_status' => self::META_REFUND_STATUS,
            'torob_status_explanation' => self::META_STATUS_EXPLANATION,
            'torob_custom_explanation' => self::META_CUSTOM_EXPLANATION
        ];

        foreach ($fields as $post_key => $meta_key) {
            if (!array_key_exists($post_key, $_POST)) {
                continue;
            }

            $value = wp_unslash($_POST[$post_key]);
            // Sanitize URL field with esc_url_raw, text fields with sanitize_text_field
            if ($post_key === 'torob_tracking_url') {
                $value = esc_url_raw($value);
            } else {
                $value = sanitize_text_field($value);
            }
            $order->update_meta_data($meta_key, $value);
        }

        $order->save();
    }

    /**
     * Check if order status endpoint is enabled
     *
     * @return bool
     */
    private function is_order_status_enabled(): bool
    {
        return (
            get_option(self::OPTION_ORDER_STATUS_ENABLED, self::OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE)
            === self::OPTION_ORDER_STATUS_ENABLED_TRUE_VALUE
        );
    }
}
