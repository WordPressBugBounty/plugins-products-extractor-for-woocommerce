<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Torob\Utils\Options;
use Torob\Utils\TorobTokenValidator;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Wires Torob login into WordPress: the button and messages on login pages, the routes, and the account pages.
 *
 * Buyers can log in once Torob has set up the shop's client and while the owner keeps login on (see
 * Settings::is_available()). Switched off, nothing is hooked into the shop except the shortcode and the
 * Elementor widget, which then print nothing; the routes stay and refuse.
 */
final class LoginHandler
{
    private Controller $controller;
    private LoginButton $button;
    private AccountService $accounts;

    public function __construct()
    {
        $this->accounts = new AccountService();
        $this->controller = new Controller(new TokenClient(), $this->accounts);
        $this->button = new LoginButton();
    }

    public function register_hooks(): void
    {
        $this->button->register_placements();
        Options::initializeTorobLogin();
        if (!Options::isTorobLoginEnabled()) {
            return;
        }

        $this->button->register_hooks();
        add_action('template_redirect', [$this, 'save_names']);
        add_action('woocommerce_checkout_update_customer', [$this->accounts, 'name_customer_after_checkout']);
        add_filter('woocommerce_save_account_details_required_fields', [
            $this->accounts,
            'email_optional_for_torob_accounts'
        ]);
        // Before WooCommerce saves the account details form, at the default priority.
        add_action('template_redirect', [$this, 'allow_first_password'], 9);
        add_action('woocommerce_save_account_details', [$this, 'forget_no_own_password_after_save']);
        add_action('after_password_reset', [$this, 'forget_no_own_password_after_reset']);
    }

    /**
     * WooCommerce's account details form only changes a password together with the current one, which a buyer
     * who signed up with Torob never had. When such a buyer sends a valid form with a new password twice and no
     * current one, the account gets a fresh random password that is filled in as the current one, so
     * WooCommerce's own checks pass and it saves the new password. The login cookie is renewed right away, so
     * the buyer stays logged in even if the form fails for another reason.
     */
    public function allow_first_password(): void
    {
        $user = wp_get_current_user();
        if (($_POST['action'] ?? null) !== 'save_account_details' || !AccountService::has_no_own_password($user)) {
            return;
        }

        $nonce = $_POST['save-account-details-nonce'] ?? '';
        $current = $_POST['password_current'] ?? '';
        $new = $_POST['password_1'] ?? '';
        $repeated = $_POST['password_2'] ?? '';
        if (
            !is_string($nonce)
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'save_account_details')
            || $current !== ''
            || !is_string($new)
            || $new === ''
            || $new !== $repeated
        ) {
            return;
        }

        // Letters and digits only: older WordPress trims a password when hashing it but not when checking it, so
        // a space at either end would make WooCommerce reject it as the current password.
        $temporary = wp_generate_password(32, false);
        wp_set_password($temporary, $user->ID);
        wp_set_auth_cookie($user->ID, true);
        $_POST['password_current'] = $temporary;
    }

    /**
     * @param mixed $user_id
     */
    public function forget_no_own_password_after_save($user_id): void
    {
        $new = $_POST['password_1'] ?? '';
        if (is_numeric($user_id) && is_string($new) && $new !== '') {
            AccountService::forget_no_own_password((int) $user_id);
        }
    }

    /**
     * @param mixed $user
     */
    public function forget_no_own_password_after_reset($user): void
    {
        if ($user instanceof WP_User) {
            AccountService::forget_no_own_password($user->ID);
        }
    }

    /**
     * The name form in My Account's Torob section: save and reload the page.
     */
    public function save_names(): void
    {
        if ($this->save_names_from_request()) {
            wp_safe_redirect(remove_query_arg(Controller::ERROR_PARAM));
            exit();
        }
    }

    /**
     * Save the first and last name the logged-in buyer sent from the name form, with a notice either way.
     *
     * @return bool Whether the names were saved.
     */
    public function save_names_from_request(): bool
    {
        if (($_POST[LoginButton::SAVE_NAMES_FIELD] ?? null) === null || !is_user_logged_in()) {
            return false;
        }

        $nonce = $_POST[LoginButton::NAMES_NONCE_FIELD] ?? '';
        $user = wp_get_current_user();
        if (
            !is_string($nonce)
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), LoginButton::NAMES_NONCE_ACTION)
            || !AccountService::may_log_in($user)
        ) {
            return false;
        }

        $first_name = self::posted_name(LoginButton::FIRST_NAME_FIELD);
        $last_name = self::posted_name(LoginButton::LAST_NAME_FIELD);
        if ($first_name === '' || $last_name === '') {
            self::add_notice('لطفا نام و نام خانوادگی را بنویسید.', 'error');

            return false;
        }

        $this->accounts->save_names($user, $first_name, $last_name);
        self::add_notice('نام شما ذخیره شد.', 'success');

        return true;
    }

    public function register_routes(TorobTokenValidator $validator): void
    {
        $this->controller->register_routes($validator);
    }

    private static function posted_name(string $field): string
    {
        $value = $_POST[$field] ?? '';

        return is_string($value) ? trim(sanitize_text_field(wp_unslash($value))) : '';
    }

    private static function add_notice(string $message, string $type): void
    {
        if (function_exists('wc_add_notice') && function_exists('WC') && WC()->session !== null) {
            wc_add_notice($message, $type);
        }
    }
}
