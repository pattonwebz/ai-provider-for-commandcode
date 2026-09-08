# AI Provider for Command Code

AI Provider for Command Code for the [WordPress AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) (WP 7.0+). Works as both a WordPress plugin and a Composer package.

Registers **Command Code** as a first-class AI provider in the WordPress 7.0 Connectors system (Settings → Connectors). No plugin code touches API keys — WordPress derives everything from the provider ID:

| What | Value |
| --- | --- |
| Provider ID | `commandcode` |
| Connector setting | `connectors_ai_commandcode_api_key` (Settings → Connectors) |
| PHP constant / env var | `COMMANDCODE_API_KEY` |
| API | `https://api.commandcode.ai/provider/v1` (OpenAI-compatible Chat Completions) |
| Keys | Create one in [Command Code Studio](https://commandcode.ai/studio) |

## Requirements

- WordPress 7.0+ (AI Client and Connectors API are in core since 7.0)
- PHP 7.4+
- A Command Code plan with API access (Provider, Pro, Max, Team, or GOAT)

## Installation

As a WordPress plugin: drop the folder into `wp-content/plugins/` and activate. The plugin registers with the AI Client at `init`, and core auto-creates the "Command Code" connector card.

As a Composer package:

```bash
composer require pattonwebz/ai-provider-for-commandcode
```

(The SDK classes are loaded by WordPress core on 7.0+; nothing else to install.)

## Configuration

Set the key via environment variable or PHP constant (recommended for production):

```php
define( 'COMMANDCODE_API_KEY', 'cmd-...' ); // wp-config.php
```

…or enter it on **Settings → Connectors**, where it is stored in the database
(masked in the UI; core stores keys unencrypted — see [core trac #64789](https://core.trac.wordpress.org/ticket/64789)).

Resolution priority: environment variable → PHP constant → database.

## Usage

Any plugin on the site can now generate text via the standard API:

```php
$text = wp_ai_client_prompt( 'Summarize the benefits of caching in WordPress.' )
    ->generate_text();

if ( is_wp_error( $text ) ) {
    // Handle error.
}
```

### Picking a model per request

```php
// Preference list: first available model wins; falls back to any compatible model.
$text = wp_ai_client_prompt( 'Refactor this PHP snippet.' )
    ->using_model_preference( 'deepseek/deepseek-v4-flash', 'gpt-5.5' )
    ->using_temperature( 0.2 )
    ->generate_text();
```

### Vision: describing images, not generating them

"Vision" here means a model that **takes an image as input and returns a text
description or analysis** (e.g. alt text). Command Code's API has no
image-generation models, and this provider never advertises image generation.

The default vision model is `MiniMaxAI/MiniMax-M3` (verified vision-capable on
the Command Code chat wire). Image-description requests resolve to it
automatically, since text-only models are filtered out of vision requests:

```php
$alt = wp_ai_client_prompt()
    ->with_text( 'Write concise alt text for this image.' )
    ->with_file( $image_file, 'image/png' )
    ->generate_text();
```

To prefer specific vision models, list them explicitly — vision requests only
consider models verified to accept image input:

```php
$alt = wp_ai_client_prompt()
    ->with_text( 'Write concise alt text for this image.' )
    ->with_file( $image_file, 'image/png' )
    ->using_model_preference(
        'MiniMaxAI/MiniMax-M3',
        'google/gemini-3.8-flash',
        'gpt-5.4'
    )
    ->generate_text();
```

## Model catalog & classification

Model metadata is fetched **live** from `GET /provider/v1/models` and
classified in code (`src/Metadata/CommandCodeModelMetadataDirectory.php`).
Every classification rule was verified against the live API on 2026-09-08:

- **Anthropic wire (`claude-*`) is excluded** — those models require Command
  Code's `/provider/v1/messages` endpoint, which v1 of this provider does not
  implement (planned).
- **Text generation + chat history** are advertised for every chat-wire model.
- **`temperature` / `top_p`**: accepted by every family tested (DeepSeek, Qwen,
  Gemini, Kimi, GLM, MiniMax, Grok, GPT-5.x, and more).
- **`presence_penalty` / `frequency_penalty`**: advertised everywhere except the
  Google Gemini family (the only tested family that rejects them).
- **Vision (image input)** is whitelisted per model — currently:
  `deepseek/deepseek-v4-flash-vision-exp`, Google Gemini 3.x, `Qwen/Qwen3.8-27B`,
  GPT-5.x chat models, Moonshot Kimi K2.5–K3, `MiniMaxAI/MiniMax-M3`,
  Thinking Machines Inkling, `z-ai/glm-5.3-flash`.
- New or unknown models default to conservative text-only metadata until
  verified, so the connector keeps working as Command Code's catalog churns.

## Zero data retention

Send `x-cmd-zdr: 1` on every request by attaching request options:

```php
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

$text = wp_ai_client_prompt( '…' )
    ->using_request_options( new RequestOptions( headers: [ 'x-cmd-zdr' => '1' ] ) )
    ->generate_text();
```

(Constructor signature may vary by SDK version — see the PHP AI Client docs.)

## Development

```bash
composer install
composer lint    # phpcs + phpstan
composer test    # phpunit
```

The fixture `tests/fixtures/models-response.json` is a live capture of
`GET /provider/v1/models` (67 models, 2026-09-08); refresh it to re-validate the
classifier against the current catalog.

## License

GPL-2.0-or-later. Not affiliated with or endorsed by Command Code. The Command
Code logo is a trademark of its owner and is used here to identify the service.
