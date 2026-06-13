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
}
