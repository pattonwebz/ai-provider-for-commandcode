<?php

/**
 * Plugin Name:       AI Provider for Command Code
 * Plugin URI:        https://github.com/pattonwebz/ai-provider-for-commandcode
 * Description:       AI Provider for Command Code for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            William Patton
 * Author URI:        https://github.com/pattonwebz
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-commandcode
 *
 * @package WordPress\CommandCodeAiProvider
 */

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\CommandCodeAiProvider\Provider\CommandCodeProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for Command Code with the AI Client.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(CommandCodeProvider::class)) {
        return;
    }

    $registry->registerProvider(CommandCodeProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
