<?php

declare(strict_types=1);

namespace Torob\Utils;

use ParagonIE_Sodium_Compat;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Read-only accessors for the site's domain and environment versions.
 */
final class SiteData
{
    /**
     * Prevent instantiation of a static utility.
     */
    private function __construct() {}

    /**
     * The full site URL, as configured in WordPress.
     */
    public static function url(): string
    {
        return get_site_url();
    }

    /**
     * The site host with a leading `www.` stripped and a non-standard port appended.
     */
    public static function domain(): string
    {
        $site_url = wp_parse_url(get_site_url());
        $host = $site_url['host'] ?? '';
        $host = str_replace('www.', '', $host);

        if (($site_url['port'] ?? null) !== null) {
            $host .= ':' . $site_url['port'];
        }

        return $host;
    }

    /**
     * The WordPress core version.
     */
    public static function wordpress_version(): string
    {
        return get_bloginfo('version');
    }

    /**
     * The active WooCommerce version, or null when it cannot be determined.
     */
    public static function woocommerce_version(): ?string
    {
        if (defined('WC_VERSION')) {
            return WC_VERSION;
        }

        if (!function_exists('WC')) {
            return null;
        }

        $woocommerce = WC();
        if (!is_object($woocommerce)) {
            return null;
        }

        $version = $woocommerce->version ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * The running PHP version, or null when it cannot be determined.
     */
    public static function php_version(): ?string
    {
        if (defined('PHP_VERSION')) {
            return PHP_VERSION;
        }

        if (function_exists('phpversion')) {
            return phpversion();
        }

        return null;
    }

    /**
     * The installed beta test plugin version, or null when it is not present.
     */
    public static function beta_test_plugin_version(): ?string
    {
        return defined('TOROB_BETA_TEST_VERSION') ? TOROB_BETA_TEST_VERSION : null;
    }

    /**
     * The libsodium version, or null when no sodium implementation is available.
     */
    public static function libsodium_version(): ?string
    {
        // Native sodium extension (bundled in PHP 7.2+, PECL libsodium for older)
        if (extension_loaded('sodium') || extension_loaded('libsodium')) {
            if (defined('SODIUM_LIBRARY_VERSION')) {
                return SODIUM_LIBRARY_VERSION;
            }

            return 'unknown';
        }

        // Polyfill/compat library (WordPress includes sodium_compat)
        if (class_exists('ParagonIE_Sodium_Compat', false)) {
            return ParagonIE_Sodium_Compat::VERSION_STRING . '-compat';
        }

        return null;
    }
}
