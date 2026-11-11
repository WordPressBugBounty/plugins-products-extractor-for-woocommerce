<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The browser-facing Torob login routes: start sends the buyer to Torob and callback logs them in. Torob's own
 * server calls one more: the client route, where it sends the shop's client id.
 *
 * Errors send the buyer back with a `torob_login_error` code that LoginButton turns into a message, so
 * the shop's own login form is always there as the fallback.
 */
final class Controller
{
    public const ERROR_PARAM = 'torob_login_error';
    public const ERROR_EXPIRED = 'expired';
    public const ERROR_CANCELLED = 'cancelled';
    public const ERROR_UNAVAILABLE = 'unavailable';
    public const ERROR_STAFF_ACCOUNT = 'staff_account';
    public const ERROR_FAILED = 'failed';

    private TokenClient $token_client;
    private AccountService $accounts;

    public function __construct(TokenClient $token_client, AccountService $accounts)
    {
        $this->token_client = $token_client;
        $this->accounts = $accounts;
    }

    public function register_routes(TorobTokenValidator $validator): void
    {
        register_rest_route(Settings::REST_NAMESPACE, Settings::CLIENT_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'receive_client'],
            'permission_callback' => [$validator, 'validate_token'],
            'args' => [
                'client_id' => [
                    'required' => true,
                    'type' => 'string'
                ]
            ]
        ]);

        $routes = [
            Settings::START_ROUTE => ['start', ['redirect_to']],
            Settings::CALLBACK_ROUTE => ['finish', ['state', 'code', 'error']]
        ];
        foreach ($routes as $route => [$method, $params]) {
            register_rest_route(Settings::REST_NAMESPACE, $route, [
                'methods' => 'GET',
                'callback' => [$this, $method],
                'permission_callback' => '__return_true',
                // Every parameter is an optional string, so a missing one reads as '' rather than a REST error.
                'args' => array_fill_keys($params, ['type' => 'string', 'default' => ''])
            ]);
        }
    }

    /**
     * Torob sends the shop's client id here, signed like the webhook token, once the owner keeps login on and
     * Torob has created the client.
     */
    public function receive_client(WP_REST_Request $request): WP_REST_Response
    {
        if (!Options::isTorobLoginEnabled()) {
            return new WP_REST_Response(['error' => 'Torob login is switched off'], 409);
        }

        $client_id = TorobTokenValidator::sanitize_opaque_token_value($request->get_param('client_id')) ?? '';
        if ($client_id === '') {
            return new WP_REST_Response(['error' => 'client_id parameter is required'], 400);
        }

        Options::setTorobLoginClient($client_id, Settings::redirect_uri());

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Start a login attempt and send the buyer to Torob.
     */
    public function start(WP_REST_Request $request): WP_REST_Response
    {
        $redirect_to = self::safe_redirect_target($request->get_param('redirect_to'));
        if (!Settings::is_available()) {
            return self::redirect_with_error($redirect_to, self::ERROR_UNAVAILABLE);
        }

        return self::redirect(Settings::authorize_url(LoginTransaction::begin($redirect_to)));
    }

    /**
     * Torob sends the buyer back here with a code, or with an error when they cancelled or login is off.
     */
    public function finish(WP_REST_Request $request): WP_REST_Response
    {
        $transaction = LoginTransaction::consume($request->get_param('state'));
        if ($transaction === null) {
            return self::redirect_with_error(self::default_redirect_target(), self::ERROR_EXPIRED);
        }

        if (!Settings::is_available()) {
            return self::redirect_with_error($transaction->redirect_to, self::ERROR_UNAVAILABLE);
        }

        $error = $request->get_param('error');
        if ($error !== '') {
            if ($error !== 'access_denied') {
                self::log_failure(
                    new WP_Error('torob_login_authorize_failed', 'Torob answered ' . sanitize_key($error))
                );
            }

            return self::redirect_with_error($transaction->redirect_to, self::error_from_torob($error));
        }

        $code = $request->get_param('code');
        if ($code === '') {
            return self::redirect_with_error($transaction->redirect_to, self::ERROR_FAILED);
        }

        $claims = $this->token_client->exchange_code($code, $transaction);
        if (is_wp_error($claims)) {
            self::log_failure($claims);

            return self::redirect_with_error($transaction->redirect_to, self::ERROR_FAILED);
        }

        return $this->log_in_or_fail($this->accounts->find_or_create_user($claims), $transaction->redirect_to);
    }

    /**
     * A same-site address to return to; anything else falls back to the My Account page.
     */
    private static function safe_redirect_target(string $redirect_to): string
    {
        $default = self::default_redirect_target();
        if ($redirect_to === '') {
            return $default;
        }

        return remove_query_arg(self::ERROR_PARAM, wp_validate_redirect($redirect_to, $default));
    }

    /**
     * @param WP_User|WP_Error $user
     */
    private function log_in_or_fail($user, string $redirect_to): WP_REST_Response
    {
        if (is_wp_error($user)) {
            self::log_failure($user);

            return self::redirect_with_error($redirect_to, self::error_from_account($user));
        }

        $this->accounts->log_in($user);

        return self::redirect($redirect_to);
    }

    private static function default_redirect_target(): string
    {
        return wc_get_page_permalink('myaccount');
    }

    private static function error_from_torob(string $error): string
    {
        switch ($error) {
            case 'access_denied':
                return self::ERROR_CANCELLED;
            case 'temporarily_unavailable':
                return self::ERROR_UNAVAILABLE;
            default:
                return self::ERROR_FAILED;
        }
    }

    private static function error_from_account(WP_Error $error): string
    {
        switch ($error->get_error_code()) {
            case 'torob_login_staff_account':
                return self::ERROR_STAFF_ACCOUNT;
            default:
                return self::ERROR_FAILED;
        }
    }

    private static function redirect_with_error(string $url, string $error_code): WP_REST_Response
    {
        return self::redirect(add_query_arg(self::ERROR_PARAM, $error_code, $url));
    }

    /**
     * Every login route answers one visitor. Page caches that keep REST answers too, like LiteSpeed Cache by
     * default, skip Cache-Control but not DONOTCACHEPAGE; a cached start would hand every later visitor the first
     * one's login attempt without its cookie, so they all came back to «expired».
     */
    private static function redirect(string $url): WP_REST_Response
    {
        wc_maybe_define_constant('DONOTCACHEPAGE', true);
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', wp_sanitize_redirect($url));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private static function log_failure(WP_Error $error): void
    {
        error_log(sprintf(
            '[Torob Plugin] Torob login failed: %s (%s)',
            $error->get_error_message(),
            $error->get_error_code()
        ));
    }
}
