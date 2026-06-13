<?php

declare(strict_types=1);

namespace Torob\Lifecycle;

use Throwable;
use Torob\Utils\Options;
use Torob\Utils\SiteData;
use Torob\Utils\TorobHttpClient;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sends plugin lifecycle events (activate / update / deactivate / uninstall) to Torob.
 *
 * Dispatch is best-effort: errors are swallowed so the events never interrupt the
 * activation, deactivation or uninstall flow.
 *
 * Multisite/network activation is out of scope: the hooks fire in network-admin
 * context and a single event is sent for the main site.
 */
final class LifecycleEventReporter
{
    public const EVENT_ACTIVATED = 'activated';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DEACTIVATED = 'deactivated';
    public const EVENT_UNINSTALLED = 'uninstalled';

    /**
     * Register the admin version-change detection hook.
     */
    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'detect_version_change']);
    }

    /**
     * Send the `activated` event and store the reported version.
     */
    public static function record_activation(): void
    {
        self::send(self::EVENT_ACTIVATED);
        self::remember_reported_version();
    }

    /**
     * Send the `deactivated` event.
     */
    public static function record_deactivation(): void
    {
        self::send(self::EVENT_DEACTIVATED);
    }

    /**
     * Send the `uninstalled` event.
     */
    public static function record_uninstall(): void
    {
        self::send(self::EVENT_UNINSTALLED);
    }

    /**
     * Send an `updated` event when the stored reported version differs from the
     * current plugin version, then store the current version so it fires once
     * per change.
     *
     * Always reports `updated`: a genuine first install is reported as
     * `activated` from the activation hook, which also stores the version, so by
     * the time this runs on a mismatch the plugin has already been installed.
     */
    public function detect_version_change(): void
    {
        $stored_version = Options::getReportedVersion();
        if ($stored_version === TOROB_PLUGIN_VERSION) {
            return;
        }

        self::send(self::EVENT_UPDATED);
        self::remember_reported_version();
    }

    /**
     * Send a lifecycle event, swallowing errors.
     */
    private static function send(string $event): void
    {
        if (!self::should_report($event)) {
            return;
        }

        try {
            TorobHttpClient::send_lifecycle_event($event, TOROB_PLUGIN_VERSION);
        } catch (Throwable $error) {
            error_log(sprintf('[Torob Plugin] Lifecycle event "%s" reporting threw: %s', $event, $error->getMessage()));
        }
    }

    /**
     * Decide whether lifecycle events may be reported from this site.
     */
    private static function should_report(string $event): bool
    {
        if (defined('TOROB_DISABLE_LIFECYCLE_EVENTS') && TOROB_DISABLE_LIFECYCLE_EVENTS) {
            return false;
        }

        return (bool) apply_filters(
            'torob_lifecycle_event_reporting_enabled',
            !self::is_local_site_url(SiteData::url()),
            $event
        );
    }

    /**
     * Local and private addresses are development/test installs, not merchant sites.
     */
    private static function is_local_site_url(string $site_url): bool
    {
        $parsed_url = wp_parse_url($site_url);
        $host = is_array($parsed_url) ? $parsed_url['host'] ?? null : null;
        if (!is_string($host)) {
            return false;
        }

        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
        $host = rtrim($host, '.');
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || substr($host, -10) === '.localhost') {
            return true;
        }

        if (substr($host, -6) === '.local' || substr($host, -5) === '.test') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Store the current plugin version as the last reported one, swallowing errors.
     */
    private static function remember_reported_version(): void
    {
        try {
            Options::setReportedVersion(TOROB_PLUGIN_VERSION);
        } catch (Throwable $error) {
            error_log(sprintf('[Torob Plugin] Failed to store reported plugin version: %s', $error->getMessage()));
        }
    }
}
