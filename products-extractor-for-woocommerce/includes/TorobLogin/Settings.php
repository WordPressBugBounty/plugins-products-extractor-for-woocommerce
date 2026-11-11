<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Torob\Utils\Options;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Where Torob login talks to Torob: its endpoints, the plugin's own routes and their URLs.
 */
final class Settings
{
    public const ISSUER = 'https://api.torob.com/oauth2';
    public const AUTHORIZE_ENDPOINT = self::ISSUER . '/authorize/';
    public const TOKEN_ENDPOINT = self::ISSUER . '/token/';
    public const SCOPES = 'openid phone profile email';
    public const REST_NAMESPACE = 'torob-api/v1';
    public const START_ROUTE = '/login/start';
    public const CALLBACK_ROUTE = '/login/callback';
    /** Where Torob sends the shop's client id once it has created the client, like `/set-token`. */
    public const CLIENT_ROUTE = '/set-login-client';

    /**
     * Prevent instantiation of a static utility.
     */
    private function __construct() {}

    /**
     * Whether buyers can log in with Torob on this site: the owner keeps it on and the shop has its client.
     */
    public static function is_available(): bool
    {
        return Options::isTorobLoginEnabled() && self::client_id() !== '';
    }

    /**
     * The shop's client id, or '' until Torob sends one for the current callback. Torob accepts only the
     * callbacks it registered, so after a permalink, subfolder or domain change the plugin reports no client
     * and login waits until Torob registers the new callback and sends the client again.
     */
    public static function client_id(): string
    {
        return Options::getTorobLoginClientRedirectUri() === self::redirect_uri()
            ? Options::getTorobLoginClientId()
            : '';
    }

    /**
     * The exact callback URL Torob sends buyers back to. Torob only accepts https addresses and compares
     * them exactly, so this must match the address registered for the shop's client.
     */
    public static function redirect_uri(): string
    {
        return rest_url(self::REST_NAMESPACE . self::CALLBACK_ROUTE, 'https');
    }

    /**
     * The URL the login button links to.
     *
     * @param string $redirect_to Where to send the buyer after logging in; empty means My Account.
     */
    public static function start_url(string $redirect_to): string
    {
        $start_url = rest_url(self::REST_NAMESPACE . self::START_ROUTE);
        if ($redirect_to === '') {
            return $start_url;
        }

        return add_query_arg('redirect_to', rawurlencode($redirect_to), $start_url);
    }

    /**
     * Torob's authorization URL for one login attempt.
     */
    public static function authorize_url(LoginTransaction $transaction): string
    {
        return add_query_arg(array_map('rawurlencode', [
            'response_type' => 'code',
            'client_id' => self::client_id(),
            'redirect_uri' => self::redirect_uri(),
            'scope' => self::SCOPES,
            'state' => $transaction->state,
            'nonce' => $transaction->nonce,
            'code_challenge' => $transaction->code_challenge(),
            'code_challenge_method' => 'S256'
        ]), self::AUTHORIZE_ENDPOINT);
    }
}
