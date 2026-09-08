<?php

/**
 * PSR-4 autoloader for the AI Provider for Command Code package.
 *
 * @since 0.1.0
 *
 * @package WordPress\CommandCodeAiProvider
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WordPress\\CommandCodeAiProvider\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
