<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use Torob\Utils\TorobHttpClient;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles product page webhook queue buffering, persistence, reads, and delivery.
 */
class QueueServices
{
    const CRON_HOOK = 'torob_process_pending_product_page_webhooks';
    const CRON_INTERVAL = 'every_five_minutes';
    const BATCH_SIZE = 100;
    const DEBOUNCE_SECONDS = 300;
    const PROCESSING_LOCK_TTL_SECONDS = 300;

    /** @var array<int, WebhookItem> */
    private static array $pending_queue_items = [];
    private static bool $shutdown_flush_registered = false;

    /**
     * Register the cron schedule and processing hook.
     */
    public function register_cron_hooks(): void
    {
        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        add_action(self::CRON_HOOK, [$this, 'process_pending_product_page_webhooks']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public static function unregister_cron_hooks(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Clear buffered and persisted pending queue items.
     */
    public static function clear_pending_queue(): void
    {
        self::$pending_queue_items = [];
        WebhookQueueRepository::clear();
    }

    /**
     * Count products currently waiting in the webhook queue.
     */
    public function count_pending(): int
    {
        return WebhookQueueRepository::count_pending();
    }

    /**
     * Fetch queued products for admin preview.
     *
     * @return array<int, object>
     */
    public function get_paginated_webhook_items(int $limit, int $offset): array
    {
        return WebhookQueueRepository::get_paginated_webhook_items($limit, $offset);
    }

    /**
     * Queue a resolved webhook item to be persisted at shutdown.
     */
    public function queue_webhook_item(?WebhookItem $item): void
    {
        if ($item === null) {
            return;
        }

        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        self::$pending_queue_items[(int) $item->get_page_unique()] = $item;

        $this->register_shutdown_flush();
    }

    /**
     * Persist one resolved item immediately.
     */
    public function upsert_queued_item(WebhookItem $item, ?string $date_modified = null): bool
    {
        return WebhookQueueRepository::upsert_queued_product(
            (int) $item->get_page_unique(),
            $date_modified ?? current_time('mysql', true),
            $item->get_page_url()
        );
    }

    /**
     * Persist all queue rows collected during this request in one pass.
     */
    public function flush_queued_products(): void
    {
        self::$shutdown_flush_registered = false;
        if (empty(self::$pending_queue_items)) {
            return;
        }

        if (!Options::isProductPageWebhookReady()) {
            self::$pending_queue_items = [];
            return;
        }

        $date_modified = current_time('mysql', true);
        $pending_queue_items = self::$pending_queue_items;
        self::$pending_queue_items = [];
        foreach ($pending_queue_items as $product_id => $item) {
            $queued = $this->upsert_queued_item($item, $date_modified);

            $this->handle_queue_write_result($product_id, $queued);
        }
    }

    /**
     * Add the every-five-minutes cron schedule.
     *
     * @param array $schedules Existing cron schedules.
     *
     * @return array
     */
    public function add_cron_schedule(array $schedules): array
    {
        if (!array_key_exists(self::CRON_INTERVAL, $schedules)) {
            $schedules[self::CRON_INTERVAL] = [
                'interval' => 300,
                'display' => 'Every Five Minutes'
            ];
        }

        return $schedules;
    }

    /**
     * Process pending product page webhook entries: select debounced rows, send, and clean up.
     */
    public function process_pending_product_page_webhooks(): void
    {
        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        if (!$this->claim_processing_lock()) {
            error_log('Product page webhook queue services: Processing lock already exists');
            return;
        }

        try {
            $rows = WebhookQueueRepository::get_ready_batch(
                current_time('mysql', true),
                self::DEBOUNCE_SECONDS,
                self::BATCH_SIZE
            );

            if (empty($rows)) {
                return;
            }

            $items = [];

            foreach ($rows as $row) {
                $items[] = new WebhookItem((string) $row->product_id, (string) $row->page_url);
            }

            $send_result = TorobHttpClient::send_product_page_webhook_items(...$items);

            if ($send_result->is_success()) {
                WebhookQueueRepository::delete_processed_rows($rows);
                return;
            }

            if ($send_result->get_status_code() === 401) {
                error_log('Product page webhook queue services: Unauthorized, resetting token');
                Options::resetToken();
                self::unregister_cron_hooks();
            }
        } finally {
            $this->release_processing_lock();
        }
    }

    /**
     * Log the outcome of a queue write consistently.
     */
    private function handle_queue_write_result(int $product_id, bool $queued): void
    {
        if (!$queued) {
            global $wpdb;
            error_log(sprintf(
                '[Torob Plugin] Failed to queue product %d for webhook: %s',
                $product_id,
                $wpdb->last_error
            ));

            return;
        }
    }

    /**
     * Register a shutdown flush for request-local queue writes.
     */
    private function register_shutdown_flush(): void
    {
        if (self::$shutdown_flush_registered) {
            return;
        }

        self::$shutdown_flush_registered = true;
        add_action('shutdown', [$this, 'flush_queued_products'], 0);
    }

    /**
     * Acquire a short-lived site-local processing lock.
     */
    private function claim_processing_lock(): bool
    {
        $expires_at = time() + self::PROCESSING_LOCK_TTL_SECONDS;

        if (add_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, (string) $expires_at, '', false)) {
            return true;
        }

        $existing_expires_at = (int) get_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, 0);
        if ($existing_expires_at > time()) {
            return false;
        }

        delete_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION);

        return add_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, (string) $expires_at, '', false);
    }

    /**
     * Release the site-local processing lock.
     */
    private function release_processing_lock(): void
    {
        delete_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION);
    }
}
