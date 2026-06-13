<?php

declare(strict_types=1);

namespace Torob\ProductExtraction;

use stdClass;
use Torob\Utils\Options;
use Torob\Utils\ProductExtractionUtils;
use Torob\Utils\SiteData;
use Torob\Utils\TorobTokenValidator;
use WC_Data_Store;
use WC_Product;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

class ProductExtractor
{
    private const API_VERSION = 'torob_woocommerce_products_v1';
    private const INFORMATIONAL_PRODUCT_FIELDS = [
        'parent_id',
        'date_added',
        'date_updated',
        'product_type'
    ];

    public function __construct() {}

    public function register_products_route(TorobTokenValidator $validator): void
    {
        $version = '1';
        $namespace = 'wcpe/v' . $version;
        $base = 'products';
        register_rest_route($namespace, '/' . $base, [
            [
                'methods' => 'POST',
                'callback' => [$this, 'get_products'],
                'permission_callback' => [$validator, 'validate_token'],
                'args' => []
            ]
        ]);
    }

    public function get_products(WP_REST_Request $request): WP_REST_Response
    {
        // Get Parameters
        $show_variations = rest_sanitize_boolean($request->get_param('variation'));
        $limit = intval($request->get_param('limit'));
        $page = intval($request->get_param('page'));
        if (!empty($request->get_param('products'))) {
            $product_list = explode(',', sanitize_text_field($request->get_param('products')));
            if (is_array($product_list)) {
                foreach ($product_list as $key => $field) {
                    $product_list[$key] = intval($field);
                }
            }
        }
        if (!empty($request->get_param('slugs'))) {
            $slug_list = explode(',', sanitize_text_field(urldecode($request->get_param('slugs'))));
        }

        $data = [];
        if (!empty($product_list)) {
            $data = $this->get_list_products($product_list);
        } elseif (!empty($slug_list)) {
            $data = $this->get_list_slugs($slug_list);
        } else {
            $data = $this->get_all_products($show_variations, $limit, $page);
        }
        $data['api_version'] = self::API_VERSION;
        $data['metadata'] = $this->build_metadata();

        return new WP_REST_Response($data, 200);
    }

    /**
     * Get single product values.
     *
     * @param WC_Product $product The product to extract values from.
     * @param WC_Product|null $parent The valid parent product when extracting a variation.
     *
     * @return stdClass The extracted product data.
     */
    public function get_product_values(WC_Product $product, ?WC_Product $parent = null): stdClass
    {
        $source_product = $parent ?? $product;

        $temp_product = new stdClass();
        $temp_product->title = $source_product->get_name();
        $temp_product->subtitle = get_post_meta($source_product->get_id(), 'product_english_name', true);
        $cat_ids = $source_product->get_category_ids();
        $temp_product->parent_id = $parent ? $parent->get_id() : 0;
        $temp_product->page_unique = ProductExtractionUtils::get_page_unique($product);
        $temp_product->availability = $product->get_stock_status();
        if ($parent === null && $product->is_type('variable')) {
            $temp_product->current_price = 0;
            $temp_product->old_price = 0;
        } else {
            $temp_product->current_price = $this->get_display_price($product, $product->get_price());
            $temp_product->old_price = $this->get_display_price($product, $product->get_regular_price());
        }
        if ($cat_ids) {
            $temp_product->category_name = get_term_by('id', end($cat_ids), 'product_cat', 'ARRAY_A')['name'];
        }
        $temp_product->image_links = [];
        $attachment_ids = $product->get_gallery_image_ids();
        foreach ($attachment_ids as $attachment_id) {
            $t_link = wp_get_attachment_image_src($attachment_id, 'full');
            if ($t_link) {
                $temp_product->image_links[] = $t_link[0];
            }
        }
        $t_image = wp_get_attachment_image_src($product->get_image_id(), 'full');
        if ($t_image) {
            $temp_product->image_link = $t_image[0];
            if (!in_array($t_image[0], $temp_product->image_links, true)) {
                $temp_product->image_links[] = $t_image[0];
            }
        } else {
            $temp_product->image_link = null;
        }
        $temp_product->page_url = ProductExtractionUtils::get_page_url($product);
        $temp_product->short_desc = $product->get_short_description();
        $temp_product->spec = [];
        $temp_product->date_added = $product->get_date_created()
            ? $product->get_date_created()->format(DATE_ATOM)
            : null;
        $temp_product->date_updated = $product->get_date_modified()
            ? $product->get_date_modified()->format(DATE_ATOM)
            : null;
        $temp_product->product_type = $product->get_type();
        $temp_product->guarantee = '';

        if ($parent === null) {
            if ($product->is_type('variable')) {
                // Find price for default attributes. If it can't find return max price of variations
                $variation_id = $this->find_matching_variation($product, $product->get_default_attributes());
                if ($variation_id !== 0) {
                    $variation = wc_get_product($variation_id);
                    $temp_product->current_price = $this->get_display_price($variation, $variation->get_price());
                    $temp_product->old_price = $this->get_display_price($variation, $variation->get_regular_price());
                    $temp_product->availability = $variation->get_stock_status();
                } else {
                    $temp_product->current_price = $this->normalize_display_price($product->get_variation_price(
                        'max',
                        true
                    ));
                    $temp_product->old_price = $this->normalize_display_price($product->get_variation_regular_price(
                        'max',
                        true
                    ));
                }
                // Extract default attributes
                foreach ($product->get_default_attributes() as $key => $value) {
                    if (empty($value)) {
                        continue;
                    }

                    if (substr($key, 0, 3) === 'pa_') {
                        $value = get_term_by('slug', $value, $key);
                        if ($value) {
                            $value = $value->name;
                        } else {
                            $value = '';
                        }
                        $key = wc_attribute_label($key);
                    }
                    $temp_product->spec[urldecode($key)] = rawurldecode($value);
                }
            }
            // add remain attributes
            foreach ($product->get_attributes() as $attribute) {
                if (!$attribute['visible']) {
                    continue;
                }

                $name = wc_attribute_label($attribute['name']);
                if (substr($attribute['name'], 0, 3) === 'pa_') {
                    $values = wc_get_product_terms($product->get_id(), $attribute['name'], ['fields' => 'names']);
                } else {
                    $values = $attribute['options'];
                }
                if (!array_key_exists($name, $temp_product->spec)) {
                    $temp_product->spec[$name] = implode(', ', $values);
                }
            }
        } else {
            foreach ($product->get_attributes() as $key => $value) {
                if (empty($value)) {
                    continue;
                }

                if (substr($key, 0, 3) === 'pa_') {
                    $value = get_term_by('slug', $value, $key);
                    if ($value) {
                        $value = $value->name;
                    } else {
                        $value = '';
                    }
                    $key = wc_attribute_label($key);
                }
                $temp_product->spec[urldecode($key)] = rawurldecode($value);
            }
        }

        $guarantee_keys = [
            'گارانتی',
            'guarantee',
            'warranty',
            'garanty',
            'گارانتی:',
            'گارانتی محصول',
            'گارانتی محصول:',
            'ضمانت',
            'ضمانت:'
        ];

        foreach ($guarantee_keys as $guarantee) {
            if (empty($temp_product->spec[$guarantee])) {
                continue;
            }

            $temp_product->guarantee = $temp_product->spec[$guarantee];
        }

        if (!array_key_exists('شناسه کالا', $temp_product->spec)) {
            $sku = $product->get_sku();
            if ($sku !== '') {
                $temp_product->spec['شناسه کالا'] = $sku;
            }
        }

        if (count($temp_product->spec) > 0) {
            $temp_product->spec = [$temp_product->spec];
        }

        return $temp_product;
    }

    /**
     * Convert a raw WooCommerce product price to the same tax display mode used on product pages.
     *
     * @param WC_Product $product Product used for tax class/rate lookup.
     * @param mixed $price Raw product price.
     *
     * @return mixed Display price, preserving unchanged raw values.
     */
    private function get_display_price(WC_Product $product, $price)
    {
        if ($price === '' || !wc_tax_enabled() || !$product->is_taxable()) {
            return $price;
        }

        $display_price = wc_get_price_to_display($product, [
            'price' => (float) $price
        ]);

        return $this->normalize_display_price($display_price);
    }

    private function normalize_display_price($price)
    {
        if ($price === '') {
            return $price;
        }

        $display_price = (float) $price;
        if (abs($display_price - round($display_price)) < 0.000_001) {
            return (string) intval(round($display_price));
        }

        return wc_format_decimal($display_price, wc_get_price_decimals());
    }

    /**
     * Get all products with pagination.
     *
     * @param bool $show_variations Whether to include product variations.
     * @param int $limit The number of products to retrieve per page.
     * @param int $page The current page number.
     * @param bool $include_informational_fields Whether to include fields useful for debugging but not directly shown in Torob.
     *
     * @return array The data containing products, count, current page, and max pages.
     */
    public function get_all_products(
        bool $show_variations,
        int $limit,
        int $page,
        bool $include_informational_fields = true
    ): array {
        $args = [
            'posts_per_page' => $limit,
            'paged' => $page,
            'post_status' => 'publish',
            'orderby' => 'ID',
            'order' => 'DESC',
            'post_type' => ['product'],
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'cache_results' => false
        ];
        if ($show_variations) {
            $args['post_type'] = ['product', 'product_variation'];
        }
        $query = new WP_Query($args);
        // Product data is already cached, so calling wc_get_product() must cause no queries at all.
        $products = array_filter(array_map('wc_get_product', $query->posts));
        $data['count'] = $query->found_posts;
        $data['current_page'] = $page;
        $data['max_pages'] = $query->max_num_pages;
        $data['products'] = [];
        $this->fill_image_caches($products);
        // Retrieve and send data in json
        foreach ($products as $product) {
            $is_variation = $product->is_type('variation');
            $parent = $is_variation ? $this->get_valid_parent($product) : null;
            if ($is_variation && (!$product->get_price() || $parent === null)) {
                continue;
            }
            if ($show_variations && $product->is_type('variable')) {
                continue;
            }

            $data['products'][] = $this->get_product_values($product, $parent);
        }

        if (!$include_informational_fields) {
            $data['products'] = $this->remove_informational_product_fields($data['products']);
        }

        return $data;
    }

    /**
     * Remove fields that help diagnostics but do not directly affect Torob-visible product data.
     */
    private function remove_informational_product_fields(array $products): array
    {
        return array_map(static function ($product) {
            if (!is_object($product)) {
                return $product;
            }

            $filtered_product = clone $product;
            foreach (self::INFORMATIONAL_PRODUCT_FIELDS as $field) {
                unset($filtered_product->{$field});
            }

            return $filtered_product;
        }, $products);
    }

    /**
     * Get a list of products by their IDs.
     *
     * @param array $product_list An array of product IDs to retrieve.
     *
     * @return array The list of products with their details.
     */
    public function get_list_products(array $product_list): array
    {
        $data['products'] = [];
        // Retrieve and send data in json
        foreach ($product_list as $pid) {
            $product = wc_get_product($pid);
            if ($product && $product->get_status() === 'publish') {
                $is_variation = $product->is_type('variation');
                $parent = $is_variation ? $this->get_valid_parent($product) : null;
                if ($is_variation && (!$product->get_price() || $parent === null)) {
                    continue;
                }

                $data['products'][] = $this->get_product_values($product, $parent);
            }
        }

        return $data;
    }

    private function get_valid_parent(WC_Product $product): ?WC_Product
    {
        $parent_id = $product->get_parent_id();
        if ($parent_id === 0) {
            return null;
        }

        $parent = wc_get_product($parent_id);

        return $parent instanceof WC_Product ? $parent : null;
    }

    /**
     * Get a list of slugs and retrieve product data by their links.
     *
     * @param array $slug_list An array of product slugs to retrieve.
     *
     * @return array The list of products with their details.
     */
    public function get_list_slugs(array $slug_list): array
    {
        $data['products'] = [];
        // Retrieve and send data in json
        foreach ($slug_list as $sid) {
            $product = get_page_by_path($sid, OBJECT, 'product');
            if ($product && $product->post_status === 'publish') {
                $data['products'][] = $this->get_product_values(wc_get_product($product->ID));
            }
        }

        return $data;
    }

    /**
     * Find matching product and variation.
     *
     * @param WC_Product $product The variable product.
     * @param array $attributes The attributes to match.
     *
     * @return int The matching variation ID, or 0 if not found.
     */
    public function find_matching_variation(WC_Product $product, array $attributes): int
    {
        foreach ($attributes as $key => $value) {
            if (strpos($key, 'attribute_') === 0) {
                continue;
            }
            unset($attributes[$key]);
            $attributes[sprintf('attribute_%s', $key)] = $value;
        }
        if (class_exists('WC_Data_Store')) {
            $data_store = WC_Data_Store::load('product');

            return $data_store->find_matching_product_variation($product, $attributes);
        } else {
            return $product->get_matching_variation($attributes);
        }
    }

    /**
     * Prime WordPress caches for product images in one batch query.
     *
     * @param WC_Product[] $products Array of products to cache images for.
     *
     * @return void
     */
    private function fill_image_caches(array $products): void
    {
        $attachment_ids = [];
        foreach ($products as $product) {
            // Collect attachment IDs (featured image + gallery)
            $image_id = $product->get_image_id();
            if ($image_id) {
                $attachment_ids[] = $image_id;
            }
            $gallery_ids = $product->get_gallery_image_ids();
            if (!empty($gallery_ids)) {
                $attachment_ids = array_merge($attachment_ids, $gallery_ids);
            }
        }
        // Prime attachment post + meta cache in one batch query
        $attachment_ids = array_unique(array_filter($attachment_ids));
        if (!empty($attachment_ids)) {
            _prime_post_caches($attachment_ids, false, true);
        }
    }

    /**
     * Build metadata array for the response.
     *
     * @return array The metadata array.
     */
    public function build_metadata(): array
    {
        return [
            'wordpress_version' => SiteData::wordpress_version(),
            'php_version' => SiteData::php_version(),
            'plugin_version' => TOROB_PLUGIN_VERSION,
            'woocommerce_version' => SiteData::woocommerce_version(),
            'beta_test_plugin_version' => SiteData::beta_test_plugin_version(),
            'libsodium_version' => SiteData::libsodium_version(),
            'hpos_enabled' => Options::isHposEnabled(),
            'options' => $this->build_options_metadata()
        ];
    }

    /**
     * Build normalized plugin options metadata without exposing secret option values.
     *
     * @return array The plugin options metadata.
     */
    private function build_options_metadata(): array
    {
        return [
            'order_status_enabled' => Options::isOrderStatusEnabled(),
            'orders_list_api_enabled' => Options::isOrdersListApiEnabled(),
            'product_page_webhook_enabled' => Options::isProductPageWebhookEnabled(),
            'has_torob_token' => Options::getToken() !== '',
            'torob_token_set_at' => Options::getTokenSetAt()
        ];
    }
}
