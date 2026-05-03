<?php

declare(strict_types=1);

namespace Torob\ProductWebhook;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles product page webhook token REST API requests.
 */
class TokenController
{
    /**
     * Register the REST endpoint for receiving the webhook token from Torob.
     */
    public function register_webhook_token_route(TorobTokenValidator $validator): void
    {
        register_rest_route('torob-api/v1', '/set-token', [
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
}
