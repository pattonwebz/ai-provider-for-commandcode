<?php

/**
 * WordPress filter test doubles for running the unit tests outside WordPress.
 *
 * This file only declares symbols (a class and, when WordPress is not loaded,
 * global filter functions that proxy to it). It causes no side effects, so it
 * can be required from the PHPUnit bootstrap without tripping file-level
 * coding standard rules.
 *
 * @since 0.1.1
 *
 * @package WordPress\CommandCodeAiProvider
 */

declare(strict_types=1);

/**
 * Minimal in-memory implementation of the parts of the WordPress filter API
 * used by the plugin, for unit tests running outside of WordPress.
 *
 * @since 0.1.1
 */
final class WpFilterTestDouble
{
    /**
     * Registered callbacks, keyed by tag.
     *
     * @var array<string, list<callable>>
     */
    private static $filters = array();

    /**
     * Registers a filter callback.
     *
     * @since 0.1.1
     *
     * @param string   $tag      Filter name.
     * @param callable $callback Callback to register.
     * @return void
     */
    public static function addFilter(string $tag, callable $callback): void
    {
        self::$filters[$tag][] = $callback;
    }

    /**
     * Removes all callbacks for a filter.
     *
     * @since 0.1.1
     *
     * @param string $tag Filter name.
     * @return void
     */
    public static function removeAllFilters(string $tag): void
    {
        unset(self::$filters[$tag]);
    }

    /**
     * Applies callbacks registered for a filter.
     *
     * @since 0.1.1
     *
     * @param string $tag   Filter name.
     * @param mixed  $value Value to filter.
     * @return mixed Filtered value.
     */
    public static function applyFilters(string $tag, $value)
    {
        if (!isset(self::$filters[$tag])) {
            return $value;
        }
        foreach (self::$filters[$tag] as $callback) {
            $value = $callback($value);
        }
        return $value;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Registers a filter callback.
     *
     * @param string   $tag      Filter name.
     * @param callable $callback Callback to register.
     * @return void
     */
    function add_filter(string $tag, callable $callback): void
    {
        WpFilterTestDouble::addFilter($tag, $callback);
    }

    /**
     * Removes all callbacks for a filter.
     *
     * @param string $tag Filter name.
     * @return void
     */
    function remove_all_filters(string $tag): void
    {
        WpFilterTestDouble::removeAllFilters($tag);
    }

    /**
     * Applies callbacks registered for a filter.
     *
     * @param string $tag   Filter name.
     * @param mixed  $value Value to filter.
     * @return mixed Filtered value.
     */
    function apply_filters(string $tag, $value)
    {
        return WpFilterTestDouble::applyFilters($tag, $value);
    }
}
