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
    const PROCESSING_INTERVAL_SECONDS = 300;
    const BATCH_SIZE = 100;
    const DEBOUNCE_SECONDS = self::PROCESSING_INTERVAL_SECONDS;
    const PROCESSING_LOCK_TTL_SECONDS = self::PROCESSING_INTERVAL_SECONDS;

    /** @var array<int, WebhookItem> */
    private static array $pending_queue_items = [];
    private static bool $shutdown_flush_registered = false;

    /**
     * Remove all scheduled product webhook queue events.
     */
    public static function clear_cron_schedule(): void
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
     * Process pending product page webhook entries: select debounced rows, send, and clean up.
     */
    public function process_pending_product_page_webhooks(): void
    {
        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        $processing_lock = $this->claim_processing_lock();
        if ($processing_lock === null) {
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
                self::clear_cron_schedule();
            }
        } finally {
            $this->release_processing_lock($processing_lock);
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
     *
     * @return array{token: string, expires_at: int}|null
     */
    private function claim_processing_lock(): ?array
    {
        $current_timestamp = time();
        $processing_lock = [
            'token' => wp_generate_password(64, false, false),
            'expires_at' => $current_timestamp + self::PROCESSING_LOCK_TTL_SECONDS
        ];
        $existing_lock = get_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, null);

        if ($existing_lock === null) {
            return add_option(Options::PRODUCT_PAGE_WEBHOOK_PROCESSING_LOCK_OPTION, $processing_lock, '', false)
                ? $processing_lock
                : null;
        }

        if ($this->is_processing_lock_active($existing_lock, $current_timestamp)) {
            return null;
        }

        return Options::replaceProductPageWebhookProcessingLock($existing_lock, $processing_lock)
            ? $processing_lock
            : null;
    }

    /**
     * Check whether a current or legacy processing lock is still active.
     *
     * @param mixed $processing_lock
     */
    private function is_processing_lock_active($processing_lock, int $current_timestamp): bool
    {
        if (is_array($processing_lock)) {
            return (
                array_key_exists('expires_at', $processing_lock)
                && (int) $processing_lock['expires_at'] > $current_timestamp
            );
        }

        return is_numeric($processing_lock) && (int) $processing_lock > $current_timestamp;
    }

    /**
     * Release the site-local processing lock only while this worker still owns it.
     *
     * @param array{token: string, expires_at: int} $processing_lock
     */
    private function release_processing_lock(array $processing_lock): void
    {
        Options::deleteProductPageWebhookProcessingLock($processing_lock);
    }
}
