<?php

declare(strict_types=1);

namespace Torob;

use Torob\Admin\AdminPage;
use Torob\OrderStatusTracking\OrderHandler;
use Torob\ProductExtraction\ProductExtractor;
use Torob\Utils\TorobTokenValidator;

if (!defined('ABSPATH')) {
    exit();
}

class TorobPlugin
{
    private static ?TorobPlugin $instance = null;

    private OrderHandler $order_handler;
    private ProductExtractor $product_extractor;
    private AdminPage $admin_page;
    private TorobTokenValidator $token_validator;

    public static function instance(): TorobPlugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->order_handler = new OrderHandler();
        $this->product_extractor = new ProductExtractor();
        $this->admin_page = new AdminPage();
        $this->token_validator = new TorobTokenValidator();
    }

    public function register_hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);

        if (is_admin()) {
            $this->order_handler->register_meta_box_hooks();
            $this->admin_page->register_hooks();
        }
    }

    public function register_routes(): void
    {
        $this->product_extractor->register_products_route($this->token_validator);
        $this->order_handler->register_order_status_route($this->token_validator);
    }
}
