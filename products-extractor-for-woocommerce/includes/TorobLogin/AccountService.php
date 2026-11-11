<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Torob\Utils\PhoneNumber;
use WC_Customer;
use WP_Error;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Finds, links or creates the shop account for a Torob user and logs it in.
 *
 * Accounts are found by the Torob user id (`sub`) stored on them. A buyer account with the phone or email
 * Torob verified is linked and used; otherwise the buyer gets a new customer account. Accounts that can do more
 * than buy (shop managers, editors, admins) never log in with Torob and are never linked.
 */
final class AccountService
{
    public const USER_ID_META = 'torob_user_id';
    public const LINKED_AT_META = 'torob_linked_at';
    public const ALLOWED_ROLES = ['customer', 'subscriber'];
    /** Set on accounts a Torob login created, until the buyer sets a password of their own. */
    public const NO_OWN_PASSWORD_META = 'torob_no_own_password';
    /** The public name of an account Torob sent no name for, followed by the account's id. */
    private const DEFAULT_NAME = 'کاربر';

    /**
     * The shop account for this Torob user: the one linked to `sub`, else the buyer account with their verified
     * phone or email, now linked, else a new customer account.
     *
     * @param array<string, mixed> $claims Checked ID token claims.
     *
     * @return WP_User|WP_Error
     */
    public function find_or_create_user(array $claims)
    {
        $user = $this->find_linked_user($claims['sub']) ?? $this->link_matching_account($claims);
        $user ??= $this->create_customer($claims['sub'], $claims);
        if (is_wp_error($user)) {
            return $user;
        }

        if (!self::may_log_in($user)) {
            return new WP_Error('torob_login_staff_account', 'Only customer accounts can log in with Torob');
        }

        return $user;
    }

    /**
     * The buyer account here, not linked to Torob yet, that this Torob user's verified phone (as its username,
     * billing phone or Digits phone) or verified email belongs to, now linked to them; null when none is. Of
     * several, the first: one whose username is the phone, as SMS login plugins make them.
     *
     * @param array<string, mixed> $claims Checked ID token claims.
     */
    private function link_matching_account(array $claims): ?WP_User
    {
        $phone = $this->claim_phone($claims);
        $user_ids = $phone === null ? [] : $this->unlinked_buyer_ids($this->accounts_with_phone($phone));

        $email = $claims['email'] ?? null;
        if ($user_ids === [] && is_string($email) && ($claims['email_verified'] ?? null) === true && is_email($email)) {
            $user = get_user_by('email', $email);
            $user_ids = $user instanceof WP_User ? $this->unlinked_buyer_ids([$user]) : [];
        }

        if ($user_ids === []) {
            return null;
        }

        update_user_meta($user_ids[0], self::USER_ID_META, $claims['sub']);
        update_user_meta($user_ids[0], self::LINKED_AT_META, time());

        return new WP_User($user_ids[0]);
    }

    /**
     * Whether this account may log in with Torob: only accounts whose every role is a buyer role.
     */
    public static function may_log_in(WP_User $user): bool
    {
        if (is_super_admin($user->ID)) {
            return false;
        }

        return $user->roles !== [] && array_diff($user->roles, self::ALLOWED_ROLES) === [];
    }

    /**
     * Whether the buyer's account still lacks their first or last name.
     */
    public static function needs_names(WP_User $user): bool
    {
        return trim((string) $user->first_name) === '' || trim((string) $user->last_name) === '';
    }

    /**
     * Save the buyer's own first and last name: on the profile, as the public name in place of the default one, and as
     * the billing names WooCommerce prefills at checkout while those are empty.
     */
    public function save_names(WP_User $user, string $first_name, string $last_name): void
    {
        $update = ['ID' => $user->ID, 'first_name' => $first_name, 'last_name' => $last_name];
        $full_name = trim($first_name . ' ' . $last_name);
        if ($full_name !== '' && self::has_default_name($user->ID, $user->display_name)) {
            $update['display_name'] = $full_name;
            $update['nickname'] = $full_name;
        }
        wp_update_user($update);

        foreach (['billing_first_name' => $first_name, 'billing_last_name' => $last_name] as $key => $value) {
            if ($value === '' || get_user_meta($user->ID, $key, true) !== '') {
                continue;
            }

            update_user_meta($user->ID, $key, $value);
        }
    }

    /**
     * At checkout WooCommerce fills empty first and last names from the billing fields but keeps the default
     * public name; once the names are there, they replace it.
     *
     * @param mixed $customer
     */
    public function name_customer_after_checkout($customer): void
    {
        if (
            !$customer instanceof WC_Customer
            || !self::has_default_name($customer->get_id(), $customer->get_display_name())
        ) {
            return;
        }

        $full_name = trim($customer->get_first_name() . ' ' . $customer->get_last_name());
        if ($full_name !== '') {
            $customer->set_display_name($full_name);
            update_user_meta($customer->get_id(), 'nickname', $full_name);
        }
    }

    /**
     * WooCommerce's account details form requires an email, which most Torob accounts don't have; for those
     * the email is optional, so the buyer can still save their name there.
     *
     * @param mixed $required_fields
     *
     * @return mixed
     */
    public function email_optional_for_torob_accounts($required_fields)
    {
        $user = wp_get_current_user();
        if (
            is_array($required_fields)
            && $user->ID !== 0
            && $user->user_email === ''
            && get_user_meta($user->ID, self::USER_ID_META, true) !== ''
        ) {
            unset($required_fields['account_email']);
        }

        return $required_fields;
    }

    /**
     * Whether the buyer never set a password: the account came from a Torob login, with a random one.
     */
    public static function has_no_own_password(WP_User $user): bool
    {
        return $user->ID !== 0 && get_user_meta($user->ID, self::NO_OWN_PASSWORD_META, true) === '1';
    }

    public static function forget_no_own_password(int $user_id): void
    {
        delete_user_meta($user_id, self::NO_OWN_PASSWORD_META);
    }

    /**
     * Log the account in with a remembered session, the way the WordPress login form does.
     */
    public function log_in(WP_User $user): void
    {
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);
    }

    /**
     * The oldest account linked to the Torob user. Two first logins at the same moment can each create an
     * account; the first one stays the buyer's.
     */
    private function find_linked_user(string $sub): ?WP_User
    {
        $users = get_users([
            'meta_key' => self::USER_ID_META,
            'meta_value' => $sub,
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
            'count_total' => false
        ]);

        return $users[0] ?? null;
    }

    /**
     * Create a customer account linked to the Torob user. The phone number is the username when it is free;
     * it never becomes public.
     *
     * @param array<string, mixed> $claims
     *
     * @return WP_User|WP_Error
     */
    private function create_customer(string $sub, array $claims)
    {
        $phone = $this->claim_phone($claims);
        $given_name = $this->claim_string($claims, 'given_name');
        $family_name = $this->claim_string($claims, 'family_name');
        $name = $this->claim_string($claims, 'name');
        if ($name === '') {
            $name = trim($given_name . ' ' . $family_name);
        }
        $public_name = $name !== '' ? $name : self::DEFAULT_NAME;
        $email = $this->usable_email($claims);

        $user_id = wp_insert_user([
            'user_login' => $this->available_username($phone, 'torob_' . $sub),
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $email,
            // WordPress would build it from the username, and it shows in the CSS classes of the buyer's reviews.
            'user_nicename' => 'torob-' . bin2hex(random_bytes(6)),
            'display_name' => $public_name,
            'nickname' => $public_name,
            'first_name' => $given_name,
            'last_name' => $family_name,
            'role' => 'customer',
            'meta_input' => array_filter(
                self::digits_phone_meta($phone)
                + [
                    self::USER_ID_META => $sub,
                    self::LINKED_AT_META => time(),
                    self::NO_OWN_PASSWORD_META => '1',
                    'billing_phone' => $phone,
                    'billing_first_name' => $given_name,
                    'billing_last_name' => $family_name,
                    'billing_email' => $email
                ],
                static fn($value): bool => $value !== null && $value !== ''
            )
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        if ($name === '') {
            $public_name = self::default_display_name($user_id);
            wp_update_user(['ID' => $user_id, 'display_name' => $public_name, 'nickname' => $public_name]);
        }

        return new WP_User($user_id);
    }

    /**
     * The phone number as the username, else the Torob username.
     */
    private function available_username(?string $phone, string $torob_username): string
    {
        foreach ([$phone, $torob_username] as $candidate) {
            if ($candidate !== null && validate_username($candidate) && !username_exists($candidate)) {
                return $candidate;
            }
        }

        // Only when another account already took the Torob username by hand.
        do {
            $candidate = $torob_username . '_' . wp_rand(100_000, 999_999);
        } while (username_exists($candidate));

        return $candidate;
    }

    /**
     * The public name for an account Torob sent no name for: DEFAULT_NAME and the account's id on this shop,
     * never the phone number or the Torob user id, which is the same on every shop.
     */
    private static function default_display_name(int $user_id): string
    {
        return self::DEFAULT_NAME . ' ' . PhoneNumber::to_persian_digits((string) $user_id);
    }

    /**
     * Whether the account still has the public name it got for lacking one, which the buyer's real name may
     * replace.
     */
    private static function has_default_name(int $user_id, string $display_name): bool
    {
        return $display_name === '' || $display_name === self::default_display_name($user_id);
    }

    /**
     * Digits' phone fields for a new account, so the shop's own SMS login (Digits looks accounts up by
     * `digits_phone`) finds it too: `+98` and the number without its leading zero. Torob verified the number.
     * Left out when another account already has it, which would make Digits' login ambiguous.
     *
     * @return array<string, string>
     */
    private static function digits_phone_meta(?string $phone): array
    {
        if ($phone === null) {
            return [];
        }

        $national = substr($phone, 1);
        $digits_phone = '+98' . $national;
        $taken = get_users([
            'meta_key' => 'digits_phone',
            'meta_value' => $digits_phone,
            'number' => 1,
            'fields' => 'ID'
        ]);

        return (
            $taken !== []
                ? []
                : ['digits_phone' => $digits_phone, 'digt_countrycode' => '+98', 'digits_phone_no' => $national]
        );
    }

    /**
     * Accounts whose username, billing phone or Digits phone is this `09…` number however it was typed:
     * with spaces or dashes, `+98`/`0098`/no leading zero, Persian or Arabic digits.
     *
     * @return list<WP_User>
     */
    private function accounts_with_phone(string $mobile): array
    {
        global $wpdb;

        $forms = PhoneNumber::digit_forms($mobile);
        $in = implode(', ', array_fill(0, count($forms), '%s'));
        // The cheap last-digits filter goes first, so the long REPLACE chain runs on few rows.
        [$login_filter, $login_filter_values] = PhoneNumber::sql_last_digits_filter('user_login', $mobile);
        [$login_digits, $login_values] = PhoneNumber::sql_digits('user_login');
        [$meta_filter, $meta_filter_values] = PhoneNumber::sql_last_digits_filter('meta_value', $mobile);
        [$meta_digits, $meta_values] = PhoneNumber::sql_digits('meta_value');

        $user_ids = array_merge(
            $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE {$login_filter} AND {$login_digits} IN ({$in}) LIMIT 10",
                array_merge($login_filter_values, $login_values, $forms)
            )),
            $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('billing_phone', 'digits_phone')"
                . " AND {$meta_filter} AND {$meta_digits} IN ({$in}) LIMIT 10",
                array_merge($meta_filter_values, $meta_values, $forms)
            ))
        );

        $users = [];
        foreach (array_unique(array_map('intval', $user_ids)) as $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user instanceof WP_User) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * The ids of the buyer accounts among these that are not linked to a Torob user yet.
     *
     * @param list<WP_User> $users
     *
     * @return list<int>
     */
    private function unlinked_buyer_ids(array $users): array
    {
        $user_ids = [];
        foreach ($users as $user) {
            if (!self::may_log_in($user) || get_user_meta($user->ID, self::USER_ID_META, true) !== '') {
                continue;
            }

            $user_ids[] = $user->ID;
        }

        return $user_ids;
    }

    /**
     * The Torob email when Torob verified it and no other account here uses it; otherwise none.
     *
     * @param array<string, mixed> $claims
     */
    private function usable_email(array $claims): string
    {
        $email = $claims['email'] ?? null;
        if (
            !is_string($email)
            || ($claims['email_verified'] ?? null) !== true
            || !is_email($email)
            || email_exists($email) !== false
        ) {
            return '';
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function claim_phone(array $claims): ?string
    {
        $phone = $claims['phone_number'] ?? null;
        if (!is_string($phone) || ($claims['phone_number_verified'] ?? null) !== true) {
            return null;
        }

        return PhoneNumber::to_iranian_mobile($phone);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function claim_string(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
