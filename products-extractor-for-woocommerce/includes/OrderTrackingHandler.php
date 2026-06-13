<?php

declare(strict_types=1);

namespace Torob;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

class OrderTrackingHandler
{
    const CARD_PSP = 'card';
    const TOROBPAY_PSP = 'torobpay';
    const TOROBPAY_PAYMENT_METHODS = ['WC_Gateway_TorobPay', 'torobpay'];
    const OFFLINE_PAYMENT_METHODS = ['cod', 'bacs', 'cheque'];

    /**
     * Cookie name for storing torob_clid.
     */
    const COOKIE_NAME = 'torob_clid';

    /**
     * Cookie expiration time (7 days in seconds).
     */
    const COOKIE_EXPIRATION = 7 * 24 * 60 * 60;
    const COOKIE_PATH = '/';

    const TOROB_CLID_MAX_LENGTH = 128;
    const TOROB_CLID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Maximum lookback window in days for purchase_timestamp_gt (security measure
     * to limit data exposure if Torob's public key is ever leaked).
     */
    const ORDER_TRACKING_MAX_LOOKBACK_DAYS = 45;

    /**
     * Constructor.
     */
    public function __construct() {}

    /**
     * Register WordPress hooks for cookie capture and order meta saving.
     */
    public function register_hooks(): void
    {
        // Capture torob_clid from URL and store in cookie
        add_action('init', [$this, 'capture_torob_clid'], 0);
        // Save torob_clid with order (classic checkout)
        add_action('woocommerce_checkout_create_order', [$this, 'save_torob_clid_to_order'], 10, 2);
        // Save torob_clid with order (block-based / Store API checkout)
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            [$this, 'save_torob_clid_to_order'],
            10,
            2
        );
    }

    /**
     * Register REST API route for orders listing.
     */
    public function register_orders_route(TorobTokenValidator $token_validator): void
    {
        register_rest_route('torob-api/v1', '/orders', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_orders'],
                'permission_callback' => [$token_validator, 'validate_token'],
                'args' => [
                    'purchase_timestamp_gt' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Returns orders with purchase_timestamp greater than this value (ISO 8601 UTC).',
                        'sanitize_callback' => 'sanitize_text_field'
                    ],
                    'limit' => [
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'Maximum number of records to return (1-1000).',
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn($param) => $param > 0 && $param <= 1000
                    ]
                ]
            ]
        ]);
    }

    /**
     * Capture torob_clid from URL query parameter and store in cookie.
     */
    public function capture_torob_clid(): void
    {
        if (!array_key_exists('torob_clid', $_GET) || !is_scalar($_GET['torob_clid'])) {
            return;
        }

        $torob_clid = wp_unslash((string) $_GET['torob_clid']);
        if ($torob_clid !== '') {
            $this->set_torob_clid_cookie($torob_clid);
        }
    }

    /**
     * Set the torob_clid cookie.
     */
    public function set_torob_clid_cookie(string $torob_clid): void
    {
        $torob_clid = $this->sanitize_torob_clid($torob_clid);
        if (empty($torob_clid)) {
            return;
        }

        if (!headers_sent()) {
            $secure = is_ssl();
            $httponly = true;
            $samesite = 'Lax';

            setcookie(self::COOKIE_NAME, $torob_clid, [
                'expires' => time() + self::COOKIE_EXPIRATION,
                'path' => self::COOKIE_PATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite
            ]);
        }

        // Keep in-memory cookie state in sync even when headers are already sent
        // (e.g., during tests) so current request code can read the captured value.
        $_COOKIE[self::COOKIE_NAME] = $torob_clid;
    }

    /**
     * Get the torob_clid from cookie.
     */
    public function get_torob_clid_from_cookie()
    {
        if (!array_key_exists(self::COOKIE_NAME, $_COOKIE) || !is_scalar($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        return $this->sanitize_torob_clid(wp_unslash((string) $_COOKIE[self::COOKIE_NAME]));
    }

    /**
     * Save torob_clid to order metadata when order is created.
     *
     * @param WC_Order $order The order being created or updated.
     * @param mixed    $data  Optional checkout payload. Classic checkout passes an array;
     *                        Store API checkout passes a WP_REST_Request.
     */
    public function save_torob_clid_to_order(WC_Order $order, $data = null): void
    {
        $torob_clid = $this->get_torob_clid_from_cookie();
        if (!empty($torob_clid)) {
            $order->update_meta_data('_torob_clid', $torob_clid);
        }
    }

    /**
     * Get orders endpoint handler.
     */
    public function get_orders(WP_REST_Request $request): WP_REST_Response
    {
        // Check if the orders list API is enabled
        if (!Options::isOrdersListApiEnabled()) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Orders list API is disabled by the administrator.'
            ], 403);
        }

        $purchase_timestamp_gt = $request->get_param('purchase_timestamp_gt');
        $limit = min(absint($request->get_param('limit')), 1000);
        $timestamp = $this->parse_iso8601_timestamp($purchase_timestamp_gt);
        if (false === $timestamp) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Invalid timestamp format. Use ISO 8601 UTC format.'
            ], 400);
        }

        $timestamp = $this->apply_lookback_limit($timestamp);

        // Query orders with torob_clid
        $orders = $this->query_torob_orders($timestamp, $limit);

        return new WP_REST_Response([
            'success' => true,
            'data' => $orders
        ], 200);
    }

    /**
     * Clamp a Unix timestamp so it is no older than ORDER_TRACKING_MAX_LOOKBACK_DAYS.
     */
    public function apply_lookback_limit(int $timestamp): int
    {
        $min_allowed = time() - (self::ORDER_TRACKING_MAX_LOOKBACK_DAYS * DAY_IN_SECONDS);
        return max($timestamp, $min_allowed);
    }

    /**
     * Parse ISO 8601 timestamp string.
     *
     * @param string $timestamp ISO 8601 UTC timestamp (e.g. '2024-01-15T10:30:00Z' or '2024-01-15T10:30:00.123456Z').
     *
     * @return int|false Unix timestamp on success, or false if format is invalid.
     */
    public function parse_iso8601_timestamp(string $timestamp)
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return false;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', $timestamp)) {
            return false;
        }

        $format = strpos($timestamp, '.') !== false ? 'Y-m-d\TH:i:s.u\Z' : 'Y-m-d\TH:i:s\Z';
        $date = \DateTimeImmutable::createFromFormat($format, $timestamp, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable) {
            return false;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
            return false;
        }

        return $date->getTimestamp();
    }

    /**
     * Format Unix timestamp to ISO 8601 UTC string.
     *
     * @param int|string $timestamp Unix timestamp as int, or a date string parseable by strtotime().
     *
     * @return string ISO 8601 formatted timestamp (e.g. '2024-01-15T10:30:00.000000Z').
     */
    public function format_iso8601_timestamp($timestamp): string
    {
        if (is_string($timestamp) && !is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(new \DateTimeZone('UTC'));
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Query orders that have torob_clid metadata.
     *
     * Uses wc_get_orders() with meta_query when HPOS is enabled. When the CPT
     * order datastore is used (WooCommerce 9.2+), meta_query is not supported
     * so we query via WP (post meta) and then load WC_Order objects.
     *
     * @param int $timestamp_gt Unix timestamp; only orders created after this time are returned.
     * @param int $limit        Maximum number of formatted order records to return.
     *
     * @return array List of formatted order data arrays ready for API response.
     */
    public function query_torob_orders(int $timestamp_gt, int $limit): array
    {
        $date_query = gmdate('Y-m-d H:i:s', $timestamp_gt);
        $statuses = ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded'];
        $orders = $this->get_orders_with_torob_clid($date_query, $statuses, $limit, $timestamp_gt);
        $formatted_orders = array_map([$this, 'format_order_data'], $orders);
        $valid_orders = array_filter($formatted_orders, static fn($order_data) => $order_data !== null);
        return array_values($valid_orders);
    }

    /**
     * Get orders that have _torob_clid meta, compatible with both HPOS and CPT datastores.
     *
     * @param string $date_query     Formatted date string (Y-m-d H:i:s) used for CPT date_query.
     * @param array  $statuses       WooCommerce order statuses to filter by (e.g. 'wc-completed').
     * @param int    $limit          Maximum number of orders to fetch.
     * @param int    $timestamp_gt   Unix timestamp used for HPOS date_created filter.
     *
     * @return WC_Order[]
     */
    private function get_orders_with_torob_clid(
        string $date_query,
        array $statuses,
        int $limit,
        int $timestamp_gt = 0
    ): array {
        if (Options::isHposEnabled()) {
            $args = [
                'limit' => $limit,
                'orderby' => 'date ID',
                'order' => 'ASC',
                'date_created' => '>' . $timestamp_gt,
                'meta_query' => [
                    [
                        'key' => '_torob_clid',
                        'compare' => 'EXISTS'
                    ],
                    [
                        'key' => '_torob_clid',
                        'value' => '',
                        'compare' => '!='
                    ]
                ],
                'status' => $statuses
            ];
            $orders = wc_get_orders($args);
            if (!is_array($orders)) {
                return [];
            }
            return $orders;
        }

        // CPT datastore: meta_query is not supported in wc_get_orders (WC 9.2+). Use WP_Query on post meta.
        $query = new \WP_Query([
            'post_type' => 'shop_order',
            'post_status' => $statuses,
            'posts_per_page' => $limit,
            'orderby' => 'date ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'date_query' => [
                [
                    'column' => 'post_date_gmt',
                    'after' => $date_query,
                    'inclusive' => false
                ]
            ],
            'meta_query' => [
                [
                    'key' => '_torob_clid',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => '_torob_clid',
                    'value' => '',
                    'compare' => '!='
                ]
            ]
        ]);

        $order_ids = $query->posts;
        $orders = [];
        foreach ($order_ids as $id) {
            $order = wc_get_order($id);
            if ($order instanceof \WC_Order) {
                $orders[] = $order;
            }
        }
        return $orders;
    }

    /**
     * Format order data for API response.
     *
     * @param WC_Order $order The WooCommerce order to format.
     *
     * @return array|null Formatted order data array, or null if order has no torob_clid or no creation date.
     */
    public function format_order_data(WC_Order $order)
    {
        $torob_clid = $order->get_meta('_torob_clid');

        if (empty($torob_clid)) {
            return null;
        }

        $wc_status = $order->get_status();
        if (in_array($wc_status, ['cancelled', 'refunded'], true)) {
            $status = 'cancelled';
        } elseif ($wc_status === 'on-hold') {
            $status = 'on-hold';
        } else {
            $status = 'completed';
        }

        $woocommerce_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null;
        $order_value = 0;
        $products = [];
        foreach ($order->get_items() as $item) {
            $item_total = (float) $item->get_total();
            $order_value += $this->normalize_price_for_torob($item_total, $woocommerce_currency);
            $product = $item->get_product();
            if ($product) {
                $product_url = $this->normalize_product_url(get_permalink($product->get_id()));
                $products[] = [
                    'product_url' => $product_url,
                    'product_price' => $this->normalize_price_for_torob(
                        $item_total / max(1, $item->get_quantity()),
                        $woocommerce_currency
                    ),
                    'quantity' => $item->get_quantity()
                ];
            }
        }

        $phone_number = $order->get_billing_phone();
        if (!empty($phone_number)) {
            $phone_number = $this->normalize_phone_number($phone_number);
        }

        $date_created = $order->get_date_created();
        $date_modified = $order->get_date_modified();
        $date_for_last = $date_created;
        if ($date_modified instanceof \WC_DateTime && $date_modified->getTimestamp() >= $date_created->getTimestamp()) {
            $date_for_last = $date_modified;
        }
        return [
            'purchase_timestamp' => $this->format_iso8601_timestamp($date_created->getTimestamp()),
            'torob_clid' => $torob_clid,
            'psp' => $this->get_order_psp($order),
            'order_value' => $order_value,
            'shipping_amount' => $this->normalize_price_for_torob(
                (float) $order->get_shipping_total(),
                $woocommerce_currency
            ),
            'status' => $status,
            'last_updated_timestamp' => $date_for_last
                ? $this->format_iso8601_timestamp($date_for_last->getTimestamp())
                : null,
            'phone_number' => $phone_number,
            'products' => $products
        ];
    }

    /**
     * Normalize WooCommerce price values to the Toman amounts expected by Torob.
     */
    private function normalize_price_for_torob(float $amount, ?string $woocommerce_currency): int
    {
        if ($woocommerce_currency === 'IRR') {
            $amount /= 10;
        }

        return (int) round($amount);
    }

    /**
     * Normalize a product permalink to an absolute URL.
     *
     * Some shops or plugins filter permalinks into site-relative paths.
     * The orders API should always return an absolute product URL.
     *
     * @param string|false $product_url Raw permalink returned by WordPress.
     */
    private function normalize_product_url($product_url): string
    {
        if (!is_string($product_url) || $product_url === '') {
            return home_url('/');
        }

        $parsed_url = wp_parse_url($product_url);
        if (
            is_array($parsed_url)
            && (($parsed_url['scheme'] ?? null) !== null || ($parsed_url['host'] ?? null) !== null)
        ) {
            return $product_url;
        }

        return home_url($product_url);
    }

    /**
     * Normalize phone number to standard Iranian mobile format (09XXXXXXXXX).
     *
     * @param string $phone Raw phone number (may include country code, leading zeros, or non-digit chars).
     *
     * @return string|null Normalized 11-digit phone number starting with '09', or null if not a valid Iranian mobile.
     */
    public function normalize_phone_number(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);
        if (strpos($phone, '989') === 0) {
            $phone = substr($phone, 2);
        } elseif (strpos($phone, '00989') === 0) {
            $phone = substr($phone, 4);
        }
        if (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return null;
        }
        return $phone;
    }

    /**
     * Resolve the order payment service provider for Torob tracking.
     */
    private function get_order_psp(WC_Order $order): string
    {
        $payment_method = $order->get_payment_method();
        if ($payment_method === '') {
            return '';
        }

        if (in_array($payment_method, self::TOROBPAY_PAYMENT_METHODS, true)) {
            return self::TOROBPAY_PSP;
        }

        if (in_array($payment_method, self::OFFLINE_PAYMENT_METHODS, true)) {
            return self::CARD_PSP;
        }

        return $payment_method;
    }

    /**
     * Sanitize and validate torob_clid input.
     */
    private function sanitize_torob_clid($torob_clid): ?string
    {
        if (!is_scalar($torob_clid)) {
            return null;
        }

        $torob_clid = sanitize_text_field((string) $torob_clid);
        $torob_clid = trim($torob_clid);
        if ($torob_clid === '') {
            return null;
        }

        if (strlen($torob_clid) > self::TOROB_CLID_MAX_LENGTH) {
            return null;
        }

        if (!preg_match(self::TOROB_CLID_PATTERN, $torob_clid)) {
            return null;
        }

        return $torob_clid;
    }
}
