<?php

declare(strict_types=1);

namespace Torob\Utils;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use TorobDeps\Firebase\JWT\BeforeValidException;
use TorobDeps\Firebase\JWT\ExpiredException;
use TorobDeps\Firebase\JWT\JWT;
use TorobDeps\Firebase\JWT\Key;
use UnexpectedValueException;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Class Torob_Token_Validator
 *
 * Utility class for validating tokens with Torob's validation server
 */
class TorobTokenValidator
{
    private const TEHRAN_TIMEZONE = 'Asia/Tehran';

    /**
     * Torob token validation endpoint URL
     *
     * @var string
     */
    const VALIDATION_ENDPOINT = 'https://extractor.torob.com/validate_token/';

    /**
     * Torob's Ed25519 public key (base64-encoded, 32 bytes) for JWT signature verification.
     * Extracted from the DER-encoded public key: MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=
     */
    const TOROB_PUBLIC_KEY = 't6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=';

    /**
     * Error codes for standardized error responses
     */
    const ERROR_MISSING_TOKEN_DATA = 'missing_token';
    const ERROR_TOKEN_EXPIRED = 'token_expired';
    const ERROR_TOKEN_NBF = 'token_nbf';
    const ERROR_TOKEN_INVALID_AUD = 'token_invalid_aud';
    const ERROR_INVALID_TOKEN = 'invalid_token';
    const ERROR_VALIDATION_SERVICE_ERROR = 'validation_service_error';
    const ERROR_CONNECTION_FAILED = 'connection_failed';

    /**
     * Validate token from request. Suitable for use as a permission_callback.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function validate_token(WP_REST_Request $request)
    {
        $token = $this->sanitize_header_value($request->get_header('X-Torob-Token'));
        $token_version = $this->sanitize_header_value($request->get_header('X-Torob-Token-Version'));

        if ($token === null || $token === '') {
            return $this->create_error_response(
                self::ERROR_MISSING_TOKEN_DATA,
                'X-Torob-Token header is required',
                401
            );
        }

        if ($token_version === null || $token_version === '') {
            return $this->create_error_response(
                self::ERROR_MISSING_TOKEN_DATA,
                'X-Torob-Token-Version header is required',
                401
            );
        }

        if ($token_version === '1' && $this->is_jwt_supported()) {
            return $this->validate_jwt($token);
        }

        return $this->validate_token_with_request($token, $token_version);
    }

    /**
     * Validate a JWT token locally using Torob's public key.
     *
     * @param string $token The JWT token string.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function validate_jwt(string $token)
    {
        try {
            $decoded = JWT::decode($token, $this->get_pub_key());
        } catch (ExpiredException $e) {
            return $this->create_error_response(self::ERROR_TOKEN_EXPIRED, 'Token has expired', 401, [
                'current_server_time' => $this->get_tehran_server_time_data()
            ]);
        } catch (BeforeValidException $e) {
            return $this->create_error_response(self::ERROR_TOKEN_NBF, $e->getMessage(), 401, [
                'current_server_time' => $this->get_tehran_server_time_data()
            ]);
        } catch (UnexpectedValueException $e) {
            return $this->create_error_response(self::ERROR_INVALID_TOKEN, $e->getMessage(), 401);
        } catch (Exception $e) {
            return $this->create_error_response(self::ERROR_INVALID_TOKEN, 'JWT validation failed', 401);
        }

        $expected_aud = $this->get_expected_audience();
        $actual_aud = $decoded->aud ?? null;
        if (!is_string($actual_aud) || $actual_aud !== $expected_aud) {
            return $this->create_error_response(self::ERROR_TOKEN_INVALID_AUD, 'Invalid audience', 401);
        }

        return true;
    }

    /**
     * Get the expected audience value from the site URL.
     *
     * @return string The hostname (with port if non-standard) of the site.
     */
    private function get_expected_audience(): string
    {
        $site_url = wp_parse_url(get_site_url());
        $host = $site_url['host'] ?? '';
        $host = str_replace('www.', '', $host);

        if (($site_url['port'] ?? null) !== null) {
            $host .= ':' . $site_url['port'];
        }

        return $host;
    }

    /**
     * Build the Ed25519 public Key used for JWT signature verification.
     *
     * @return Key
     */
    protected function get_pub_key(): Key
    {
        return new Key(self::TOROB_PUBLIC_KEY, 'EdDSA');
    }

    /**
     * Check whether the runtime has everything needed for local JWT validation:
     * the sodium Ed25519 functions.
     *
     * @return bool
     */
    protected function is_jwt_supported(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Create a standardized WP_Error response for permission_callback
     *
     * @param string $error_code Machine-readable error code constant
     * @param string $message Human-readable error message
     * @param int $http_status HTTP status code (401, 500, etc.)
     * @param array<string, mixed> $additional_data Additional error data to merge into the response.
     *
     * @return WP_Error Error object with status in data
     */
    private function create_error_response(
        string $error_code,
        string $message,
        int $http_status,
        array $additional_data = []
    ): WP_Error {
        return new WP_Error($error_code, $message, array_merge(['status' => $http_status], $additional_data));
    }

    /**
     * Get the current server time data in Tehran timezone for JWT timing errors.
     *
     * @return string
     */
    private function get_tehran_server_time_data(): string
    {
        $server_time = new DateTimeImmutable('now', new DateTimeZone(self::TEHRAN_TIMEZONE));

        return $server_time->format(DATE_ATOM);
    }

    /**
     * Sanitize header values without mutating opaque tokens.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function sanitize_header_value($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }

    /**
     * @param string $token
     * @param string|null $token_version
     *
     * @return true|WP_Error
     */
    public function validate_token_with_request(string $token, ?string $token_version)
    {
        $shop_domain = $this->get_expected_audience();

        $body = [
            'token' => $token,
            'shop_domain' => $shop_domain
        ];

        if (is_string($token_version) && $token_version !== '') {
            $body['token_version'] = sanitize_text_field($token_version);
        }

        $response = wp_safe_remote_post(self::VALIDATION_ENDPOINT, [
            'method' => 'POST',
            'timeout' => 12,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking' => true,
            'body' => $body,
            'cookies' => []
        ]);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[Torob Plugin] Connection to extractor.torob.com failed: %s (%s)',
                $response->get_error_message(),
                $response->get_error_code()
            ));

            return $this->create_error_response(
                self::ERROR_CONNECTION_FAILED,
                'Could not connect to the Torob validation service',
                500
            );
        }

        $http_status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($http_status_code >= 500 && $http_status_code < 600) {
            error_log(sprintf(
                '[Torob Plugin] Upstream validation server returned %d status. Response: %s',
                $http_status_code,
                $response_body
            ));

            return $this->create_error_response(
                self::ERROR_VALIDATION_SERVICE_ERROR,
                'Torob validation service returned a server error',
                500
            );
        }

        $validation_result = json_decode($response_body, true);
        $is_valid = ($validation_result['success'] ?? false) === true;

        if (!$is_valid) {
            $error_message = $validation_result['error']['message'] ?? 'The provided Torob token is invalid';

            return $this->create_error_response(self::ERROR_INVALID_TOKEN, $error_message, 401);
        }

        return true;
    }
}
