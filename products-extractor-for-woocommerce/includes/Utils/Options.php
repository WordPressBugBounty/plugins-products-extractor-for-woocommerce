<?php

declare(strict_types=1);

namespace Torob\Utils;

use Automattic\WooCommerce\Utilities\OrderUtil;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Centralized access to plugin-related WordPress options.
 */
final class Options
{
    private const ORDER_UTIL_CLASS = 'Automattic\WooCommerce\Utilities\OrderUtil';
    public const ORDER_STATUS_ENABLED_OPTION = 'torob_order_status_enabled';
    public const ORDERS_LIST_API_ENABLED_OPTION = 'torob_orders_list_api_enabled';
    public const PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION = 'torob_product_page_webhook_enabled';
    public const TOKEN_OPTION = 'torob_token';
    public const TOKEN_SET_AT_OPTION = 'torob_token_set_at';
    public const PLUGIN_DB_VERSION_OPTION = 'torob_plugin_db_version';
    public const PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION = 'torob_product_webhook_processing_lock';
    public const PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION = 'torob_product_webhook_last_runner_started_at';
    public const PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_COMPLETED_AT_OPTION = 'torob_product_webhook_last_runner_completed_at';
    public const PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION = 'torob_product_webhook_runner_lock';
    public const REPORTED_VERSION_OPTION = 'torob_plugin_reported_version';
    private const ENABLED_VALUE = '1';
    private const DISABLED_VALUE = '0';

    /**
     * Prevent instantiation of a static utility.
     */
    private function __construct() {}

    /**
     * Check whether WooCommerce is active on the site.
     */
    public static function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce', false);
    }

    /**
     * Check whether WooCommerce high-performance order storage is enabled.
     */
    public static function isHposEnabled(): bool
    {
        if (!class_exists(self::ORDER_UTIL_CLASS)) {
            return false;
        }

        if (!method_exists(self::ORDER_UTIL_CLASS, 'custom_orders_table_usage_is_enabled')) {
            return false;
        }

        return OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Check whether the order status endpoint is enabled.
     */
    public static function isOrderStatusEnabled(): bool
    {
        return get_option(self::ORDER_STATUS_ENABLED_OPTION, self::ENABLED_VALUE) === self::ENABLED_VALUE;
    }

    /**
     * Persist the order status endpoint setting.
     */
    public static function setOrderStatusEnabled(bool $enabled): bool
    {
        return update_option(self::ORDER_STATUS_ENABLED_OPTION, self::normalize_bool($enabled), true);
    }

    /**
     * Delete the stored order status setting so the default applies.
     */
    public static function resetOrderStatusEnabled(): bool
    {
        return delete_option(self::ORDER_STATUS_ENABLED_OPTION);
    }

    /**
     * Check whether the orders list API is enabled.
     */
    public static function isOrdersListApiEnabled(): bool
    {
        return get_option(self::ORDERS_LIST_API_ENABLED_OPTION, self::ENABLED_VALUE) === self::ENABLED_VALUE;
    }

    /**
     * Persist the orders list API setting.
     */
    public static function setOrdersListApiEnabled(bool $enabled): bool
    {
        return update_option(self::ORDERS_LIST_API_ENABLED_OPTION, self::normalize_bool($enabled), true);
    }

    /**
     * Delete the stored orders list API setting so the default applies.
     */
    public static function resetOrdersListApiEnabled(): bool
    {
        return delete_option(self::ORDERS_LIST_API_ENABLED_OPTION);
    }

    /**
     * Check whether the product page webhook feature is enabled.
     */
    public static function isProductPageWebhookEnabled(): bool
    {
        return get_option(self::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION, self::ENABLED_VALUE) === self::ENABLED_VALUE;
    }

    public static function isProductPageWebhookReady(): bool
    {
        return self::isProductPageWebhookEnabled() && self::getToken() !== '';
    }

    /**
     * Persist the product page webhook enabled flag.
     */
    public static function setProductPageWebhookEnabled(bool $enabled): bool
    {
        return update_option(self::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION, self::normalize_bool($enabled), true);
    }

    /**
     * Delete the stored product page webhook enabled flag so the default applies.
     */
    public static function resetProductPageWebhookEnabled(): bool
    {
        return delete_option(self::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION);
    }

    /**
     * Get the stored product page webhook token.
     */
    public static function getToken(): string
    {
        return (string) get_option(self::TOKEN_OPTION, '');
    }

    /**
     * Persist the product page webhook token and record when it was stored.
     */
    public static function setToken(string $token): bool
    {
        $is_token_updated = update_option(self::TOKEN_OPTION, $token, false);
        $is_timestamp_updated = update_option(self::TOKEN_SET_AT_OPTION, gmdate(DATE_ATOM), false);

        return $is_token_updated || $is_timestamp_updated;
    }

    /**
     * Delete the stored product page webhook token.
     */
    public static function resetToken(): bool
    {
        return delete_option(self::TOKEN_OPTION);
    }

    /**
     * Get the timestamp when the product page webhook token was last stored.
     */
    public static function getTokenSetAt(): ?string
    {
        $value = get_option(self::TOKEN_SET_AT_OPTION);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Delete the stored product page webhook token timestamp.
     */
    public static function resetTokenSetAt(): bool
    {
        return delete_option(self::TOKEN_SET_AT_OPTION);
    }

    /**
     * Get the installed product page webhook queue schema version.
     */
    public static function getDbVersion(): int
    {
        return (int) get_option(self::PLUGIN_DB_VERSION_OPTION, 0);
    }

    /**
     * Persist the installed product page webhook queue schema version.
     */
    public static function setDbVersion(int $version): bool
    {
        return update_option(self::PLUGIN_DB_VERSION_OPTION, $version, true);
    }

    /**
     * Delete the stored product page webhook queue schema version.
     */
    public static function resetDbVersion(): bool
    {
        return delete_option(self::PLUGIN_DB_VERSION_OPTION);
    }

    /**
     * Ensure the autoloaded queue-run timestamp exists.
     *
     * A zero value means the runner has not started yet and should run on the next request.
     */
    public static function initializeProductPageWebhookRunner(): bool
    {
        return add_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION, '0', '', true);
    }

    /**
     * Get the last time the product webhook queue runner started.
     */
    public static function getProductPageWebhookRunnerLastStartedAt(): ?int
    {
        return self::getPositiveTimestamp(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION);
    }

    /**
     * Record when the product webhook queue runner started.
     */
    public static function setProductPageWebhookRunnerLastStartedAt(int $timestamp): bool
    {
        return (
            update_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION, (string) $timestamp, true)
            || self::getProductPageWebhookRunnerLastStartedAt() === $timestamp
        );
    }

    /**
     * Get the last time the product webhook queue runner completed.
     */
    public static function getProductPageWebhookRunnerLastCompletedAt(): ?int
    {
        return self::getPositiveTimestamp(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_COMPLETED_AT_OPTION);
    }

    /**
     * Record when the product webhook queue runner completed.
     */
    public static function setProductPageWebhookRunnerLastCompletedAt(int $timestamp): bool
    {
        return (
            update_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_COMPLETED_AT_OPTION, (string) $timestamp, false)
            || self::getProductPageWebhookRunnerLastCompletedAt() === $timestamp
        );
    }

    /**
     * Atomically replace the runner lock only when its stored value has not changed.
     *
     * @param mixed $expected_lock
     * @param array<string, mixed> $replacement_lock
     */
    public static function replaceProductPageWebhookRunnerLock($expected_lock, array $replacement_lock): bool
    {
        return self::replaceLockOption(
            self::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION,
            $expected_lock,
            $replacement_lock
        );
    }

    /**
     * Atomically replace the processing lock only when its stored value has not changed.
     *
     * @param mixed $expected_lock
     * @param array{token: string, expires_at: int} $replacement_lock
     */
    public static function replaceProductPageWebhookProcessingLock($expected_lock, array $replacement_lock): bool
    {
        return self::replaceLockOption(
            self::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION,
            $expected_lock,
            $replacement_lock
        );
    }

    /**
     * Delete the processing lock only while its stored value still belongs to the caller.
     *
     * @param array{token: string, expires_at: int} $expected_lock
     */
    public static function deleteProductPageWebhookProcessingLock(array $expected_lock): bool
    {
        global $wpdb;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name = %s
               AND BINARY option_value = BINARY %s",
            self::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION,
            maybe_serialize($expected_lock)
        ));

        wp_cache_delete(self::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, 'options');

        return $deleted === 1;
    }

    /**
     * Delete the request-driven fallback lock without removing shared run timestamps.
     */
    public static function resetProductPageWebhookRunnerLock(): bool
    {
        return delete_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION);
    }

    /**
     * Delete all product webhook queue scheduling state.
     */
    public static function resetProductPageWebhookRunner(): void
    {
        delete_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION);
        delete_option(self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_COMPLETED_AT_OPTION);
        self::resetProductPageWebhookRunnerLock();
    }

    /**
     * Get the plugin version last reported to Torob via a lifecycle event.
     *
     * Empty string means nothing has been reported yet.
     */
    public static function getReportedVersion(): string
    {
        return (string) get_option(self::REPORTED_VERSION_OPTION, '');
    }

    /**
     * Persist the plugin version last reported to Torob via a lifecycle event.
     */
    public static function setReportedVersion(string $version): bool
    {
        return update_option(self::REPORTED_VERSION_OPTION, $version, true);
    }

    /**
     * Get all WordPress option names owned by this plugin.
     *
     * @return array The plugin option names.
     */
    public static function pluginOptionNames(): array
    {
        return [
            self::ORDER_STATUS_ENABLED_OPTION,
            self::ORDERS_LIST_API_ENABLED_OPTION,
            self::PRODUCT_PAGE_WEBHOOK_ENABLED_OPTION,
            self::TOKEN_OPTION,
            self::TOKEN_SET_AT_OPTION,
            self::PLUGIN_DB_VERSION_OPTION,
            self::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION,
            self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION,
            self::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_COMPLETED_AT_OPTION,
            self::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION,
            self::REPORTED_VERSION_OPTION
        ];
    }

    /**
     * Delete all WordPress options owned by this plugin.
     */
    public static function deleteAllPluginOptions(): void
    {
        foreach (self::pluginOptionNames() as $option_name) {
            delete_option($option_name);
        }
    }

    /**
     * Convert a boolean flag to the storage format expected by WordPress.
     */
    private static function normalize_bool(bool $enabled): string
    {
        return $enabled ? self::ENABLED_VALUE : self::DISABLED_VALUE;
    }

    /**
     * Read a positive Unix timestamp from an option.
     */
    private static function getPositiveTimestamp(string $option_name): ?int
    {
        $timestamp = (int) get_option($option_name, 0);

        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * Atomically replace a non-autoloaded lock option when its value is unchanged.
     *
     * @param mixed $expected_lock
     * @param array<string, mixed> $replacement_lock
     */
    private static function replaceLockOption(string $option_name, $expected_lock, array $replacement_lock): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s
               AND BINARY option_value = BINARY %s",
            maybe_serialize($replacement_lock),
            $option_name,
            maybe_serialize($expected_lock)
        ));

        wp_cache_delete($option_name, 'options');

        return $updated === 1;
    }
}
