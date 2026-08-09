<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Coordinates product page webhook controllers and lifecycle operations.
 */
class WebhookHandler
{
    private Controller $controller;
    private QueueServices $queue_services;
    private QueueRunner $queue_runner;
    private ProductChangeObserver $product_change_observer;

    public function __construct(?bool $wp_cron_disabled = null)
    {
        $this->queue_services = new QueueServices();
        $this->queue_runner = new QueueRunner($this->queue_services, $wp_cron_disabled);
        $this->controller = new Controller($this->queue_runner);
        $this->product_change_observer = new ProductChangeObserver($this->queue_services);
    }

    /**
     * Set the product page webhook enabled state.
     */
    public function set_webhook_enabled(bool $enabled): void
    {
        if ($enabled) {
            Options::setProductPageWebhookEnabled(true);
        } else {
            Options::setProductPageWebhookEnabled(false);
            $this->queue_runner->unregister_hooks();
            QueueRunner::clear_state();
            QueueServices::clear_pending_queue();
        }
    }

    /**
     * Clear product webhook queue and runner state on plugin deactivation.
     */
    public function plugin_deactivated(): void
    {
        $this->queue_runner->unregister_hooks();
        QueueRunner::clear_state();
        QueueServices::clear_pending_queue();
    }

    /**
     * Register product page webhook REST endpoints.
     */
    public function register_routes(TorobTokenValidator $validator): void
    {
        $this->controller->register_routes($validator);
    }

    /**
     * Register all product page webhook hooks.
     */
    public function register_hooks(): void
    {
        $this->product_change_observer->register_product_hooks();
        $this->queue_runner->register_hooks();
    }

    /**
     * Count products currently waiting in the webhook queue.
     */
    public function get_pending_queue_product_count(): int
    {
        return $this->queue_services->count_pending();
    }

    /**
     * Get the completion time of the last queue run.
     */
    public function get_queue_runner_last_run_timestamp(): ?int
    {
        return $this->queue_runner->get_last_run_timestamp();
    }

    /**
     * Get the next queue run time.
     */
    public function get_queue_runner_next_run_timestamp(): ?int
    {
        return $this->queue_runner->get_next_run_timestamp();
    }

    /**
     * Fetch queued products for admin preview.
     *
     * @return array<int, object>
     */
    public function get_pending_queue_products(int $limit, int $offset): array
    {
        return $this->queue_services->get_paginated_webhook_items($limit, $offset);
    }
}
