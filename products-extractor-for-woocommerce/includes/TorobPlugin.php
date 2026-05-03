<?php

declare(strict_types=1);

namespace Torob;

use Torob\Admin\AdminPage;
use Torob\OrderStatusTracking\OrderHandler;
use Torob\ProductExtraction\ProductExtractor;
use Torob\ProductWebhook\WebhookHandler;
use Torob\ProductWebhook\WebhookQueueRepository;
use Torob\Utils\DatabaseSchemaManager;
use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;

if (!defined('ABSPATH')) {
    exit();
}

class TorobPlugin
{
    private static ?TorobPlugin $instance = null;

    private OrderHandler $order_handler;
    private OrderTrackingHandler $order_tracking_handler;
    private ProductExtractor $product_extractor;
    private WebhookHandler $product_page_webhook_handler;
    private AdminPage $admin_page;
    private TorobTokenValidator $token_validator;

    public static function instance(): TorobPlugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        DatabaseSchemaManager::migrateIfNeeded();
    }

    public static function deactivate(): void
    {
        WebhookHandler::plugin_deactivated();
    }

    public static function uninstall(): void
    {
        WebhookQueueRepository::drop_table();
        Options::deleteAllPluginOptions();
    }

    private function __construct()
    {
        $this->order_handler = new OrderHandler();
        $this->order_tracking_handler = new OrderTrackingHandler();
        $this->product_extractor = new ProductExtractor();
        $this->product_page_webhook_handler = new WebhookHandler();
        $this->admin_page = new AdminPage($this->product_extractor, $this->product_page_webhook_handler);
        $this->token_validator = new TorobTokenValidator();
    }

    public function register_hooks(): void
    {
        if (is_admin() || wp_doing_cron()) {
            DatabaseSchemaManager::migrateIfNeeded();
        }
        add_action('rest_api_init', [$this, 'register_routes']);

        if (is_admin()) {
            $this->order_handler->register_meta_box_hooks();
            $this->admin_page->register_hooks();
        }

        $this->order_tracking_handler->register_hooks();
        $this->product_page_webhook_handler->register_hooks();
    }

    public function register_routes(): void
    {
        $this->product_extractor->register_products_route($this->token_validator);
        $this->order_handler->register_order_status_route($this->token_validator);
        $this->order_tracking_handler->register_orders_route($this->token_validator);
        $this->product_page_webhook_handler->register_webhook_token_route($this->token_validator);
    }
}
