<?php

declare(strict_types=1);

namespace Torob\Utils;

use Torob\Admin\TorobConnectivityCheckResult;
use Torob\ProductWebhook\WebhookItem;
use Torob\ProductWebhook\WebhookSendResult;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Static HTTP client for outbound requests from this plugin to Torob.
 */
final class TorobHttpClient
{
    const TOKEN_VALIDATION_ENDPOINT = 'https://extractor.torob.com/validate_token/';
    const PRODUCT_PAGE_WEBHOOK_ENDPOINT = 'https://api.torob.com/update/webhook/v1/';
    const EXTRACTOR_HEALTH_CHECK_ENDPOINT = 'https://extractor.torob.com/health_check/';
    const BACKEND_HEALTH_CHECK_ENDPOINT = 'https://api.torob.com/update/health_check/';
    const LIFECYCLE_EVENT_ENDPOINT = 'https://extractor.torob.com/woocommerce_plugin/event/';

    private function __construct() {}

    /**
     * POST a plugin lifecycle event to Torob.
     *
     * The request is non-blocking with a short timeout and failures are swallowed.
     *
     * @param string $event          One of activated|updated|deactivated|uninstalled.
     * @param string $plugin_version The current plugin version.
     */
    public static function send_lifecycle_event(string $event, string $plugin_version): void
    {
        $body = [
            'site_url' => SiteData::url(),
            'event' => $event,
            'plugin_version' => $plugin_version,
            'wc_version' => SiteData::woocommerce_version(),
            'wp_version' => SiteData::wordpress_version()
        ];

        $response = wp_safe_remote_post(self::LIFECYCLE_EVENT_ENDPOINT, [
            'method' => 'POST',
            'timeout' => 2,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking' => false,
            'body' => wp_json_encode($body),
            'headers' => ['Content-Type' => 'application/json'],
            'cookies' => []
        ]);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[Torob Plugin] Lifecycle event "%s" dispatch failed: %s (%s)',
                $event,
                $response->get_error_message(),
                $response->get_error_code()
            ));
        }
    }

    /**
     * Send token validation data to Torob.
     *
     * @return array|\WP_Error
     */
    public static function validate_token(string $token, string $shop_domain, ?string $token_version)
    {
        $body = [
            'token' => $token,
            'shop_domain' => $shop_domain
        ];

        if (is_string($token_version) && $token_version !== '') {
            $body['token_version'] = sanitize_text_field($token_version);
        }

        return wp_safe_remote_post(self::TOKEN_VALIDATION_ENDPOINT, [
            'method' => 'POST',
            'timeout' => 12,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking' => true,
            'body' => $body,
            'cookies' => []
        ]);
    }

    public static function send_product_page_webhook_items(WebhookItem ...$items): WebhookSendResult
    {
        $token = Options::getToken();
        if ($token === '') {
            return new WebhookSendResult(false, 0);
        }

        $payload_items = [];
        foreach ($items as $item) {
            $payload_items[] = $item->to_array();
        }

        $payload = wp_json_encode(['items' => $payload_items]);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Torob Plugin] Product page webhook payload: %s', $payload));
        }

        $response = wp_safe_remote_post(self::PRODUCT_PAGE_WEBHOOK_ENDPOINT, [
            'body' => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[Torob Plugin] Product page webhook request failed: %s (%s)',
                $response->get_error_message(),
                $response->get_error_code()
            ));

            return new WebhookSendResult(false, 0);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            error_log(sprintf('[Torob Plugin] Product page webhook returned HTTP %d', $status_code));

            return new WebhookSendResult(false, $status_code);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Torob Plugin] Product page webhook fired successfully for %d item(s)',
                count($payload_items)
            ));
        }

        return new WebhookSendResult(true, $status_code);
    }

    public static function check_extractor_health(): TorobConnectivityCheckResult
    {
        return self::check_health_endpoint(self::EXTRACTOR_HEALTH_CHECK_ENDPOINT, 'Extractor');
    }

    public static function check_backend_health(): TorobConnectivityCheckResult
    {
        return self::check_health_endpoint(self::BACKEND_HEALTH_CHECK_ENDPOINT, 'Backend');
    }

    private static function check_health_endpoint(string $endpoint, string $service_label): TorobConnectivityCheckResult
    {
        $start_time = microtime(true);
        $response = wp_safe_remote_get($endpoint, [
            'timeout' => 12,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking' => true,
            'cookies' => []
        ]);
        $request_time_seconds = max(0.0, microtime(true) - $start_time);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[Torob Plugin] %s health check connection failed: %s (%s)',
                $service_label,
                $response->get_error_message(),
                $response->get_error_code()
            ));

            return TorobConnectivityCheckResult::network_failure(
                $request_time_seconds,
                $response->get_error_code(),
                $response->get_error_message()
            );
        }

        $http_status_code = (int) wp_remote_retrieve_response_code($response);
        if ($http_status_code !== 200) {
            error_log(sprintf('[Torob Plugin] %s health check returned HTTP %d', $service_label, $http_status_code));

            return TorobConnectivityCheckResult::http_failure($request_time_seconds, $http_status_code);
        }

        return TorobConnectivityCheckResult::success($request_time_seconds, $http_status_code);
    }
}
