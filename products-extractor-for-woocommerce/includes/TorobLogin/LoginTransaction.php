<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * One Torob login attempt: the state, nonce and PKCE verifier, kept on the shop's server for ten minutes.
 *
 * The attempt is bound to the browser that started it through an HttpOnly cookie, so a callback carrying
 * someone else's state cannot log a buyer in (login CSRF). Each state can be used once.
 */
final class LoginTransaction
{
    public const BROWSER_COOKIE = 'torob_login_browser';
    public const LIFETIME = 600;
    private const TRANSIENT_PREFIX = 'torob_login_';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public string $state;
    public string $nonce;
    public string $code_verifier;
    public string $redirect_to;

    public function __construct(string $state, string $nonce, string $code_verifier, string $redirect_to)
    {
        $this->state = $state;
        $this->nonce = $nonce;
        $this->code_verifier = $code_verifier;
        $this->redirect_to = $redirect_to;
    }

    /**
     * Start an attempt for this browser and remember it until the buyer comes back.
     *
     * @param string $redirect_to Where to send the buyer after logging in.
     */
    public static function begin(string $redirect_to): self
    {
        $browser_key = self::bind_browser();

        $transaction = new self(self::random_token(), self::random_token(), self::random_token(), $redirect_to);
        set_transient(
            self::transient_key($transaction->state),
            [
                'browser' => hash('sha256', $browser_key),
                'nonce' => $transaction->nonce,
                'code_verifier' => $transaction->code_verifier,
                'redirect_to' => $redirect_to
            ],
            self::LIFETIME
        );

        return $transaction;
    }

    /**
     * This browser's key, kept in its cookie for another ten minutes; a new one when it has none.
     */
    public static function bind_browser(): string
    {
        $browser_key = self::browser_key() ?? self::random_token();
        wc_setcookie(self::BROWSER_COOKIE, $browser_key, time() + self::LIFETIME, is_ssl(), true);

        return $browser_key;
    }

    /**
     * 32 random bytes, base64url-encoded to 43 characters (a valid RFC 7636 verifier).
     */
    public static function random_token(): string
    {
        return self::base64url(random_bytes(32));
    }

    /**
     * The browser key from this request's cookie, reused so attempts in several tabs all stay valid.
     */
    public static function browser_key(): ?string
    {
        $value = $_COOKIE[self::BROWSER_COOKIE] ?? null;
        if (!is_string($value) || !preg_match(self::TOKEN_PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Take the attempt for `$state` out of storage.
     *
     * @return self|null The attempt, or null when the state is unknown, expired, used, or from another browser.
     */
    public static function consume(string $state): ?self
    {
        $key = self::transient_key($state);
        $stored = get_transient($key);
        $browser_key = self::browser_key();
        if (
            !is_array($stored)
            || $browser_key === null
            || !hash_equals($stored['browser'], hash('sha256', $browser_key))
        ) {
            return null;
        }

        // Only the request that deletes the attempt may use it: two callbacks racing with the same state both
        // read it, but only one delete succeeds.
        if (!delete_transient($key)) {
            return null;
        }

        return new self($state, $stored['nonce'], $stored['code_verifier'], $stored['redirect_to']);
    }

    /**
     * The PKCE `S256` challenge: base64url(SHA-256(verifier)), as RFC 7636 defines it.
     */
    public function code_challenge(): string
    {
        return self::base64url(hash('sha256', $this->code_verifier, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function transient_key(string $state): string
    {
        return self::TRANSIENT_PREFIX . hash('sha256', $state);
    }
}
