<?php

declare(strict_types=1);

namespace Torob\Utils;

use ParagonIE_Sodium_Compat;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Read-only accessors for the site's domain, environment versions and setup.
 */
final class SiteData
{
    public const PAGE_TYPE_BLOCK = 'block';
    public const PAGE_TYPE_SHORTCODE = 'shortcode';
    public const PAGE_TYPE_OTHER = 'other';
    public const PAGE_TYPE_MISSING = 'missing';

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

    /**
     * Whether OpenSSL is loaded and the host has not disabled `openssl_verify()`.
     */
    public static function openssl_loaded(): bool
    {
        return extension_loaded('openssl') && function_exists('openssl_verify');
    }

    /**
     * Whether WordPress uses pretty permalinks; without them REST URLs look like `/?rest_route=/...`.
     */
    public static function pretty_permalinks_enabled(): bool
    {
        $structure = get_option('permalink_structure', '');

        return is_string($structure) && $structure !== '';
    }

    /**
     * Whether the configured home URL is HTTPS.
     */
    public static function home_url_https(): bool
    {
        return wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https';
    }

    /**
     * Whether WordPress sees the current request, Torob's product-list crawl, as HTTPS. False on an
     * HTTPS home URL has two causes: Torob crawled over `http://`, or a proxy or CDN ends TLS without
     * telling WordPress, so the URLs WordPress builds start with `http://`. Only a false value on an
     * `https://` crawl points to the proxy.
     */
    public static function request_https(): bool
    {
        return is_ssl();
    }

    /**
     * Sorted folder names of the active plugins, including network-activated ones on multisite.
     * A plugin that is a single file in the plugins folder is reported by its file name.
     *
     * @return list<string>
     */
    public static function active_plugin_slugs(): array
    {
        $plugin_files = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $plugin_files = array_merge(
                $plugin_files,
                array_keys((array) get_site_option('active_sitewide_plugins', []))
            );
        }

        $slugs = [];
        foreach ($plugin_files as $plugin_file) {
            if (!is_string($plugin_file) || $plugin_file === '') {
                continue;
            }

            $directory = dirname($plugin_file);
            $slugs[] = $directory === '.' ? basename($plugin_file, '.php') : $directory;
        }

        return self::sorted_unique($slugs);
    }

    /**
     * Sorted file names, without `.php`, of the must-use plugins, which WordPress loads without activation.
     *
     * @return list<string>
     */
    public static function must_use_plugin_slugs(): array
    {
        $slugs = [];
        foreach (wp_get_mu_plugins() as $plugin_file) {
            $slugs[] = basename($plugin_file, '.php');
        }

        return self::sorted_unique($slugs);
    }

    /**
     * Whether `WP_CACHE` is on, which page-cache plugins set to load their drop-in.
     */
    public static function wp_cache_enabled(): bool
    {
        return defined('WP_CACHE') && WP_CACHE;
    }

    /**
     * Whether a page-cache drop-in (`wp-content/advanced-cache.php`) is installed.
     */
    public static function advanced_cache_dropin_installed(): bool
    {
        return file_exists(WP_CONTENT_DIR . '/advanced-cache.php');
    }

    /**
     * How the WooCommerce checkout page renders: the checkout block, the classic checkout (the
     * `[woocommerce_checkout]` shortcode or WooCommerce's Classic Checkout block), something else
     * such as a page builder, or no published checkout page.
     */
    public static function checkout_page_type(): string
    {
        $page = self::published_woocommerce_page('checkout');
        if ($page === null) {
            return self::PAGE_TYPE_MISSING;
        }

        if (has_block('woocommerce/checkout', $page)) {
            return self::PAGE_TYPE_BLOCK;
        }

        if (
            self::has_classic_checkout_block($page->post_content)
            || self::has_shortcode_tag($page->post_content, 'woocommerce_checkout')
        ) {
            return self::PAGE_TYPE_SHORTCODE;
        }

        return self::PAGE_TYPE_OTHER;
    }

    /**
     * How the My Account page renders: the `[woocommerce_my_account]` shortcode, something else
     * such as a page builder, or no published My Account page.
     */
    public static function myaccount_page_type(): string
    {
        $page = self::published_woocommerce_page('myaccount');
        if ($page === null) {
            return self::PAGE_TYPE_MISSING;
        }

        return self::has_shortcode_tag($page->post_content, 'woocommerce_my_account')
            ? self::PAGE_TYPE_SHORTCODE
            : self::PAGE_TYPE_OTHER;
    }

    /**
     * Whether the classic checkout shows the "Returning customer? Click here to login" form.
     */
    public static function checkout_login_reminder_enabled(): bool
    {
        return get_option('woocommerce_enable_checkout_login_reminder') === 'yes';
    }

    /**
     * Whether buyers may check out without an account.
     */
    public static function guest_checkout_enabled(): bool
    {
        return get_option('woocommerce_enable_guest_checkout') === 'yes';
    }

    /**
     * Whether buyers may create an account during checkout.
     */
    public static function checkout_registration_enabled(): bool
    {
        return get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes';
    }

    /**
     * Whether the My Account page offers registration.
     */
    public static function myaccount_registration_enabled(): bool
    {
        return get_option('woocommerce_enable_myaccount_registration') === 'yes';
    }

    /**
     * The published WooCommerce page configured for `$page`, such as `checkout`, or null.
     */
    private static function published_woocommerce_page(string $page): ?WP_Post
    {
        $page_id = wc_get_page_id($page);
        $post = $page_id > 0 ? get_post($page_id) : null;

        return $post instanceof WP_Post && $post->post_status === 'publish' ? $post : null;
    }

    /**
     * Whether the content holds the shortcode tag. Unlike has_shortcode(), this works before the
     * shortcode is registered; like WordPress, it skips escaped `[[tag]]`, which renders as text.
     */
    private static function has_shortcode_tag(string $content, string $tag): bool
    {
        if (!preg_match_all('/' . get_shortcode_regex([$tag]) . '/', $content, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '[' || ($match[6] ?? '') !== ']') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the content holds WooCommerce's Classic Checkout block, which "Create pages" and
     * "Switch to classic checkout" save instead of the `[woocommerce_checkout]` text.
     */
    private static function has_classic_checkout_block(string $content): bool
    {
        if (!has_block('woocommerce/classic-shortcode', $content)) {
            return false;
        }

        return self::blocks_include_classic_checkout(parse_blocks($content));
    }

    /**
     * @param array<array-key, mixed> $blocks Parsed blocks, searched with their inner blocks.
     */
    private static function blocks_include_classic_checkout(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (
                ($block['blockName'] ?? null) === 'woocommerce/classic-shortcode'
                && ($block['attrs']['shortcode'] ?? null) === 'checkout'
            ) {
                return true;
            }

            if (self::blocks_include_classic_checkout((array) ($block['innerBlocks'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted_unique(array $values): array
    {
        $values = array_unique($values);
        sort($values, SORT_STRING);

        return $values;
    }
}
