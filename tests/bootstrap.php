<?php

/**
 * PHPUnit bootstrap file for the AI Provider for Command Code package.
 *
 * @since 0.1.0
 *
 * @package WordPress\CommandCodeAiProvider
 */

declare(strict_types=1);

/*
 * Load the PHP AI Client SDK. Prefer the Composer autoloader; alternatively, a standalone
 * autoloader for the SDK may be provided via the PHP_AI_CLIENT_AUTOLOAD environment variable
 * (useful in environments where Composer dependencies cannot be installed).
 */
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$sdkAutoload = getenv('PHP_AI_CLIENT_AUTOLOAD');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} elseif (is_string($sdkAutoload) && $sdkAutoload !== '' && file_exists($sdkAutoload)) {
    require_once $sdkAutoload;
}

// Load this package's classes.
require_once dirname(__DIR__) . '/src/autoload.php';

/*
 * Minimal WordPress filter test doubles, only for running the unit tests
 * outside of WordPress. They emulate the parts of the filter API that the
 * plugin's code path uses; production code never sees them.
 */
if (!function_exists('apply_filters')) {
    $GLOBALS['wp_test_filter_registry'] = array();

    /**
     * Registers a filter callback.
     *
     * @param string   $tag      Filter name.
     * @param callable $callback Callback.
     */
    function add_filter(string $tag, callable $callback): void
    {
        $GLOBALS['wp_test_filter_registry'][$tag][] = $callback;
    }

    /**
     * Removes all callbacks for a filter.
     *
     * @param string $tag Filter name.
     */
    function remove_all_filters(string $tag): void
    {
        unset($GLOBALS['wp_test_filter_registry'][$tag]);
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
        if (!isset($GLOBALS['wp_test_filter_registry'][$tag])) {
            return $value;
        }
        foreach ($GLOBALS['wp_test_filter_registry'][$tag] as $callback) {
            $value = $callback($value);
        }
        return $value;
    }
}
