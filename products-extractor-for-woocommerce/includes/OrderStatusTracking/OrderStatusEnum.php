<?php

declare(strict_types=1);

namespace Torob\OrderStatusTracking;

/**
 * Order Status Enum
 *
 * Defines WooCommerce order status constants without the 'wc-' prefix
 * These match the values returned by WC_Order::get_status()
 *
 * @package Torob_WCPE
 */
class OrderStatusEnum
{
    const PENDING = 'pending';
    const ON_HOLD = 'on-hold';
    const PROCESSING = 'processing';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
    const REFUNDED = 'refunded';
    const FAILED = 'failed';
}
