<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs the product webhook queue through WP-Cron with a request-driven fallback.
 */
final class QueueRunner
{
    const REST_NAMESPACE = 'torob-api/v1';
    const REST_ROUTE = '/product-webhook-runner';
    const TOKEN_PARAMETER = 'runner_token';
    const RUNNER_LOCK_TTL_SECONDS = 60;
    const FALLBACK_INACTIVE_ERROR = 'torob_runner_fallback_inactive';
    const INVALID_TOKEN_ERROR = 'torob_invalid_runner_token';
    const WEBHOOK_NOT_READY_ERROR = 'torob_product_webhook_not_ready';

    private const START_TIMESTAMP_ERROR = 'torob_runner_start_timestamp_error';
    private const COMPLETION_TIMESTAMP_ERROR = 'torob_runner_completion_timestamp_error';

    private QueueServices $queue_services;
    private ?bool $wp_cron_disabled_override;
    private bool $fallback_state_initialized = false;
    private bool $is_handling_runner_request = false;

    public function __construct(QueueServices $queue_services, ?bool $wp_cron_disabled = null)
    {
        $this->queue_services = $queue_services;
        $this->wp_cron_disabled_override = $wp_cron_disabled;
    }

    /**
     * Register scheduler initialization after WordPress and plugin translations are ready.
     */
    public function register_hooks(): void
    {
        if (did_action('init') > 0) {
            $this->initialize_scheduler();

            return;
        }

        add_action('init', [$this, 'initialize_scheduler'], 10);
    }

    /**
     * Register the active scheduling mechanism.
     */
    public function initialize_scheduler(): void
    {
        remove_action('init', [$this, 'initialize_scheduler'], 10);

        if (!Options::isProductPageWebhookEnabled()) {
            $this->configure_disabled_mode();

            return;
        }

        if ($this->is_fallback_active()) {
            $this->configure_fallback_mode();

            return;
        }

        $this->configure_wp_cron_mode();
    }

    /**
     * Remove callbacks for both scheduling mechanisms from the current request.
     */
    public function unregister_hooks(): void
    {
        remove_action('init', [$this, 'initialize_scheduler'], 10);
        remove_action('shutdown', [$this, 'maybe_dispatch'], 10);
        $this->unregister_wp_cron_hooks();
        $this->fallback_state_initialized = false;
    }

    /**
     * Add the shared five-minute WP-Cron schedule.
     *
     * @param array $schedules Existing cron schedules.
     *
     * @return array
     */
    public function add_cron_schedule(array $schedules): array
    {
        if (!array_key_exists(QueueServices::CRON_INTERVAL, $schedules)) {
            $schedules[QueueServices::CRON_INTERVAL] = [
                'interval' => QueueServices::PROCESSING_INTERVAL_SECONDS,
                'display' => 'Every Five Minutes'
            ];
        }

        return $schedules;
    }

    /**
     * Process one WP-Cron queue run.
     */
    public function run_cron(): void
    {
        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        $this->record_run_started();
        $this->process_queue();
        $this->record_run_completed();
    }

    /**
     * Dispatch one non-blocking fallback request when the processing interval has elapsed.
     */
    public function maybe_dispatch(): void
    {
        if (!$this->is_fallback_active()) {
            Options::resetProductPageWebhookRunnerLock();

            return;
        }

        if ($this->is_handling_runner_request) {
            return;
        }

        $this->initialize_fallback_state();

        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        $current_timestamp = time();
        if (!$this->is_due($current_timestamp)) {
            return;
        }

        $lock_token = $this->acquire_runner_lock($current_timestamp);
        if ($lock_token === null) {
            return;
        }

        $response = wp_remote_post(rest_url(self::REST_NAMESPACE . self::REST_ROUTE), [
            'timeout' => 0.01,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking' => false,
            'body' => [self::TOKEN_PARAMETER => $lock_token],
            'cookies' => [],
            'sslverify' => apply_filters('https_local_ssl_verify', false)
        ]);

        if (!is_wp_error($response)) {
            return;
        }

        error_log(sprintf(
            '[Torob Plugin] Product webhook queue runner loopback failed: %s (%s)',
            $response->get_error_message(),
            $response->get_error_code()
        ));
    }

    /**
     * Process one authenticated fallback request.
     *
     * @return bool|WP_Error Whether the queue was processed, or the processing failure.
     */
    public function run_fallback(string $lock_token)
    {
        $this->is_handling_runner_request = true;
        $cooldown_until = null;

        if (!$this->is_fallback_active()) {
            return new WP_Error(self::FALLBACK_INACTIVE_ERROR, 'product webhook runner fallback is not active');
        }

        $claimed_lock = $this->claim_runner_lock($lock_token, time());
        if ($claimed_lock === null) {
            return new WP_Error(self::INVALID_TOKEN_ERROR, 'runner token is no longer valid');
        }

        try {
            if (!Options::isProductPageWebhookReady()) {
                return new WP_Error(self::WEBHOOK_NOT_READY_ERROR, 'product page webhook is not ready');
            }

            $started_at = time();
            $next_due_timestamp = $this->get_next_due_timestamp();
            if ($next_due_timestamp !== null && $started_at < $next_due_timestamp) {
                $cooldown_until = $next_due_timestamp;

                return false;
            }

            $start_error = $this->record_run_started();
            if ($start_error !== null) {
                return $start_error;
            }

            $cooldown_until = $started_at + QueueServices::PROCESSING_INTERVAL_SECONDS;
            $this->process_queue();

            $completion_error = $this->record_run_completed();
            if ($completion_error !== null) {
                return $completion_error;
            }

            return true;
        } finally {
            $this->complete_runner_claim($claimed_lock, $cooldown_until);
        }
    }

    /**
     * Get the completion time of the last queue run, regardless of trigger type.
     */
    public function get_last_run_timestamp(): ?int
    {
        return Options::getProductPageWebhookRunnerLastCompletedAt();
    }

    /**
     * Get the next queue run time for the active scheduling mechanism.
     */
    public function get_next_run_timestamp(): ?int
    {
        if (!$this->is_wp_cron_disabled()) {
            $next_run_timestamp = wp_next_scheduled(QueueServices::CRON_HOOK);

            return $next_run_timestamp === false ? null : (int) $next_run_timestamp;
        }

        if (!Options::isProductPageWebhookEnabled()) {
            return null;
        }

        $last_started_at = Options::getProductPageWebhookRunnerLastStartedAt();
        if ($last_started_at === null) {
            return null;
        }

        return $last_started_at + QueueServices::PROCESSING_INTERVAL_SECONDS;
    }

    /**
     * Remove state for both scheduling mechanisms.
     */
    public static function clear_state(): void
    {
        QueueServices::clear_cron_schedule();
        Options::resetProductPageWebhookRunner();
    }

    /**
     * Record when a queue run starts.
     */
    private function record_run_started(): ?WP_Error
    {
        if (!Options::setProductPageWebhookRunnerLastStartedAt(time())) {
            error_log('[Torob Plugin] Product webhook queue runner could not persist its start timestamp.');

            return new WP_Error(self::START_TIMESTAMP_ERROR, 'unable to persist runner start timestamp');
        }

        return null;
    }

    /**
     * Process the pending product webhook queue.
     */
    private function process_queue(): void
    {
        $this->queue_services->process_pending_product_page_webhooks();
    }

    /**
     * Record when a queue run completes.
     */
    private function record_run_completed(): ?WP_Error
    {
        if (!Options::setProductPageWebhookRunnerLastCompletedAt(time())) {
            error_log('[Torob Plugin] Product webhook queue runner could not persist its completion timestamp.');

            return new WP_Error(self::COMPLETION_TIMESTAMP_ERROR, 'unable to persist runner completion timestamp');
        }

        return null;
    }

    /**
     * Ensure the cheap autoloaded fallback cadence timestamp exists.
     */
    private function initialize_runner_if_needed(): void
    {
        $stored_timestamp = get_option(Options::PRODUCT_PAGE_WEBHOOK_RUNNER_LAST_STARTED_AT_OPTION, false);
        if ($stored_timestamp !== false) {
            return;
        }

        Options::initializeProductPageWebhookRunner();
    }

    /**
     * Check whether the request-driven fallback should be active.
     */
    public function is_fallback_active(): bool
    {
        return $this->is_wp_cron_disabled() && Options::isProductPageWebhookEnabled();
    }

    /**
     * Read the current WP-Cron state unless a test override was provided.
     */
    private function is_wp_cron_disabled(): bool
    {
        if ($this->wp_cron_disabled_override !== null) {
            return $this->wp_cron_disabled_override;
        }

        return defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON');
    }

    /**
     * Disable both scheduling mechanisms and remove active events and locks.
     */
    private function configure_disabled_mode(): void
    {
        remove_action('shutdown', [$this, 'maybe_dispatch'], 10);
        $this->unregister_wp_cron_hooks();
        $this->fallback_state_initialized = false;
        QueueServices::clear_cron_schedule();
        Options::resetProductPageWebhookRunnerLock();
    }

    /**
     * Configure server-driven WP-Cron with a request-driven fallback.
     */
    private function configure_fallback_mode(): void
    {
        add_action('shutdown', [$this, 'maybe_dispatch'], 10);
        $this->initialize_fallback_state();
        $this->configure_wp_cron();
    }

    /**
     * Configure the normal WP-Cron scheduler.
     */
    private function configure_wp_cron_mode(): void
    {
        add_action('shutdown', [$this, 'maybe_dispatch'], 10);
        $this->fallback_state_initialized = false;
        $this->configure_wp_cron();
    }

    /**
     * Register WP-Cron callbacks and ensure its recurring event exists.
     */
    private function configure_wp_cron(): void
    {
        $this->unregister_wp_cron_hooks();

        if (!Options::isProductPageWebhookReady()) {
            QueueServices::clear_cron_schedule();

            return;
        }

        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        add_action(QueueServices::CRON_HOOK, [$this, 'run_cron']);

        if (!wp_next_scheduled(QueueServices::CRON_HOOK)) {
            wp_schedule_event(time(), QueueServices::CRON_INTERVAL, QueueServices::CRON_HOOK);
        }
    }

    /**
     * Remove request-local WP-Cron callbacks.
     */
    private function unregister_wp_cron_hooks(): void
    {
        remove_filter('cron_schedules', [$this, 'add_cron_schedule']);
        remove_action(QueueServices::CRON_HOOK, [$this, 'run_cron']);
    }

    /**
     * Prepare fallback scheduling state once for the current request.
     */
    private function initialize_fallback_state(): void
    {
        if ($this->fallback_state_initialized) {
            return;
        }

        $this->initialize_runner_if_needed();
        $this->fallback_state_initialized = true;
    }

    /**
     * Check a token against the current, unexpired runner lock.
     */
    public function is_runner_token_valid(string $lock_token): bool
    {
        return $this->has_valid_runner_lock($lock_token, time());
    }

    /**
     * Check whether a fallback queue run is due.
     */
    private function is_due(int $current_timestamp): bool
    {
        $next_due_timestamp = $this->get_next_due_timestamp();

        return $next_due_timestamp === null || $current_timestamp >= $next_due_timestamp;
    }

    /**
     * Get the earliest timestamp at which another fallback run is due.
     */
    private function get_next_due_timestamp(): ?int
    {
        $last_started_at = Options::getProductPageWebhookRunnerLastStartedAt();

        return $last_started_at === null ? null : $last_started_at + QueueServices::PROCESSING_INTERVAL_SECONDS;
    }

    /**
     * Acquire the short-lived lock that suppresses concurrent fallback requests.
     */
    private function acquire_runner_lock(int $current_timestamp): ?string
    {
        $existing_lock = get_option(Options::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION, null);
        if ($this->is_active_runner_lock($existing_lock, $current_timestamp)) {
            return null;
        }

        $lock_token = wp_generate_password(64, false, false);
        $runner_lock = [
            'token' => $lock_token,
            'expires_at' => $current_timestamp + self::RUNNER_LOCK_TTL_SECONDS
        ];

        if ($existing_lock === null) {
            $acquired = add_option(Options::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION, $runner_lock, '', false);
        } else {
            $acquired = Options::replaceProductPageWebhookRunnerLock($existing_lock, $runner_lock);
        }

        return $acquired ? $lock_token : null;
    }

    /**
     * Atomically claim an authenticated runner lock before processing begins.
     *
     * @return array{token: string, owner: string, expires_at: int}|null
     */
    private function claim_runner_lock(string $lock_token, int $current_timestamp): ?array
    {
        $runner_lock = get_option(Options::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION, null);
        if (!$this->runner_lock_matches_token($runner_lock, $lock_token, $current_timestamp)) {
            return null;
        }

        $claimed_lock = [
            'token' => '',
            'owner' => wp_generate_password(64, false, false),
            'expires_at' => (int) $runner_lock['expires_at']
        ];

        return Options::replaceProductPageWebhookRunnerLock($runner_lock, $claimed_lock) ? $claimed_lock : null;
    }

    /**
     * Check a token against the current, unexpired runner lock.
     */
    private function has_valid_runner_lock(string $lock_token, int $current_timestamp): bool
    {
        $runner_lock = get_option(Options::PRODUCT_PAGE_WEBHOOK_RUNNER_LOCK_OPTION, null);

        return $this->runner_lock_matches_token($runner_lock, $lock_token, $current_timestamp);
    }

    /**
     * Check a loaded runner lock against the presented token.
     *
     * @param mixed $runner_lock
     */
    private function runner_lock_matches_token($runner_lock, string $lock_token, int $current_timestamp): bool
    {
        if (
            $lock_token === ''
            || !is_array($runner_lock)
            || !$this->is_active_runner_lock($runner_lock, $current_timestamp)
            || !array_key_exists('token', $runner_lock)
            || !is_string($runner_lock['token'])
            || $runner_lock['token'] === ''
        ) {
            return false;
        }

        return hash_equals((string) $runner_lock['token'], $lock_token);
    }

    /**
     * Release one owned runner claim into an unauthenticated dispatch cooldown.
     *
     * @param array{token: string, owner: string, expires_at: int} $claimed_lock
     */
    private function complete_runner_claim(array $claimed_lock, ?int $cooldown_until): void
    {
        $cooldown_lock = [
            'token' => '',
            'expires_at' => max($claimed_lock['expires_at'], $cooldown_until ?? 0)
        ];
        Options::replaceProductPageWebhookRunnerLock($claimed_lock, $cooldown_lock);
    }

    /**
     * Validate a stored runner lock shape and expiry.
     *
     * @param mixed $runner_lock
     */
    private function is_active_runner_lock($runner_lock, int $current_timestamp): bool
    {
        if (!is_array($runner_lock) || !array_key_exists('expires_at', $runner_lock)) {
            return false;
        }

        return (int) $runner_lock['expires_at'] > $current_timestamp;
    }
}
