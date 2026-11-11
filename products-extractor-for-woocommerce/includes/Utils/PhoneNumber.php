<?php

declare(strict_types=1);

namespace Torob\Utils;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Iranian mobile number normalization.
 */
final class PhoneNumber
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    /** What people type between digits: spaces, dashes, dots, brackets, `+`, and invisible marks. */
    private const SEPARATORS = [' ', '-', '.', '(', ')', '+', '/', "\u{00A0}", "\u{200C}", "\u{200E}", "\u{200F}"];

    /**
     * Prevent instantiation of a static utility.
     */
    private function __construct() {}

    /**
     * Normalize an Iranian mobile number to its 11-digit `09…` form.
     *
     * @param string $phone Raw phone number (may include country code, leading zeros, Persian or Arabic digits,
     *                      or non-digit chars).
     *
     * @return string|null The `09…` number, or null if it is not a valid Iranian mobile.
     */
    public static function to_iranian_mobile(string $phone): ?string
    {
        $phone = preg_replace('/\D+/', '', self::to_latin_digits($phone)) ?? '';
        if (strpos($phone, '989') === 0) {
            $phone = substr($phone, 2);
        } elseif (strpos($phone, '00989') === 0) {
            $phone = substr($phone, 4);
        }
        if (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return null;
        }

        return $phone;
    }

    /**
     * The digits a `09…` number leaves once separators are dropped and digits made Latin: with its leading zero,
     * without it, and after the `98` or `0098` country code.
     *
     * @return list<string>
     */
    public static function digit_forms(string $mobile): array
    {
        $national = substr($mobile, 1);

        return [$mobile, $national, '98' . $national, '0098' . $national];
    }

    /**
     * SQL that reduces a column to digits the way to_iranian_mobile() reads it: separators dropped, Persian and
     * Arabic digits made Latin. The expression comes with its `%s` values, in order, for `$wpdb->prepare()`.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql_digits(string $column): array
    {
        $expression = $column;
        $values = [];
        foreach (self::SEPARATORS as $separator) {
            $expression = "REPLACE({$expression}, %s, '')";
            $values[] = $separator;
        }
        foreach ([self::PERSIAN_DIGITS, self::ARABIC_DIGITS] as $digits) {
            foreach ($digits as $value => $digit) {
                $expression = "REPLACE({$expression}, %s, '{$value}')";
                $values[] = $digit;
            }
        }

        return [$expression, $values];
    }

    /**
     * A cheap SQL condition that lets through only values ending in the number's last two digits, in Latin,
     * Persian or Arabic digits and with anything between them, so sql_digits() runs on few rows. The condition
     * comes with its `%s` values, in order, for `$wpdb->prepare()`.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql_last_digits_filter(string $column, string $mobile): array
    {
        $last_digits = array_map('intval', str_split(substr($mobile, -2)));
        $values = [];
        foreach ([range(0, 9), self::PERSIAN_DIGITS, self::ARABIC_DIGITS] as $digits) {
            $values[] =
                '%' . implode('%', array_map(static fn(int $digit): string => (string) $digits[$digit], $last_digits));
        }

        return ["({$column} LIKE %s OR {$column} LIKE %s OR {$column} LIKE %s)", $values];
    }

    public static function to_latin_digits(string $text): string
    {
        return str_replace(
            array_merge(self::PERSIAN_DIGITS, self::ARABIC_DIGITS),
            array_merge(range(0, 9), range(0, 9)),
            $text
        );
    }

    public static function to_persian_digits(string $text): string
    {
        return str_replace(range(0, 9), self::PERSIAN_DIGITS, $text);
    }
}
