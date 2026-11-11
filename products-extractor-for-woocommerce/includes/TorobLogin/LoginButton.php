<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Torob\Utils\Options;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Torob login button in shops' login forms (WooCommerce's, wp-login.php, forms that run `login_form`
 * such as Digits', and the common SMS login forms that replace WooCommerce's), and the messages shown when a
 * Torob login comes back with an error.
 *
 * Like Google's sign-in button, it comes in a few fixed themes rather than free styling, chosen by the owner in
 * the Torob settings and, for one spot, in the shortcode or the Elementor widget. The text never changes.
 */
final class LoginButton
{
    public const SHORTCODE = 'torob_login';
    public const THEME_LIGHT = 'light';
    public const THEME_RED = 'red';
    /** The themes by key, with the names the settings page and the Elementor widget show. */
    public const THEMES = [self::THEME_LIGHT => 'روشن', self::THEME_RED => 'قرمز'];
    /** The name form in My Account's Torob section. */
    public const SAVE_NAMES_FIELD = 'torob_save_names';
    public const FIRST_NAME_FIELD = 'torob_first_name';
    public const LAST_NAME_FIELD = 'torob_last_name';
    public const NAMES_NONCE_FIELD = 'torob_names_nonce';
    public const NAMES_NONCE_ACTION = 'torob_login_save_names';
    private const BRAND_COLOR = '#E91E33';
    /** The light theme's border and text: those of Google's light button, so the two sit well together. */
    private const LIGHT_BORDER_COLOR = '#747775';
    private const LIGHT_TEXT_COLOR = '#1F1F1F';
    private const WP_LOGIN_CONTAINER_ID = 'torob-login-wp-login';
    private const REPLACED_FORM_TEMPLATE_ID = 'torob-login-replaced-form';
    /**
     * SMS login forms that take the place of WooCommerce's and run none of its hooks, by the element the button
     * goes right after, inside their box: Voorodak, otino, Faraz SMS, Woodmart Plus (its card and its other
     * layouts), and the Parskala, Dinakala and Bakala themes, the most common ones on shops using this plugin.
     */
    private const REPLACED_FORMS = [
        '#voorodak__wrapper-main-otp',
        '#otino-form-otp',
        '#submit-identifier',
        '#stm-sms-form-holder',
        '.dina-login-wrapper',
        '#lr-send-form>:last-child',
        'form.aramis_login',
        '.form-box>form.first_step'
    ];
    private const WP_LOGIN_MOVE_SCRIPT =
        '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'var b=document.getElementById("'
            . self::WP_LOGIN_CONTAINER_ID
            . '");'
            . 'var s=document.querySelector("#loginform .submit");'
            . 'if(b&&s){s.parentNode.insertBefore(b,s.nextSibling);}'
            . '});</script>';
    private const ERROR_MESSAGES = [
        Controller::ERROR_EXPIRED => 'زمان ورود با ترب تمام شد. لطفا دوباره تلاش کنید.',
        Controller::ERROR_CANCELLED => 'ورود با ترب لغو شد.',
        Controller::ERROR_UNAVAILABLE => 'ورود با ترب الان در دسترس نیست. لطفا با روش عادی وارد شوید.',
        Controller::ERROR_STAFF_ACCOUNT => 'حساب‌های مدیریتی نمی‌توانند با ترب وارد شوند. لطفا با روش عادی وارد شوید.',
        Controller::ERROR_FAILED => 'ورود با ترب انجام نشد. لطفا دوباره تلاش کنید یا با روش عادی وارد شوید.'
    ];

    /**
     * The shortcode and the Elementor widget, which the owner puts in their own pages.
     */
    public function register_placements(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_action('elementor/widgets/register', [$this, 'register_elementor_widget']);
    }

    /**
     * The places the button and its messages show by themselves; the button's own places only while the owner
     * keeps its automatic placement on.
     */
    public function register_hooks(): void
    {
        if (Options::isTorobLoginAutoPlacementEnabled()) {
            add_action('woocommerce_login_form_end', [$this, 'print_button']);
            add_action('login_form', [$this, 'print_button_in_login_form']);
            add_action('wp_footer', [$this, 'print_button_for_replaced_forms']);
        }

        add_action('woocommerce_before_customer_login_form', [$this, 'print_error_notice']);
        add_action('woocommerce_before_checkout_form', [$this, 'print_error_notice'], 5);
        add_filter('login_message', [$this, 'add_error_to_login_message']);
        add_action('woocommerce_account_dashboard', [$this, 'print_account_section']);
        add_action('woocommerce_edit_account_form_start', [$this, 'print_password_note']);
    }

    /**
     * On WooCommerce's account details form, for a buyer who signed up with Torob and has no password of their
     * own: say so, and hide the current password field they cannot fill (LoginHandler::allow_first_password()
     * lets the form save without it).
     */
    public function print_password_note(): void
    {
        if (!AccountService::has_no_own_password(wp_get_current_user())) {
            return;
        }

        echo
            '<p class="torob-login__password-note">'
                . esc_html(
                    'شما با «ورود با ترب» ثبت‌نام کرده‌اید و رمز جدایی ندارید. اگر می‌خواهید بدون ترب هم وارد شوید، در بخش تغییر رمز فقط رمز جدید را دو بار بنویسید.'
                )
                . '</p>'
                . '<style>.woocommerce-EditAccountForm p:has(#password_current),'
                . '.woocommerce-EditAccountForm label[for="password_current"],'
                . '.woocommerce-EditAccountForm #password_current{display:none}</style>'
        ;
    }

    /**
     * On the My Account dashboard of a buyer linked to Torob: say so, and ask for the names Torob did not have.
     */
    public function print_account_section(): void
    {
        $user = wp_get_current_user();
        if (
            !Settings::is_available()
            || $user->ID === 0
            || get_user_meta($user->ID, AccountService::USER_ID_META, true) === ''
        ) {
            return;
        }

        echo
            '<div class="torob-login torob-login--account" style="margin:16px 0;"><p>'
                . esc_html('حساب شما به ترب وصل است و می‌توانید با «ورود با ترب» وارد شوید.')
                . '</p>'
        ;
        if (AccountService::needs_names($user)) {
            self::print_name_form($user);
        }
        echo '</div>';
    }

    /**
     * Offer the Torob login button as a widget in Elementor, which builds most custom login pages.
     */
    public function register_elementor_widget(\Elementor\Widgets_Manager $widgets_manager): void
    {
        $widgets_manager->register(new ElementorWidget());
    }

    /**
     * Inside the WooCommerce login form, on My Account and on the classic checkout.
     */
    public function print_button(): void
    {
        // @mago-expect lint:no-unescaped-output render_button() escapes every value it adds to the markup.
        echo $this->render_button(self::current_url());
    }

    /**
     * `login_form` runs in wp-login.php and inside other plugins' login forms, such as Digits', at their end.
     *
     * Some themes also run it inside WooCommerce's form, which already gets the button at its end.
     */
    public function print_button_in_login_form(): void
    {
        if (did_action('woocommerce_login_form_start') > did_action('woocommerce_login_form_end')) {
            return;
        }

        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') {
            $this->print_button_on_wp_login();

            return;
        }

        // @mago-expect lint:no-unescaped-output render_button_with_message() escapes every value it adds.
        echo $this->render_button_with_message(self::current_url());
    }

    /**
     * Inside the wp-login.php form; the buyer goes where the form would have sent them.
     *
     * wp-login.php runs `login_form` above "Remember me" and the submit button, so a small script moves the
     * button under them. Without JavaScript it stays above them and still works.
     */
    private function print_button_on_wp_login(): void
    {
        $redirect_to = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
        $button = $this->render_button(is_string($redirect_to) ? wp_unslash($redirect_to) : '');
        if ($button === '') {
            return;
        }

        // @mago-expect lint:no-unescaped-output render_button() escapes every value it adds to the markup.
        echo '<div id="' . self::WP_LOGIN_CONTAINER_ID . '" style="clear:both;padding-top:8px;">' . $button . '</div>';
        // @mago-expect lint:no-unescaped-output A fixed script with no request data in it.
        echo self::WP_LOGIN_MOVE_SCRIPT;
    }

    /**
     * The REPLACED_FORMS offer no hook for other buttons, and many fill a page of their own outside the page
     * content (Voorodak's /auth/, otino's My Account), so the button waits in the footer and a small script puts
     * a copy after each of them. Only on inner pages, where these forms live; without JavaScript it stays hidden.
     */
    public function print_button_for_replaced_forms(): void
    {
        if (!is_page() || is_front_page() || !Settings::is_available()) {
            return;
        }

        // Themes that keep the top of WooCommerce's login template, like Parskala, already show the error notice.
        $button = did_action('woocommerce_before_customer_login_form')
            ? $this->render_button(self::replaced_form_return_address())
            : $this->render_button_with_message(self::replaced_form_return_address());
        if ($button === '') {
            return;
        }

        // @mago-expect lint:no-unescaped-output render_button() and render_button_with_message() escape every value.
        echo '<template id="' . self::REPLACED_FORM_TEMPLATE_ID . '">' . $button . '</template>';
        // @mago-expect lint:no-unescaped-output A fixed script with no request data in it.
        echo
            '<script>(function(){var t=document.getElementById("'
                . self::REPLACED_FORM_TEMPLATE_ID
                . '");var f=document.querySelectorAll("'
                . implode(',', self::REPLACED_FORMS)
                . '");for(var i=0;i<f.length;i++){var p=f[i].parentNode;'
                . 'if(!p.querySelector(".torob-login")){p.insertBefore(t.content.cloneNode(true),f[i].nextSibling);}'
                . '}})();</script>'
        ;
    }

    /**
     * `[torob_login]` for themes and page builders that replace the WooCommerce login form;
     * `[torob_login theme="red"]` sets the theme for that spot.
     *
     * @param mixed $atts
     */
    public function render_shortcode($atts = []): string
    {
        return $this->render_button_with_message(self::current_url(), is_array($atts) ? $atts : []);
    }

    public function print_error_notice(): void
    {
        $message = self::error_message();
        if ($message !== null) {
            wc_print_notice(esc_html($message), 'error');
        }
    }

    /**
     * @param mixed $message
     */
    public function add_error_to_login_message($message): string
    {
        $message = is_string($message) ? $message : '';
        $error = self::error_message();
        if ($error === null) {
            return $message;
        }

        return $message . '<div id="login_error" class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    }

    /**
     * The button, or nothing when Torob login is off or the visitor is already logged in.
     *
     * @param string $redirect_to Where to send the buyer after logging in; empty means My Account.
     * @param array<array-key, mixed> $choice This spot's `theme`, over the owner's choice.
     */
    public function render_button(string $redirect_to, array $choice = []): string
    {
        if (!Settings::is_available() || is_user_logged_in()) {
            return '';
        }

        return $this->button_markup($redirect_to, $choice);
    }

    /**
     * First and last name for a Torob account that lacks them (Torob usually has no name for the buyer), so
     * the shop can address them and prefill checkout. LoginHandler::save_names() saves it.
     */
    private static function print_name_form(\WP_User $user): void
    {
        $field = static fn(string $name, string $label, string $value): string => (
            '<p class="form-row" style="margin:0 0 8px;"><label for="'
            . esc_attr($name)
            . '">'
            . esc_html($label)
            . '</label><input type="text" class="input-text" id="'
            . esc_attr($name)
            . '" name="'
            . esc_attr($name)
            . '" value="'
            . esc_attr($value)
            . '" autocomplete="'
            . ($name === self::FIRST_NAME_FIELD ? 'given-name' : 'family-name')
            . '" required></p>'
        );

        // @mago-expect lint:no-unescaped-output Every value added to the markup is escaped.
        echo
            '<form method="post" class="torob-login__names" style="margin:12px 0 0;">'
                . '<p style="margin:0 0 8px;">'
                . esc_html('نام و نام خانوادگی خود را بنویسید تا فروشگاه شما را بشناسد و فرم خرید از قبل پر شود.')
                . '</p>'
                . $field(self::FIRST_NAME_FIELD, 'نام', (string) $user->first_name)
                . $field(self::LAST_NAME_FIELD, 'نام خانوادگی', (string) $user->last_name)
                . wp_nonce_field(self::NAMES_NONCE_ACTION, self::NAMES_NONCE_FIELD, true, false)
                . '<button type="submit" class="button" name="'
                . self::SAVE_NAMES_FIELD
                . '" value="1">'
                . esc_html('ذخیره')
                . '</button>'
                . '</form>'
        ;
    }

    /**
     * The button for the current page, as widgets and other placements without their own return address use.
     *
     * @param array<array-key, mixed> $choice
     */
    public function render_button_here(array $choice = []): string
    {
        return $this->render_button(self::current_url(), $choice);
    }

    /**
     * The button as a page-builder editor shows it to the logged-in editor, or a note while Torob login is
     * off for this shop. Buyers get render_button() instead.
     *
     * @param array<array-key, mixed> $choice
     */
    public function render_editor_preview(array $choice = []): string
    {
        if (!Settings::is_available()) {
            return (
                '<div class="torob-login torob-login--inactive" '
                . 'style="padding:12px;border:1px dashed #c3c4c7;border-radius:6px;text-align:center;">'
                . esc_html(
                    'دکمه‌ی «ورود با ترب» اینجا نشان داده می‌شود. ورود با ترب هنوز برای این فروشگاه روشن نشده است.'
                )
                . '</div>'
            );
        }

        return $this->button_markup(home_url('/'), $choice);
    }

    /**
     * The theme for one spot: its own choice when it names a known one, else the owner's, else light, which
     * sits beside the shop's own buttons without competing with them.
     *
     * @param array<array-key, mixed> $choice
     */
    public static function theme(array $choice = []): string
    {
        foreach ([$choice['theme'] ?? null, Options::getLoginButtonTheme()] as $theme) {
            if (is_string($theme) && array_key_exists($theme, self::THEMES)) {
                return $theme;
            }
        }

        return self::THEME_LIGHT;
    }

    /**
     * One theme as the settings page previews it: the button's face, without a link.
     */
    public static function sample(string $theme): string
    {
        $theme = self::theme(['theme' => $theme]);

        return (
            '<span class="torob-login__button torob-login__button--'
            . $theme
            . '" style="'
            . self::button_style($theme)
            . 'max-width:280px;">'
            . self::button_face($theme)
            . '</span>'
        );
    }

    /**
     * @param array<array-key, mixed> $choice
     */
    private function button_markup(string $redirect_to, array $choice): string
    {
        $theme = self::theme($choice);

        return (
            '<div class="torob-login" style="box-sizing:border-box;width:100%;margin:16px 0 0;text-align:center;">'
            . '<p class="torob-login__separator" style="margin:0 0 8px;opacity:.7;">یا</p>'
            . '<a class="torob-login__button torob-login__button--'
            . $theme
            . '" rel="nofollow" href="'
            . esc_url(Settings::start_url($redirect_to))
            . '" style="'
            . self::button_style($theme)
            . '">'
            . self::button_face($theme)
            . '</a>'
            . '</div>'
        );
    }

    private static function button_style(string $theme): string
    {
        $colors = $theme === self::THEME_RED
            ? 'background:' . self::BRAND_COLOR . ';color:#fff;border:1px solid ' . self::BRAND_COLOR . ';'
            : 'background:#fff;color:' . self::LIGHT_TEXT_COLOR . ';border:1px solid ' . self::LIGHT_BORDER_COLOR . ';';

        return (
            'display:flex;align-items:center;justify-content:center;gap:8px;box-sizing:border-box;width:100%;'
            . 'min-height:44px;padding:8px 16px;border-radius:6px;'
            . $colors
            . 'font-size:15px;line-height:1.4;font-weight:bold;text-decoration:none;'
        );
    }

    /**
     * The logo and the words: the login button's fixed text, or the label of another Torob button. The logo is
     * red, so on the red button it sits on a white disc.
     */
    private static function button_face(string $theme, string $words = 'ورود با ترب'): string
    {
        $on_disc = $theme === self::THEME_RED;
        $size = $on_disc ? '18' : '20';
        $logo =
            '<img src="'
            . esc_url(plugins_url('assets/torob.png', dirname(__FILE__, 2)))
            . '" alt="" width="'
            . $size
            . '" height="'
            . $size
            . '" style="width:'
            . $size
            . 'px;height:'
            . $size
            . 'px;margin:0;">';
        if ($on_disc) {
            $logo =
                '<span style="display:inline-flex;align-items:center;justify-content:center;flex:none;'
                . 'width:26px;height:26px;border-radius:50%;background:#fff;">'
                . $logo
                . '</span>';
        }

        return $logo . esc_html($words);
    }

    /**
     * The button with the message of a Torob login that came back with an error, for places that have no
     * WooCommerce notice or wp-login.php message of their own.
     *
     * @param array<array-key, mixed> $choice
     */
    private function render_button_with_message(string $redirect_to, array $choice = []): string
    {
        $button = $this->render_button($redirect_to, $choice);
        $message = self::error_message();
        if ($button === '' || $message === null) {
            return $button;
        }

        return '<p class="torob-login__error" role="alert">' . esc_html($message) . '</p>' . $button;
    }

    /**
     * The message for the `torob_login_error` code in this page's URL, if any.
     */
    private static function error_message(): ?string
    {
        $code = $_GET[Controller::ERROR_PARAM] ?? null;
        if (!is_string($code)) {
            return null;
        }

        return self::ERROR_MESSAGES[$code] ?? null;
    }

    /**
     * Where a replaced form's page would send the buyer: its `redirect_to`, or Voorodak's `backurl=checkout|cart`
     * from those pages. On the cart and checkout, where Bakala's login modal also is, the buyer comes back to the
     * same page, as from WooCommerce's form; anything else means My Account.
     */
    private static function replaced_form_return_address(): string
    {
        $redirect_to = $_GET['redirect_to'] ?? null;
        if (is_string($redirect_to) && $redirect_to !== '') {
            return wp_unslash($redirect_to);
        }

        switch ($_GET['backurl'] ?? null) {
            case 'checkout':
                return wc_get_checkout_url();
            case 'cart':
                return wc_get_cart_url();
            default:
                return is_cart() || is_checkout() ? self::current_url() : '';
        }
    }

    /**
     * This page's path, to come back to after logging in; the start route keeps it on this site.
     */
    private static function current_url(): string
    {
        return wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
    }
}
