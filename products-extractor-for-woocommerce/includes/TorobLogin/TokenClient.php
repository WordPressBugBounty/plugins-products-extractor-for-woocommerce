<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exchanges the login code at Torob's token endpoint and checks the ID token it returns.
 *
 * The ID token comes straight from Torob over TLS in this server-to-server call, so its signature is not
 * checked (OpenID Connect Core 3.1.3.7, rule 6) and the plugin needs no OpenSSL. Its issuer, audience,
 * expiry and nonce are checked. The access token is not kept.
 */
final class TokenClient
{
    private const TIMEOUT_SECONDS = 10;
    private const CLOCK_SKEW_SECONDS = 120;

    /**
     * Trade the code for the buyer's claims.
     *
     * @return array<string, mixed>|WP_Error The ID token claims (`sub`, and `phone_number`, names and `email`
     *                                       when Torob shares them).
     */
    public function exchange_code(string $code, LoginTransaction $transaction)
    {
        $response = wp_safe_remote_post(Settings::TOKEN_ENDPOINT, [
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => Settings::redirect_uri(),
                'client_id' => Settings::client_id(),
                'code_verifier' => $transaction->code_verifier
            ]
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('torob_login_token_unreachable', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($body) || !is_string($body['id_token'] ?? null)) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unknown';

            return new WP_Error('torob_login_token_rejected', sprintf(
                'Torob token endpoint answered %d (%s)',
                $status,
                $error
            ));
        }

        return $this->read_id_token($body['id_token'], $transaction->nonce);
    }

    /**
     * Decode the ID token and check the claims that tie it to this shop and this attempt.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function read_id_token(string $id_token, string $expected_nonce)
    {
        $parts = explode('.', $id_token);
        $payload = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($claims)) {
            return new WP_Error('torob_login_id_token_invalid', 'The ID token is not a JWT');
        }

        $problem = $this->find_claim_problem($claims, $expected_nonce);
        if ($problem !== null) {
            return new WP_Error('torob_login_id_token_invalid', $problem);
        }

        return $claims;
    }

    /**
     * @param array<array-key, mixed> $claims
     */
    private function find_claim_problem(array $claims, string $expected_nonce): ?string
    {
        if (($claims['iss'] ?? null) !== Settings::ISSUER) {
            return 'Unexpected issuer';
        }

        if (($claims['aud'] ?? null) !== Settings::client_id()) {
            return 'Unexpected audience';
        }

        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < (time() - self::CLOCK_SKEW_SECONDS)) {
            return 'Expired';
        }

        if (!is_string($claims['nonce'] ?? null) || !hash_equals($expected_nonce, $claims['nonce'])) {
            return 'Nonce mismatch';
        }

        // Torob's user id, which accounts are linked by.
        if (!is_string($claims['sub'] ?? null) || !preg_match('/^\d{1,20}$/', $claims['sub'])) {
            return 'Invalid subject';
        }

        return null;
    }
}
