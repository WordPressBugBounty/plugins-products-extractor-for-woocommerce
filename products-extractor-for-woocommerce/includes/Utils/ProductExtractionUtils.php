<?php

declare(strict_types=1);

namespace Torob\Utils;

use WC_Product;

if (!defined('ABSPATH')) {
    exit();
}

class ProductExtractionUtils
{
    public static function get_page_unique(WC_Product $product): int
    {
        return $product->get_id();
    }

    public static function get_page_url(WC_Product $product): string
    {
        return $product->get_permalink();
    }
}
