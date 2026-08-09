<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles the product webhook REST API boundary.
 */
final class Controller
{
    private const TOKEN_ROUTE = '/set-token';

    private QueueRunner $queue_runner;

    public function __construct(QueueRunner $queue_runner)
    {
        $this->queue_runner = $queue_runner;
    }

    /**
     * Register product webhook REST endpoints.
     */
    public function register_routes(TorobTokenValidator $validator): void
    {
        register_rest_route(QueueRunner::REST_NAMESPACE, self::TOKEN_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'receive_webhook_token'],
            'permission_callback' => [$validator, 'validate_token'],
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string'
                ]
            ]
        ]);

        register_rest_route(QueueRunner::REST_NAMESPACE, QueueRunner::REST_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'run_product_webhook_queue'],
            'permission_callback' => [$this, 'authorize_queue_runner_request'],
            'args' => [
                QueueRunner::TOKEN_PARAMETER => [
                    'required' => true,
                    'type' => 'string'
                ]
            ]
        ]);
    }

    /**
     * Accept and store the product page webhook token sent by Torob.
     */
    public function receive_webhook_token(WP_REST_Request $request): WP_REST_Response
    {
        if (!Options::isProductPageWebhookEnabled()) {
            return new WP_REST_Response(['error' => 'product page webhook is disabled'], 409);
        }

        $token = TorobTokenValidator::sanitize_opaque_token_value($request->get_param('token')) ?? '';

        if ($token === '') {
            return new WP_REST_Response(['error' => 'token parameter is required'], 400);
        }

        Options::setToken($token);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Verify that a fallback request presents the current runner lock token.
     *
     * @return true|WP_Error
     */
    public function authorize_queue_runner_request(WP_REST_Request $request)
    {
        if (!$this->queue_runner->is_fallback_active()) {
            return new WP_Error(
                QueueRunner::FALLBACK_INACTIVE_ERROR,
                'The product webhook runner fallback is not active',
                ['status' => 404]
            );
        }

        $lock_token = $this->get_runner_token($request);
        if ($this->queue_runner->is_runner_token_valid($lock_token)) {
            return true;
        }

        return new WP_Error(QueueRunner::INVALID_TOKEN_ERROR, 'A valid product webhook runner token is required', [
            'status' => 401
        ]);
    }

    /**
     * Process one authenticated fallback request.
     */
    public function run_product_webhook_queue(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->queue_runner->run_fallback($this->get_runner_token($request));

        if (!is_wp_error($result)) {
            return new WP_REST_Response(['success' => true, 'processed' => $result], 200);
        }

        return new WP_REST_Response(['error' => $result->get_error_message()], $this->get_runner_error_status($result));
    }

    /**
     * Read the opaque one-time runner token without text-field mutation.
     */
    private function get_runner_token(WP_REST_Request $request): string
    {
        $lock_token = $request->get_param(QueueRunner::TOKEN_PARAMETER);

        return is_string($lock_token) ? trim($lock_token) : '';
    }

    /**
     * Translate queue runner failures into endpoint response statuses.
     */
    private function get_runner_error_status(WP_Error $error): int
    {
        switch ($error->get_error_code()) {
            case QueueRunner::FALLBACK_INACTIVE_ERROR:
                return 404;
            case QueueRunner::INVALID_TOKEN_ERROR:
                return 401;
            case QueueRunner::WEBHOOK_NOT_READY_ERROR:
                return 409;
            default:
                return 500;
        }
    }
}
