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
    private TokenController $token_controller;
    private QueueServices $queue_services;
    private ProductChangeObserver $product_change_observer;

    public function __construct()
    {
        $this->token_controller = new TokenController();
        $this->queue_services = new QueueServices();
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
            QueueServices::unregister_cron_hooks();
            QueueServices::clear_pending_queue();
        }
    }

    /**
     * Clear the scheduled cron event on plugin deactivation.
     */
    public static function plugin_deactivated(): void
    {
        QueueServices::unregister_cron_hooks();
        QueueServices::clear_pending_queue();
    }

    /**
     * Register the REST endpoint for receiving the webhook token from Torob.
     */
    public function register_webhook_token_route(TorobTokenValidator $validator): void
    {
        $this->token_controller->register_webhook_token_route($validator);
    }

    /**
     * Register all product page webhook hooks.
     */
    public function register_hooks(): void
    {
        $this->product_change_observer->register_product_hooks();
        $this->queue_services->register_cron_hooks();
    }

    /**
     * Count products currently waiting in the webhook queue.
     */
    public function get_pending_queue_product_count(): int
    {
        return $this->queue_services->count_pending();
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
