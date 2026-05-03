<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Result of sending product page webhook items to Torob.
 */
class WebhookSendResult
{
    private bool $success;
    private int $status_code;

    public function __construct(bool $success, int $status_code)
    {
        $this->success = $success;
        $this->status_code = $status_code;
    }

    public function is_success(): bool
    {
        return $this->success;
    }

    public function get_status_code(): int
    {
        return $this->status_code;
    }
}
