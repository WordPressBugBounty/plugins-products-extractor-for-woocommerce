<?php

declare(strict_types=1);

namespace Torob\TorobLogin;

use Elementor\Controls_Manager;
use Elementor\Plugin;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Torob login button as an Elementor widget, for custom login pages and popups built with Elementor.
 *
 * Loaded only from Elementor's `elementor/widgets/register` hook, so Widget_Base exists. Buyers see the
 * button only while logged out; the editor always shows it so the owner can place it. The widget can set its
 * own theme, or follow the Torob settings.
 */
final class ElementorWidget extends Widget_Base
{
    private const FOLLOW_SETTINGS = ['' => 'مطابق تنظیمات ترب'];

    public function get_name(): string
    {
        return 'torob_login';
    }

    public function get_title(): string
    {
        return 'ورود با ترب';
    }

    public function get_icon(): string
    {
        return 'eicon-lock-user';
    }

    /**
     * @return list<string>
     */
    public function get_categories(): array
    {
        return ['general'];
    }

    /**
     * @return list<string>
     */
    public function get_keywords(): array
    {
        return ['torob', 'login', 'ترب', 'ورود'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('torob_login_button', ['label' => 'دکمه']);
        $this->add_control('theme', [
            'label' => 'ظاهر',
            'type' => Controls_Manager::SELECT,
            'default' => '',
            'options' => self::FOLLOW_SETTINGS + LoginButton::THEMES
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $choice = ['theme' => $this->get_settings_for_display('theme')];
        $button = new LoginButton();

        // @mago-expect lint:no-unescaped-output Both renderers escape every value they add to the markup.
        echo self::is_editing() ? $button->render_editor_preview($choice) : $button->render_button_here($choice);
    }

    /**
     * The button depends on who is looking and from where, so Elementor must not cache its output.
     */
    public function is_dynamic_content(): bool
    {
        return true;
    }

    private static function is_editing(): bool
    {
        return Plugin::$instance->editor->is_edit_mode() || Plugin::$instance->preview->is_preview_mode();
    }
}
