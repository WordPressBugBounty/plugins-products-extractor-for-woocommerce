<?php

declare(strict_types=1);

namespace Torob\Admin;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Result of checking outbound connectivity to Torob.
 */
class TorobConnectivityCheckResult
{
    private bool $successful;
    private float $request_time_seconds;
    private ?int $http_status_code;
    private ?string $error_code;
    private ?string $error_message;

    private function __construct(
        bool $successful,
        float $request_time_seconds,
        ?int $http_status_code,
        ?string $error_code,
        ?string $error_message
    ) {
        $this->successful = $successful;
        $this->request_time_seconds = $request_time_seconds;
        $this->http_status_code = $http_status_code;
        $this->error_code = $error_code;
        $this->error_message = $error_message;
    }

    public static function success(float $request_time_seconds, int $http_status_code): self
    {
        return new self(true, $request_time_seconds, $http_status_code, null, null);
    }

    public static function http_failure(float $request_time_seconds, int $http_status_code): self
    {
        return new self(false, $request_time_seconds, $http_status_code, null, null);
    }

    public static function network_failure(float $request_time_seconds, string $error_code, string $error_message): self
    {
        return new self(false, $request_time_seconds, null, $error_code, $error_message);
    }

    public function is_successful(): bool
    {
        return $this->successful;
    }

    public function get_request_time_seconds(): float
    {
        return $this->request_time_seconds;
    }

    public function get_http_status_code(): ?int
    {
        return $this->http_status_code;
    }

    public function get_error_code(): ?string
    {
        return $this->error_code;
    }

    public function get_error_message(): ?string
    {
        return $this->error_message;
    }

    /**
     * Build visible diagnostic details for the admin notice.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function get_details_array(): array
    {
        $details = [];

        if (!$this->successful && $this->http_status_code !== null) {
            $details[] = [
                'label' => 'کد پاسخ',
                'value' => (string) $this->http_status_code
            ];
        }

        if ($this->error_code !== null && $this->error_code !== '') {
            $details[] = [
                'label' => 'کد خطا',
                'value' => $this->error_code
            ];
        }

        if ($this->error_message !== null && $this->error_message !== '') {
            $details[] = [
                'label' => 'پیام خطا',
                'value' => $this->error_message
            ];
        }

        $details[] = [
            'label' => 'مدت درخواست',
            'value' => $this->format_seconds($this->request_time_seconds) . ' ثانیه'
        ];

        return $details;
    }

    /**
     * Format elapsed time in seconds for admin diagnostics.
     */
    private function format_seconds(float $seconds): string
    {
        return number_format($seconds, 2, '.', '');
    }
}
