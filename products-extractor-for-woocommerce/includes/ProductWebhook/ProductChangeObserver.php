<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use Torob\Utils\ProductExtractionUtils;
use WC_Product;
use WC_Product_Variation;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles WooCommerce product change hooks and decides which product pages to queue.
 */
class ProductChangeObserver
{
    const WATCHED_PROPS = [
        'name',
        'slug',
        'regular_price',
        'sale_price',
        'price',
        'sku',
        'stock_status',
        'date_on_sale_from',
        'date_on_sale_to',
        'short_description',
        'image_id',
        'gallery_image_ids',
        'attributes',
        'default_attributes',
        'category_ids'
    ];

    const WATCHED_META_KEYS = [
        'product_english_name',
        '_thumbnail_id',
        '_product_image_gallery',
        '_regular_price',
        '_sale_price',
        '_price',
        '_sku',
        '_stock_status',
        '_sale_price_dates_from',
        '_sale_price_dates_to',
        '_default_attributes'
    ];

    private QueueServices $queue_services;

    public function __construct(QueueServices $queue_services)
    {
        $this->queue_services = $queue_services;
    }

    /**
     * Register WooCommerce product update hooks.
     */
    public function register_product_hooks(): void
    {
        if (!Options::isProductPageWebhookReady()) {
            return;
        }

        add_action('woocommerce_product_object_updated_props', [$this, 'on_product_updated_props'], 10, 2);
        add_action('updated_post_meta', [$this, 'on_product_meta_updated'], 10, 4);
        add_action('added_post_meta', [$this, 'on_product_meta_updated'], 10, 4);
        add_action('deleted_post_meta', [$this, 'on_product_meta_updated'], 10, 4);
        add_action('post_updated', [$this, 'on_product_post_updated'], 10, 3);
        add_action('transition_post_status', [$this, 'on_product_status_transition'], 10, 3);
        add_action('set_object_terms', [$this, 'on_product_terms_set'], 10, 6);
        add_action('wp_trash_post', [$this, 'on_product_trash'], 10, 1);
        add_filter('pre_delete_post', [$this, 'on_pre_delete_post'], 10, 3);
    }

    /**
     * Handle product property updates - queue if a watched prop changed.
     *
     * WooCommerce fires this hook before apply_changes(), so get_changes()
     * still contains pending changes on the product object. We combine that
     * with $updated_props because different WooCommerce save paths expose
     * meaningful changes through one or both sources.
     *
     * @param \WC_Product $product       The product object.
     * @param array       $updated_props List of updated property names (meta-based only).
     */
    public function on_product_updated_props(WC_Product $product, array $updated_props): void
    {
        $changes = $product->get_changes();
        $changed_props = array_unique(array_merge($updated_props, array_keys($changes)));
        $watched_changed_props = array_intersect(self::WATCHED_PROPS, $changed_props);

        if (
            in_array('attributes', $watched_changed_props, true)
            && !$this->has_meaningful_attribute_change($product, $changes)
        ) {
            $watched_changed_props = array_diff($watched_changed_props, ['attributes']);
        }

        if (empty($watched_changed_props)) {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_for_product($product));
    }

    /**
     * Handle post meta updates - queue product when watched product meta changes.
     *
     * @param mixed  $meta_id    ID of the metadata entry or deleted meta ID list.
     * @param int    $object_id  Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public function on_product_meta_updated($meta_id, int $object_id, string $meta_key, $meta_value): void
    {
        if (!in_array($meta_key, self::WATCHED_META_KEYS, true)) {
            return;
        }

        $source_post = get_post($object_id);
        // Trashing is handled by wp_trash_post while the original permalink is still available.
        // Ignore later meta hooks for trashed posts so they do not queue stale update payloads.
        if ($source_post instanceof \WP_Post && 'trash' === $source_post->post_status) {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_for_post_id($object_id));
    }

    /**
     * Queue product when watched post-backed fields change.
     *
     * @param int      $post_id     Post ID.
     * @param \WP_Post $post_after  Post object after update.
     * @param \WP_Post $post_before Post object before update.
     */
    public function on_product_post_updated(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
    {
        if ($post_after->post_type !== 'product' || 'trash' === $post_after->post_status) {
            // Trashing is handled by wp_trash_post before WordPress mutates the post slug.
            // Ignore the follow-up post_updated event to avoid replacing that removal payload.
            return;
        }

        if ('publish' === $post_before->post_status && 'publish' !== $post_after->post_status) {
            $this->queue_services->queue_webhook_item($this->build_removal_for_product_post($post_before));

            return;
        }

        $changed_fields = [];

        if ($post_after->post_title !== $post_before->post_title) {
            $changed_fields[] = 'name';
        }

        if ($post_after->post_name !== $post_before->post_name) {
            $changed_fields[] = 'slug';
        }

        if ($post_after->post_excerpt !== $post_before->post_excerpt) {
            $changed_fields[] = 'short_description';
        }

        if (empty($changed_fields)) {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_for_post_id($post_id));
    }

    /**
     * Queue publish state transitions so Torob can add or remove product pages deliberately.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param \WP_Post $post       Post object.
     */
    public function on_product_status_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status === $old_status) {
            return;
        }

        if ('publish' !== $old_status && 'publish' !== $new_status) {
            return;
        }

        if ($post->post_type === 'product_variation') {
            $variation = wc_get_product($post->ID);
            if ($variation instanceof WC_Product_Variation) {
                $this->queue_services->queue_webhook_item($this->build_for_product($variation));
            }

            return;
        }

        if ($post->post_type !== 'product') {
            return;
        }

        if ('publish' !== $new_status) {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_for_post_id($post->ID));
    }

    /**
     * Queue product when its category terms are changed outside WooCommerce product saves.
     *
     * @param int    $object_id  Object ID.
     * @param mixed  $terms      Terms assigned by wp_set_object_terms().
     * @param array  $tt_ids     Current term taxonomy IDs assigned by the call.
     * @param string $taxonomy   Taxonomy slug.
     * @param bool   $append     Whether terms were appended.
     * @param array  $old_tt_ids Previous term taxonomy IDs.
     */
    // @mago-expect lint:excessive-parameter-list
    public function on_product_terms_set(
        int $object_id,
        $terms,
        array $tt_ids,
        string $taxonomy,
        bool $append,
        array $old_tt_ids
    ): void {
        if ($taxonomy !== 'product_cat') {
            return;
        }

        if ($this->normalize_term_taxonomy_ids($tt_ids) === $this->normalize_term_taxonomy_ids($old_tt_ids)) {
            return;
        }

        $post = get_post($object_id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'product') {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_for_post_id($object_id));
    }

    /**
     * Capture the product permalink before WordPress mutates the slug on trash.
     *
     * The wp_trash_post action fires before the post status and slug are changed,
     * so get_permalink() still returns the correct pretty URL.
     *
     * @param int $post_id Post ID being trashed.
     */
    public function on_product_trash(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || 'publish' !== $post->post_status) {
            return;
        }

        $this->queue_services->queue_webhook_item($this->build_removal_for_product_post($post));
    }

    /**
     * Capture product or variation data before permanent deletion.
     *
     * For products: queue the product ID together with its current permalink so Torob
     * can remove the exact deleted page later.
     * For variations: queue the parent product so Torob gets refreshed pricing data.
     *
     * @param mixed         $delete       Short-circuit value from WordPress.
     * @param \WP_Post|null $post         Post object being deleted.
     * @param bool          $force_delete Whether the delete is forced.
     *
     * @return mixed
     */
    public function on_pre_delete_post($delete, ?\WP_Post $post, bool $force_delete)
    {
        if (!$post instanceof \WP_Post) {
            return $delete;
        }

        $post_type = $post->post_type;
        if ($post_type === 'product') {
            if ('trash' === $post->post_status) {
                // The trash transition already queued the pre-trash payload snapshot.
                // Avoid replacing its URL with the trashed permalink.

                return $delete;
            }

            $this->queue_services->queue_webhook_item($this->build_removal_for_product_post($post));

            return $delete;
        }

        if ($post_type === 'product_variation') {
            $variation = wc_get_product($post->ID);
            if ($variation instanceof WC_Product_Variation) {
                $this->queue_services->queue_webhook_item($this->build_for_product($variation));
            }

            return $delete;
        }

        return $delete;
    }

    /**
     * Build an item for the product page represented by a WooCommerce product object.
     */
    public function build_for_product(WC_Product $product, ?string $page_url = null): ?WebhookItem
    {
        $page_product = $product;
        if ($page_product instanceof WC_Product_Variation) {
            $parent = wc_get_product($page_product->get_parent_id());
            if (!$parent instanceof WC_Product) {
                return null;
            }

            $page_product = $parent;
        }

        $resolved_page_url = $page_url;
        if ($resolved_page_url === null) {
            $post = get_post($page_product->get_id());
            if (!$post instanceof \WP_Post || !$this->is_public_product_post($post)) {
                return null;
            }

            $resolved_page_url = ProductExtractionUtils::get_page_url($page_product);
        }

        return new WebhookItem((string) ProductExtractionUtils::get_page_unique($page_product), $resolved_page_url);
    }

    /**
     * Build an item for the product page represented by a product or variation post ID.
     */
    public function build_for_post_id(int $post_id): ?WebhookItem
    {
        $product = wc_get_product($post_id);
        if (!$product instanceof WC_Product) {
            return null;
        }

        return $this->build_for_product($product);
    }

    /**
     * Build a product removal item from a product post snapshot.
     */
    public function build_removal_for_product_post(\WP_Post $post): ?WebhookItem
    {
        if (!$this->is_public_product_post($post)) {
            return null;
        }

        $product = wc_get_product($post->ID);
        if (!$product instanceof WC_Product || $product instanceof WC_Product_Variation) {
            return null;
        }

        $page_url = get_permalink($post);
        if (!is_string($page_url) || $page_url === '') {
            $page_url = ProductExtractionUtils::get_page_url($product);
        }

        return $this->build_for_product($product, $page_url);
    }

    /**
     * Determine whether the queued attributes change is semantically meaningful.
     *
     * WooCommerce may populate attributes in get_changes() on admin saves even
     * when the saved attribute data is effectively unchanged.
     *
     * @param \WC_Product $product Product object before apply_changes().
     * @param array       $changes Pending product changes.
     *
     * @return bool
     */
    private function has_meaningful_attribute_change(WC_Product $product, array $changes): bool
    {
        if (!array_key_exists('attributes', $changes)) {
            return false;
        }

        $original_data = $product->get_data();
        $original_attributes = $original_data['attributes'] ?? [];
        $changed_attributes = $changes['attributes'];

        return (
            $this->normalize_product_attributes($original_attributes) !== $this->normalize_product_attributes(
                $changed_attributes
            )
        );
    }

    /**
     * Normalize product attributes for stable comparisons.
     *
     * @param array $attributes Raw product attributes keyed by taxonomy/name.
     *
     * @return array
     */
    private function normalize_product_attributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute) {
                $normalized[$key] = [
                    'id' => (int) $attribute->get_id(),
                    'name' => (string) $attribute->get_name(),
                    'options' => array_map('strval', $attribute->get_options()),
                    'position' => (int) $attribute->get_position(),
                    'visible' => (bool) $attribute->get_visible(),
                    'variation' => (bool) $attribute->get_variation()
                ];
                continue;
            }

            $normalized[$key] = $attribute;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Normalize term taxonomy IDs for stable comparisons.
     *
     * @param array $term_taxonomy_ids Raw term taxonomy IDs.
     *
     * @return int[]
     */
    private function normalize_term_taxonomy_ids(array $term_taxonomy_ids): array
    {
        $normalized = array_map('intval', $term_taxonomy_ids);
        sort($normalized);

        return $normalized;
    }

    /**
     * Check whether a product post is publicly viewable.
     */
    private function is_public_product_post(\WP_Post $post): bool
    {
        return $post->post_type === 'product' && is_post_publicly_viewable($post);
    }
}
